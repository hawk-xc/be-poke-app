<?php

namespace App\Console\Commands;

use Carbon\Carbon;
use Exception;
use GuzzleHttp\Client;
use Illuminate\Console\Command;
use App\Models\VisitorDetection;
use App\Models\VisitorDetectionFails;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class SendGateOutFailsData extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'visitor:resend-failed-gate-out';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Resend failed gate-out visitor detections to Face++ for records with less than 3 retries.';

    protected string $apikey;
    protected string $apisecret;
    protected string $faceset_token;
    protected string $faceplus_delete_face_token;
    protected string $faceplus_search_url;
    protected int $matchedDataCounter;
    protected int $toleranceMaxStayMin = 40; // minutes
    protected int $acuracy = 85; // percentage
    protected int $sleepTime = 1;
    protected int $retryCount = 3;
    protected int $successfullyResent;
    protected int $failedAgain;

    public function __construct()
    {
        parent::__construct();

        $baseUrl = rtrim(env('FACEPLUSPLUS_URL'), '/');

        $this->faceplus_search_url          = $baseUrl . '/search';
        $this->faceplus_delete_face_token   = $baseUrl . '/faceset/removeface';
        $this->apikey                       = env('FACEPLUSPLUS_API_KEY');
        $this->apisecret                    = env('FACEPLUSPLUS_SECRET_KEY');
        $this->faceset_token                = env('FACEPLUSPLUS_FACESET_TOKEN');
        $this->matchedDataCounter           = 0;
        $this->successfullyResent           = 0;
        $this->failedAgain                  = 0;
    }

    /**
     * Execute the console command.
     */
    public function handle()
    {
        date_default_timezone_set('Asia/Jakarta');
        $this->info('=== [ResendFailedGateOutData] Command Started ===');

        $failed_detections = VisitorDetectionFails::where('try_count', '<', $this->retryCount)->get();

        $this->info("Found {$failed_detections->count()} failed records to re-process.");

        foreach ($failed_detections as $failed_detection) {
            // Avoid throttle
            sleep($this->sleepTime);

            // Increment try_count immediately to prevent infinite loops on crash
            $failed_detection->increment('try_count');

            $detection = VisitorDetection::where('rec_no', $failed_detection->rec_no)->first();

            if (!$detection) {
                $this->error("Original detection with rec_no: {$failed_detection->rec_no} not found. Deleting fail record.");
                $failed_detection->delete();
                continue;
            }

            $this->info("Processing failed detection for rec_no: {$detection->rec_no} (Attempt: {$failed_detection->try_count})");

            try {
                $imageUrl = $detection->person_pic_url;

                // ======================================================
                // URL FILE Validation
                // ======================================================
                $filePath = normalizeFaceImagePath($imageUrl);
                $storagePath  = $filePath['storage'];
                $absolutePath = $filePath['absolute'];

                if (!Storage::exists($storagePath)) {
                    $this->info("Detection {$detection->id}: FILE NOT FOUND {$storagePath}");
                    $this->failedAgain++;
                    continue;
                }

                // ======================================================
                // 1. REQUEST SEARCH (FACE++ SEARCH API)
                // ======================================================
                $detect_response = curlMultipart(
                    $this->faceplus_search_url,
                    [
                        'api_key'           => $this->apikey,
                        'api_secret'        => $this->apisecret,
                        'faceset_token'     => $this->faceset_token,
                        'image_file'        => new \CURLFile(
                            $absolutePath,
                            mime_content_type($absolutePath),
                            basename($absolutePath)
                        ),
                    ]
                );

                $status = $detect_response['status'];
                $body   = $detect_response['body'];

                $this->info($body);

                if ($status !== 200) {
                    $this->error("Detection {$detection->id} - Resend failed: HTTP {$status}");
                    $this->failedAgain++;
                    continue; // Keep the record in fails table with incremented try_count
                }

                $search_data = json_decode($body, true);
                $detection->face_token = $search_data['faces'][0]['face_token'] ?? null;

                if (!isset($search_data['results']) || empty($search_data['results'])) {
                    $this->info("Detection {$detection->id} - No match found on resend.");
                    $detection->is_matched = false;
                    $detection->is_registered = true;
                    $detection->save();
                    
                    // If successfully processed (even if no match), remove from fails table
                    $failed_detection->delete();
                    $this->successfullyResent++;
                    continue;
                }

                $best_match = $search_data['results'][0];
                $threshold  = $search_data['thresholds']['1e-5'] ?? 75;
                $thresholdValue = max($this->acuracy, $threshold);

                if ($best_match['confidence'] < $thresholdValue) {
                    $detection->save();
                    $failed_detection->delete(); // Processed, so remove from fails
                    $this->successfullyResent++;
                    continue;
                }

                // ======================================================
                // MATCH VALID
                // ======================================================
                $visitor_in = VisitorDetection::select(['id', 'rec_no', 'locale_time', 'face_token'])
                    ->where('label', 'in')
                    ->where('face_token', $best_match['face_token'])
                    ->where('locale_time', '<', Carbon::parse($detection->locale_time)->subMinutes($this->toleranceMaxStayMin))
                    ->first();

                if (!$visitor_in) {
                    $this->info("No matching 'IN' record found on resend.");
                    $detection->save();
                    $failed_detection->delete(); // Processed, so remove from fails
                    $this->successfullyResent++;
                    continue;
                }

                $minutes = Carbon::parse($visitor_in->locale_time)
                    ->diffInMinutes(Carbon::parse($detection->locale_time));

                $detection->is_matched      = true;
                $detection->embedding_id    = $search_data['image_id'] ?? null;
                $detection->rec_no_in       = $visitor_in->rec_no;
                $detection->duration        = $minutes;
                $detection->similarity      = $best_match['confidence'];
                $detection->status          = true;
                $detection->is_registered   = true;

                $facetokens = $search_data['faces'][0]['face_token'] . ',' . $visitor_in->face_token;

                $this->info("Detection {$detection->id} MATCHED on resend with confidence {$best_match['confidence']}");
                $this->matchedDataCounter++;

                curlMultipart(
                    $this->faceplus_delete_face_token,
                    [
                        'api_key'           => $this->apikey,
                        'api_secret'        => $this->apisecret,
                        'faceset_token'     => $this->faceset_token,
                        'face_tokens'       => $facetokens
                    ]
                );

                $detection->save();

                // On full success, delete from fails table
                $failed_detection->delete();
                $this->successfullyResent++;
                $this->info("Successfully re-processed and removed failure record for rec_no: {$detection->rec_no}");

            } catch (Exception $err) {
                $this->failedAgain++;
                sendTelegram('🔴 [ResendFailedGateOut] Resend Errno : ' . $err->getMessage());
                $this->error("Error on resending detection {$detection->id}: " . $err->getMessage());
            }
        }

        $summary = "
🔵 <b>[ResendFailedGateOut] Summary Report</b>

<b>Total Failed Records Processed:</b> {$failed_detections->count()}

<b>📊 Reprocessing Result:</b>
• ✅ Successfully Resent : <b>{$this->successfullyResent}</b>
• 🔗 Matched on Resend   : <b>{$this->matchedDataCounter}</b>
• ❌ Failed Again        : <b>{$this->failedAgain}</b>

<b>⚙️ Notes:</b>
• Records that reached the retry limit ({$this->retryCount}) were automatically deleted.
• On success, records are removed from the failure log.
• On failure, `try_count` is incremented.
            ";

        $this->info('=== [ResendFailedGateOutData] Finished ===');
        sendTelegram($summary);

        return 0;
    }
}
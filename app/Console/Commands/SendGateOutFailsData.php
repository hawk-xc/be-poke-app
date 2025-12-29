<?php

namespace App\Console\Commands;

use Carbon\Carbon;
use Exception;
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
    protected $description = 'Resend failed gate-out visitor detections to Custom ML API for records with less than 3 retries.';

    protected string $api_exit_url;
    protected int $matchedDataCounter;
    protected int $toleranceMaxStayMin = 40; // minutes
    protected int $accuracy = 60; // percentage
    protected int $sleepTime = 1;
    protected int $retryCount = 3;
    protected int $successfullyResent;
    protected int $failedAgain;

    public function __construct()
    {
        parent::__construct();

        $baseUrl = rtrim(env('INSIGHTFACE_API_URL', 'https://insightface.deraly.id'), '/');
        $this->api_exit_url = $baseUrl . '/exit';
        $this->matchedDataCounter = 0;
        $this->successfullyResent = 0;
        $this->failedAgain = 0;
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
                $paths = normalizeFaceImagePath($imageUrl);
                $storagePath  = $paths['storage'];

                // ============================
                // FILE CHECK BLOCK
                // ============================
                if (!Storage::disk('minio')->exists($storagePath)) {
                    $this->info("Detection {$detection->id}: FILE NOT FOUND {$storagePath}");
                    continue;
                }

                // ============================
                // SEND TO CUSTOM ML API BLOCK
                // ============================
                $tempFile = tempnam(sys_get_temp_dir(), 'face_');

                file_put_contents(
                    $tempFile,
                    Storage::disk('minio')->get($storagePath)
                );

                // ======================================================
                // 1. REQUEST EXIT (CUSTOM ML EXIT API)
                // ======================================================
                $exit_response = curlMultipart(
                    $this->api_exit_url . '?similarity_method=compreface',
                    [
                        'image' => new \CURLFile(
                            $tempFile,
                            mime_content_type($tempFile),
                            basename($tempFile)
                        ),
                    ]
                );

                $status = $exit_response['status'];
                $body   = $exit_response['body'];

                $this->info($body);

                if ($status !== 200) {
                    $this->error("Detection {$detection->id} - Resend failed: HTTP {$status}");
                    $this->failedAgain++;
                    continue; // Keep the record in fails table with incremented try_count
                }

                $exit_data = json_decode($body, true);

                // ======================================================
                // Check Response Success
                // ======================================================
                if (empty($exit_data['success']) || !$exit_data['success']) {
                    $this->info("Detection {$detection->id} - Exit API failed on resend");
                    $detection->is_matched = false;
                    $detection->is_registered = true;
                    $detection->save();
                    
                    // If successfully processed (even if no match), remove from fails table
                    $failed_detection->delete();
                    $this->successfullyResent++;
                    continue;
                }

                // ======================================================
                // When No Match Found (person_entry_id is null)
                // ======================================================
                if (empty($exit_data['person_entry_id'])) {
                    $this->info("Detection {$detection->id} - No match found on resend.");
                    $detection->is_matched = false;
                    $detection->is_registered = true;
                    $detection->save();
                    
                    // Successfully processed (no match), remove from fails table
                    $failed_detection->delete();
                    $this->successfullyResent++;
                    continue;
                }

                // ======================================================
                // Check Confidence Threshold
                // ======================================================
                $confidence = ($exit_data['confidence'] ?? 0) * 100; // Convert to percentage

                if ($confidence < $this->accuracy) {
                    $this->info("Detection {$detection->id} - Confidence too low on resend: {$confidence}%");
                    $detection->is_matched = false;
                    $detection->is_registered = true;
                    $detection->save();
                    
                    // Successfully processed (low confidence), remove from fails table
                    $failed_detection->delete();
                    $this->successfullyResent++;
                    continue;
                }

                // ======================================================
                // Find Matching IN Record
                // ======================================================
                $matched_entry_id = $exit_data['person_entry_id'];
                
                $visitor_in = VisitorDetection::select(['id', 'rec_no', 'locale_time', 'person_uid'])
                    ->where('label', 'in')
                    ->where('person_uid', $matched_entry_id)
                    ->where('locale_time', '<', Carbon::parse($detection->locale_time)->subMinutes($this->toleranceMaxStayMin))
                    ->first();

                if (!$visitor_in) {
                    $this->info("Detection {$detection->id} - No matching 'IN' record found on resend for entry: {$matched_entry_id}");
                    $detection->is_matched = false;
                    $detection->is_registered = true;
                    $detection->save();
                    
                    // Successfully processed, remove from fails table
                    $failed_detection->delete();
                    $this->successfullyResent++;
                    continue;
                }

                // ======================================================
                // MATCH VALID - Calculate Duration
                // ======================================================
                $minutes = $exit_data['duration_minutes'] ?? Carbon::parse($visitor_in->locale_time)
                    ->diffInMinutes(Carbon::parse($detection->locale_time));

                // ======================================================
                // Save Matching Results
                // ======================================================
                $detection->is_matched          = true;
                $detection->rec_no_in           = $visitor_in->rec_no;
                $detection->duration            = $minutes;
                $detection->similarity          = $confidence;
                $detection->status              = true;
                $detection->is_registered       = true;
                
                // Mapping from exit response
                $detection->person_uid          = $exit_data['person_exit_id'] ?? null;
                $detection->face_sex            = $exit_data['gender'] ?? null;
                $detection->face_age            = $exit_data['age'] ?? null;

                $detection->save();

                $this->info("Detection {$detection->id} MATCHED on resend with IN record {$visitor_in->rec_no} (Confidence: {$confidence}%, Duration: {$minutes} min)");
                $this->matchedDataCounter++;

                // On full success, delete from fails table
                $failed_detection->delete();
                $this->successfullyResent++;
                $this->info("Successfully re-processed and removed failure record for rec_no: {$detection->rec_no}");

            } catch (Exception $err) {
                $this->failedAgain++;
                Log::error("ResendFailedGateOut Error on Detection {$detection->id}: " . $err->getMessage());
                sendTelegram('🔴 [ResendFailedGateOut] Resend Error: ' . $err->getMessage());
                $this->error("Error on resending detection {$detection->id}: " . $err->getMessage());
            }
        }

        // ============================
        // TELEGRAM SUMMARY REPORT
        // ============================
        $summary = "
🔵 <b>[ResendFailedGateOut] Summary Report</b>

<b>Total Failed Records Processed:</b> {$failed_detections->count()}

<b>📊 Reprocessing Result:</b>
• ✅ Successfully Resent : <b>{$this->successfullyResent}</b>
• 🔗 Matched on Resend   : <b>{$this->matchedDataCounter}</b>
• ❌ Failed Again        : <b>{$this->failedAgain}</b>

<b>⚙️ Custom ML Parameters:</b>
• Confidence Threshold : <b>{$this->accuracy}%</b>
• Max Retry Count      : <b>{$this->retryCount}</b>
• API Endpoint         : <code>{$this->api_exit_url}</code>

<b>📘 Notes:</b>
• Records that reached the retry limit ({$this->retryCount}) were skipped.
• On success, records are removed from the failure log.
• On failure, `try_count` is incremented.
• Successfully processed records (even without match) are removed from fails table.
        ";

        $this->info('=== [ResendFailedGateOutData] Finished ===');
        sendTelegram($summary);

        return 0;
    }
}
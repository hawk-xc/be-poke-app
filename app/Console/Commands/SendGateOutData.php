<?php

namespace App\Console\Commands;

use Carbon\Carbon;
use Exception;
use Illuminate\Console\Command;
use App\Models\VisitorDetection;
use App\Models\VisitorDetectionFails;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class SendGateOutData extends Command
{
    protected $signature = 'visitor:send-gate-out {start?} {end?}';
    protected $description = 'Process outgoing visitor detections and match faces using Custom ML API';

    protected string $api_exit_url;
    protected int $matchedDataCounter;
    protected int $notMatchedCounter;
    protected int $toleranceMaxStayMin = 40; // minutes
    protected int $getOutAttendingDataMin = 30; // minutes
    protected int $expirateOutDataHour = 12; // hour
    protected int $accuracy = 80; // percentage (confidence threshold)
    protected int $sleepTime = 0;

    public function __construct()
    {
        parent::__construct();

        $baseUrl = rtrim(env('INSIGHTFACE_API_URL', 'https://insightface.deraly.id'), '/');
        $this->api_exit_url = $baseUrl . '/exit';
        $this->matchedDataCounter = 0;
        $this->notMatchedCounter = 0;
    }

    public function handle()
    {
        date_default_timezone_set('Asia/Jakarta');

        // CRON Running
        $start_time = $this->argument('start') ?? Carbon::now()->subMinutes($this->toleranceMaxStayMin);
        $end_time   = $this->argument('end') ?? Carbon::now()->subMinutes(5);

        $this->info('=== [SendGateOutData] Command Started ===');

        $visitor_detections = VisitorDetection::where('label', 'out')
            ->where('is_matched', false)
            ->whereNull('rec_no_in')
            ->whereNotNull('person_pic_url')
            ->whereBetween('locale_time', [$start_time, $end_time])
            ->latest()
            ->get();

        $this->info("Found {$visitor_detections->count()} unprocessed gate-out detections.");

        foreach ($visitor_detections as $detection) {
            // Avoid throttle
            sleep($this->sleepTime);

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

                $this->info(print_r($exit_response));

                $status = $exit_response['status'];
                $this->info($status);
                $body   = $exit_response['body'];

                // debug
                $this->info($body);

                $this->info('error block state');
                // Error state block
                if ($status !== 200) {
                    $this->info("Detection {$detection->id} - Exit API: HTTP {$status} with data {$body}");
                    $existingFail = VisitorDetectionFails::where('rec_no', $detection->rec_no)->first();

                    if (!$existingFail) {
                        $failsDetection = new VisitorDetectionFails();
                        $failsDetection->rec_no = $detection->rec_no;
                        $failsDetection->save();
                    }

                    continue;
                }

                $this->info('decode state');
                $exit_data = json_decode($body, true);

                // ======================================================
                // Check Response Success
                // ======================================================
                if (empty($exit_data['success']) || !$exit_data['success']) {
                    $this->info("Detection {$detection->id} - Exit API failed");
                    $detection->is_matched = false;
                    $detection->is_registered = true;
                    $detection->save();
                    $this->notMatchedCounter++;
                    continue;
                }

                $this->info('person entry state');

                // ======================================================
                // When No Match Found (person_entry_id is null)
                // ======================================================
                if (empty($exit_data['person_entry_id'])) {
                    $this->info("Detection {$detection->id} - No matching entry found");
                    $detection->is_matched = false;
                    $detection->is_registered = true;
                    $detection->save();
                    $this->notMatchedCounter++;
                    continue;
                }

                $this->info('entry state');

                // ======================================================
                // Check Confidence Threshold
                // ======================================================
                $confidence = (int) ceil((($exit_data['confidence'] * 100))) ?? 0; // Convert to percentage

                $this->info($confidence);

                if ($confidence < $this->accuracy) {
                    $this->info("Detection {$detection->id} - Confidence too low: {$confidence}%");
                    $detection->is_matched = false;
                    $detection->is_registered = true;
                    $detection->save();
                    $this->notMatchedCounter++;
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

                $this->info($visitor_in);

                if (!$visitor_in) {
                    $this->info("Detection {$detection->id} - No matching 'IN' record found for entry: {$matched_entry_id}");
                    $detection->is_matched = false;
                    $detection->is_registered = true;
                    $detection->save();
                    $this->notMatchedCounter++;
                    continue;
                }

                // ======================================================
                // MATCH VALID - Calculate Duration from API or manually
                // ======================================================
                // Use duration from API if available, otherwise calculate manually
                // $minutes = $exit_data['duration_minutes'] ?? Carbon::parse($visitor_in->locale_time)
                //     ->diffInMinutes(Carbon::parse($detection->locale_time));
                $minutes = Carbon::parse($visitor_in->locale_time)
                    ->diffInMinutes(Carbon::parse($detection->locale_time));

                // ======================================================
                // Save Matching Results with Response Mapping
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
                // $detection->emotion             = $exit_data['exit_expression'] ?? null;

                // Store landmark if needed (as JSON)
                if (isset($exit_data['landmark'])) {
                    // Uncomment if you have a column for landmark data
                    // $detection->face_landmark = json_encode($exit_data['landmark']);
                }

                $detection->save();
                $this->info($detection);

                $this->info("Detection {$detection->id} MATCHED with IN record {$visitor_in->rec_no} (Confidence: {$confidence}%, Duration: {$minutes} min)");
                $this->matchedDataCounter++;
            } catch (Exception $err) {
                $detection->is_registered = false;
                $detection->status = false;
                $detection->save();

                Log::error("SendGateOut Error on Detection {$detection->id}: " . $err->getMessage());
                sendTelegram('🔴 Production Server [SendGateOutEvent] Send Gate Out Error: ' . $err->getMessage());
                $this->info("Error on detection {$detection->id}: " . $err->getMessage());
            }
        }

        // ============================
        // TELEGRAM SUMMARY REPORT
        // ============================
        $summary = "
🔵 <b>Production-Server [SendGateOutEvent] Summary Report</b>

<b>Total Gate-Out Detections:</b> {$visitor_detections->count()}

<b>📊 Matching Result:</b>
• 🔗 Matched Records     : <b>{$this->matchedDataCounter}</b>
• 🚫 Unmatched Records   : <b>{$this->notMatchedCounter}</b>

<b>🕒 Processing Window:</b>
• Start : <code>{$start_time}</code>
• End   : <code>{$end_time}</code>

<b>⚙️ Custom ML Parameters:</b>
• Confidence Threshold : <b>{$this->accuracy}%</b>
• Tolerance Max Stay   : <b>{$this->toleranceMaxStayMin} min</b>
• API Endpoint         : <code>{$this->api_exit_url}</code>

<b>📘 Notes:</b>
• Out records matched with IN records using Custom ML Exit API.
• Duration calculated from API response ({$this->matchedDataCounter} matched).
• Local storage verified before processing.
• Non-matched records kept as <i>unmatched</i>.
        ";

        $this->info('=== [SendGateOutData] Finished ===');
        sendTelegram($summary);

        return 0;
    }
}

<?php

namespace App\Console\Commands;

use Exception;
use Carbon\Carbon;
use Illuminate\Console\Command;
use App\Models\VisitorDetection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class SendGateInData extends Command
{
    protected $signature = 'visitor:send-gate-in';
    protected $description = 'Process incoming visitor detections and register faces to Custom ML API';

    protected string $api_url;
    protected int $sleepRequestTime = 1;
    protected int $noFaceDetectedCounter;
    protected int $sendFaceSuccessCounter;
    protected int $sendFaceFailedCounter;

    public function __construct()
    {
        parent::__construct();

        $this->api_url = env('INSIGHTFACE_API_URL') . '/entry';
        $this->sendFaceSuccessCounter = 0;
        $this->noFaceDetectedCounter = 0;
        $this->sendFaceFailedCounter = 0;
    }

    public function handle()
    {
        $visitor_detections = VisitorDetection::where('label', 'in')
            ->where('is_registered', 0)
            ->where('is_duplicate', 0)
            ->whereNotNull('person_pic_url')
            ->whereDate('locale_time', Carbon::today())
            ->latest()
            ->get();

        $this->info("Found {$visitor_detections->count()} unregistered detections.");

        foreach ($visitor_detections as $detection) {
            // Avoid throttle
            sleep($this->sleepRequestTime);

            try {
                $imageUrl = $detection->person_pic_url;
                $paths = normalizeFaceImagePath($imageUrl);

                $storagePath  = $paths['storage'];
                $absolutePath = $paths['absolute'];

                // ============================
                // FILE CHECK BLOCK
                // ============================
                if (!Storage::exists($storagePath)) {
                    $this->info("Detection {$detection->id}: FILE NOT FOUND {$storagePath}");
                    continue;
                }

                // ============================
                // SEND TO CUSTOM ML API BLOCK
                // ============================
                $response = curlMultipart(
                    $this->api_url,
                    [
                        'image' => new \CURLFile(
                            $absolutePath,
                            mime_content_type($absolutePath),
                            basename($absolutePath)
                        ),
                    ]
                );

                $status = $response['status'];
                $body   = $response['body'];

                if ($status !== 200) {
                    $this->info("Detection {$detection->id} - API Request Failed");
                    Log::error('Error in ML API entry: ' . $body);
                    $this->sendFaceFailedCounter++;
                    continue;
                }

                $api_data = json_decode($body, true);

                // Check if registration successful
                if (empty($api_data['success']) || !$api_data['success']) {
                    $detection->status = 0;
                    $detection->is_registered = 0;
                    $detection->save();
                    $this->info("Detection {$detection->id} - Registration Failed");
                    $this->sendFaceFailedCounter++;
                    continue;
                }

                // ============================
                // MAPPING RESPONSE TO DATABASE
                // ============================
                $detection->is_registered = $api_data['success'] ? 1 : 0;
                $detection->person_uid = $api_data['person_entry_id'] ?? null;
                $detection->face_sex = $api_data['gender'] ?? null;
                $detection->face_age = $api_data['age'] ?? null;
                $detection->person_pic_quality = $api_data['quality_score'] ?? null;
                $detection->is_duplicate = $api_data['is_duplicate'] ?? false;
                $detection->emotion = $api_data['expression'] ?? null;

                $detection->status = 1;
                $detection->save();

                $this->sendFaceSuccessCounter++;
                $this->info("Detection {$detection->id} REGISTERED SUCCESSFULLY" . 
                    ($detection->is_duplicate ? " (DUPLICATE)" : ""));

            } catch (Exception $err) {
                $detection->is_registered = 0;
                $detection->save();

                $this->info("ERROR ON DETECTION {$detection->id}: " . $err->getMessage());
                Log::error("SendGateIn Error on Detection {$detection->id}: " . $err->getMessage());

                sendTelegram('🔴 [SendGateInEvent] Send Gate In Error: ' . $err->getMessage());
            }
        }

        // ============================
        // TELEGRAM SUMMARY REPORT
        // ============================
        $summaryMessage = "
🟢 <b>[SendGateInEvent] Summary Report</b>

<b>Total Detections Processed:</b> {$visitor_detections->count()}

<b>📊 Processing Result:</b>
• ✅ Success Registered   : <b>{$this->sendFaceSuccessCounter}</b>
• ❌ Failed Send          : <b>{$this->sendFaceFailedCounter}</b>
• 🚫 No Face Detected     : <b>{$this->noFaceDetectedCounter}</b>

<b>🕒 Processing Time:</b>
• Date  : " . now()->format('Y-m-d H:i:s') . "

<b>⚙️ System Status:</b>
• API Endpoint  : Custom ML (InsightFace)
• API URL       : <code>{$this->api_url}</code>

<b>📘 Notes:</b>
• Only <i>unregistered</i> IN records processed.
• Duplicate detection handled automatically.
• Single-step registration (no faceset needed).
        ";

        sendTelegram($summaryMessage);
        $this->info('=== [SendGateInData] Finished ===');
        return 0;
    }
}
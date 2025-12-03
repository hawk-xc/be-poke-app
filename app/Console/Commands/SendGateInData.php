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
    protected $description = 'Process incoming visitor detections and register faces to Face++';

    protected string $apikey;
    protected string $apisecret;
    protected string $faceset_token;
    protected string $faceplus_detect_url;
    protected string $faceplus_addface_url;
    protected int $sleepRequestTime = 1;
    protected int $noFaceDetectedCounter;
    protected int $sendFaceSuccessCounter;
    protected int $sendFaceFailedCounter;

    public function __construct()
    {
        parent::__construct();

        $baseUrl = rtrim(env('FACEPLUSPLUS_URL'), '/');

        $this->faceplus_detect_url   = $baseUrl . '/detect';
        $this->faceplus_addface_url  = $baseUrl . '/faceset/addface';

        $this->apikey        = env('FACEPLUSPLUS_API_KEY');
        $this->apisecret     = env('FACEPLUSPLUS_SECRET_KEY');
        $this->faceset_token = env('FACEPLUSPLUS_FACESET_TOKEN');
        $this->sendFaceSuccessCounter   = 0;
        $this->noFaceDetectedCounter = 0;
        $this->sendFaceFailedCounter = 0;
    }

    public function handle()
    {
        $visitor_detections = VisitorDetection::where('label', 'in')
            ->where('is_registered', 0)
            ->whereNotNull('person_pic_url')
            ->whereNull('face_token')
            ->whereDate('locale_time', Carbon::today())
            ->latest()
            // ->limit(25)
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
                // FACE++ /detect (CURL MULTIPART) BLOCK
                // ============================

                $detect_response = curlMultipart(
                    $this->faceplus_detect_url,
                    [
                        'api_key'           => $this->apikey,
                        'api_secret'        => $this->apisecret,
                        'return_landmark'   => 1,
                        'image_file'        => new \CURLFile(
                            $absolutePath,                      // FULL FILE PATH
                            mime_content_type($absolutePath),   // MIME TYPE
                            basename($absolutePath)             // FILENAME
                        ),
                    ]
                );

                $status = $detect_response['status'];
                $body   = $detect_response['body'];

                // $this->info("DETECT RESPONSE ({$detection->id}): HTTP {$status}");

                Storage::put("face_detect_log_{$detection->id}.log", "HTTP {$status}\n{$body}");

                if ($status !== 200) {
                    $this->info("Detection {$detection->id} - Detect Failed");
                    Log::error('error in face detect: ' . $body);
                    $this->sendFaceFailedCounter++;
                    continue;
                }

                $detect_data = json_decode($body, true);

                if (empty($detect_data['faces'])) {
                    $detection->status = 0;
                    $detection->is_registered = 1;
                    $detection->save();
                    $this->info("Detection {$detection->id} - NO FACE DETECTED");
                    $this->noFaceDetectedCounter++;
                    continue;
                }

                // GET Metadata
                $faces = $detect_data['faces'][0];

                if (isset($faces['attributes'])) {
                    $detection->face_sex = $faces['attributes']['gender']['value'] == "Male" ? "Man" : "Woman";
                    $detection->face_age = $faces['attributes']['age']['value'] ?? null;
                }

                $face_token = $faces['face_token'] ?? null;
                $detection->face_token = $face_token;
                $detection->embedding_id = $detect_data['image_id'] ?? null;

                // ============================
                // ADD FACE TO FACESET
                // ============================
                $add_response = curlUrlencoded(
                    $this->faceplus_addface_url,
                    [
                        'api_key'       => $this->apikey,
                        'api_secret'    => $this->apisecret,
                        'faceset_token' => $this->faceset_token,
                        'face_tokens'   => $face_token,
                    ]
                );

                $add_status = $add_response['status'];
                $add_body   = $add_response['body'];

                $this->info($add_body);

                if ($add_status !== 200) {
                    $this->info("Detection {$detection->id} - AddFace Failed HTTP {$add_status}");
                    continue;
                }

                $add_data = json_decode($add_body, true);

                $detection->is_registered = 1;
                $detection->class         = $add_data['outer_id'] ?? null;
                $detection->faceset_token = $this->faceset_token ?? null;
                $detection->status        = 1;
                $detection->save();

                $this->sendFaceSuccessCounter++;
                // $this->info("Detection {$detection->id} REGISTERED SUCCESSFULLY");

            } catch (Exception $err) {

                $detection->is_registered = 0;
                $detection->save();

                $this->info("ERROR ON DETECTION {$detection->id}: " . $err->getMessage());

                sendTelegram('🟢 [SendGateInEvent] Send Gate In Errno ' . $err->getMessage());
            }
        }

        // telegram message block
        $summaryMessage = "
🟢 <b>[SendGateInEvent] Summary Report</b>

<b>Total Detections Processed:</b> {$visitor_detections->count()}

<b>📊 Processing Result:</b>
• ✅ Success Registered   : <b>{$this->sendFaceSuccessCounter}</b>
• ❌ Failed Send              : <b>{$this->sendFaceFailedCounter}</b>
• 🚫 No Face Detected     : <b>{$this->noFaceDetectedCounter}</b>

<b>🕒 Processing Time:</b>
• Date  : " . now()->format('Y-m-d') . "
• Range : 01:00 → 20:00 (Default)

<b>⚙️ System Status:</b>
• Faceset Token : <code>{$this->faceset_token}</code>
• API Endpoint  : Face++ Detect & AddFace

<b>📘 Notes:</b>
• Only <i>unregistered</i> IN records processed.
• Local image path verified before sending.
            ";

        sendTelegram($summaryMessage);
        $this->info('=== [SendGateInData] Finished ===');
        return 0;
    }
}

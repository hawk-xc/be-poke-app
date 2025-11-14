<?php

namespace App\Console\Commands;

use App\Models\VisitorDetection;
use Exception;
use GuzzleHttp\Client;
use Illuminate\Console\Command;
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

    public function __construct()
    {
        parent::__construct();

        $baseUrl = rtrim(env('FACEPLUSPLUS_URL'), '/');

        $this->faceplus_detect_url   = $baseUrl . '/detect';
        $this->faceplus_addface_url  = $baseUrl . '/faceset/addface';

        $this->apikey        = env('FACEPLUSPLUS_API_KEY');
        $this->apisecret     = env('FACEPLUSPLUS_SECRET_KEY');
        $this->faceset_token = env('FACEPLUSPLUS_FACESET_TOKEN');
    }

    public function handle()
    {
        $this->info('=== [SendGateInData] Command Started ===');

        $client = new Client([
            'timeout' => 20,
            'verify'  => false,
        ]);

        $visitor_detections = VisitorDetection::where('label', 'in')
            ->where('is_registered', 0)
            ->whereNotNull('person_pic_url')
            ->whereNull('face_token')
            ->latest()
            ->limit(25)
            ->get();

        $this->info("Found {$visitor_detections->count()} unregistered detections.");

        foreach ($visitor_detections as $detection) {
            // avoid throttling requests
            sleep(12);

            try {
                $imageUrl = $detection->person_pic_url;

                if (!str_starts_with($imageUrl, '/storage/')) {
                    $this->info("Detection {$detection->id}: invalid URL {$imageUrl}");
                    continue;
                }

                $filePath = 'public/' . ltrim(str_replace('/storage/', '', $imageUrl), '/');
                $this->info($filePath);

                if (!Storage::exists($filePath)) {
                    $this->info("Detection ID {$detection->id}: file not found {$filePath}");
                    continue;
                }

                // ====================================================
                // 1. Detect Face (menggunakan cara request referensi)
                // ====================================================
                $imageBinary = Storage::get($filePath);

                $detect_response = $client->post($this->faceplus_detect_url, [
                    'multipart' => [
                        ['name' => 'api_key', 'contents' => $this->apikey],
                        ['name' => 'api_secret', 'contents' => $this->apisecret],
                        ['name' => 'image_file', 'contents' => $imageBinary, 'filename' => basename($filePath)],
                    ],
                    'http_errors' => false,
                ]);

                $status = $detect_response->getStatusCode();
                $body   = (string) $detect_response->getBody();

                Storage::put("face_detect_log_{$detection->id}.log", "HTTP {$status}\n{$body}");

                if ($status !== 200) {
                    $this->info("Detection {$detection->id} - Face++ detect: HTTP {$status}");
                    continue;
                }

                $detect_data = json_decode($body, true);

                if (empty($detect_data['faces'])) {
                    $this->info("Detection {$detection->id} - No face detected");
                    $detection->status = false;
                    continue;
                }

                // detection metadata
                $faces = $detect_data['faces'][0];
                if (isset($faces['attributes'])) {
                    $detection->face_sex = $faces['attributes']['gender']['value'] == "Male" ? "Man" : "Woman";
                    $detection->face_age = $faces['attributes']['age']['value'] ?? null;
                }
                $face_token = $faces['face_token'] ?? null;

                $detection->face_token = $face_token;
                $detection->embedding_id = $detect_data['image_id'] ?? null;

                // ====================================================
                // 2. Add face_token to FaceSet (cara request referensi)
                // ====================================================
                $add_response = $client->post($this->faceplus_addface_url, [
                    'form_params' => [
                        'api_key'       => $this->apikey,
                        'api_secret'    => $this->apisecret,
                        'faceset_token' => $this->faceset_token,
                        'face_tokens'   => $face_token,
                    ],
                    'http_errors' => false,
                ]);

                $add_status = $add_response->getStatusCode();
                $add_response_body = (string) $add_response->getBody();
                $add_response_data = json_decode($add_response_body, true);

                if ($add_status !== 200) {
                    $this->info("Detection {$detection->id} - AddFace: HTTP {$add_status}");
                    continue;
                }

                $detection->is_registered = true;
                $detection->class = $add_response_data['outer_id'] ?? null;
                $detection->faceset_token = $add_response_data['faceset_token'] ?? null;

                // Success
                if ($add_status === 200) {
                    $detection->status = true;
                } else {
                    $detection->status = false;
                }

                $detection->save();

                $this->info("Detection {$detection->id} registered successfully.");
            } catch (Exception $err) {

                // Revert status
                $detection->is_registered = false;
                $detection->save();

                $this->info("Error on detection {$detection->id}: " . $err->getMessage());
            }
        }

        $this->info('=== [SendGateInData] Finished ===');
        return 0;
    }
}

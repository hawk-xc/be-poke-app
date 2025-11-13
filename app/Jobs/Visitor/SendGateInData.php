<?php

namespace App\Jobs\Visitor;

use App\Models\VisitorDetection;
use Exception;
use GuzzleHttp\Client;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class SendGateInData implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $apikey;
    protected $apisecret;
    protected $faceset_token;
    protected $faceplus_detect_url;
    protected $faceplus_addface_url;

    /**
     * Create a new job instance.
     */
    public function __construct()
    {
        $this->faceplus_detect_url = env('FACEPLUSPLUS_URL') . '/detect';
        $this->faceplus_addface_url = env('FACEPLUSPLUS_URL') . '/faceset/addface';
        $this->apikey = env('FACEPLUSPLUS_API_KEY');
        $this->apisecret = env('FACEPLUSPLUS_SECRET_KEY');
        $this->faceset_token = env('FACEPLUSPLUS_FACESET_TOKEN');
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        Log::info('🚀 [SendGateInData] Job started...');

        $client = new Client(['timeout' => 20, 'verify' => false]);
        $visitor_detections = VisitorDetection::where('label', 'in')
            ->where('is_registered', 0)
            ->whereNotNull('person_pic_url')
            ->whereNotNull('face_token')
            ->latest()
            ->limit(25)
            ->get();

        Log::info("📦 Found {$visitor_detections->count()} unregistered detections to process.");

        foreach ($visitor_detections as $detection) {
            $detection->save();

            try {
                $imageUrl = $detection->person_pic_url;
                if (!$imageUrl) {
                    continue;
                }

                // Convert public URL to local storage path
                $storageUrl = env('APP_URL') . '/storage/';
                if (strpos($imageUrl, $storageUrl) !== 0) {
                    Log::error("Detection ID {$detection->id}: Image URL '{$imageUrl}' is not a local storage URL.");
                    continue;
                }
                $filePath = 'public/' . substr($imageUrl, strlen($storageUrl));

                if (!Storage::exists($filePath)) {
                    Log::error("Detection ID {$detection->id}: File not found at path '{$filePath}'.");
                    continue;
                }

                $detect_response = $client->post($this->faceplus_detect_url, [
                    'multipart' => [
                        ['name' => 'api_key', 'contents' => $this->apikey],
                        ['name' => 'api_secret', 'contents' => $this->apisecret],
                        ['name' => 'image_file', 'contents' => Storage::get($filePath), 'filename' => basename($filePath)],
                    ],
                ]);

                $detect_data = json_decode($detect_response->getBody()->getContents(), true);

                // add face_token to faceset_db
                if (!empty($detect_data['faces'])) {
                    $face_token = $detect_data['faces'][0]['face_token'];
                    $detection->face_token = $face_token;

                    // 2. Add face_token to FaceSet
                    $client->post($this->faceplus_addface_url, [
                        'form_params' => [
                            'api_key' => $this->apikey,
                            'api_secret' => $this->apisecret,
                            'faceset_token' => $this->faceset_token,
                            'face_tokens' => $face_token,
                        ],
                    ]);

                    $detection->is_registered = true;
                    $detection->save();
                    Log::info("Successfully processed and added face_token for detection ID: {$detection->id}");
                } else {
                    Log::warning("No face detected for detection ID: {$detection->id}");
                }
            } catch (Exception $err) {
                // Revert is_registered status if API call fails to allow reprocessing
                $detection->is_registered = false;
                $detection->save();
                Log::error('Error processing detection ID ' . $detection->id . ': ' . $err->getMessage());
            }
        }
    }
}

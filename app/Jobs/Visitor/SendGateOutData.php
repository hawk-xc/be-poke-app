<?php

namespace App\Jobs\Visitor;

use Exception;
use Carbon\Carbon;
use GuzzleHttp\Client;
use Illuminate\Bus\Queueable;
use App\Models\VisitorDetection;
use Illuminate\Support\Facades\Log;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

class SendGateOutData implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $apikey;
    protected $apisecret;
    protected $faceset_token;
    protected $faceplus_search_url;

    /**
     * Create a new job instance.
     */
    public function __construct()
    {
        $this->faceplus_search_url = env('FACEPLUSPLUS_URL') . '/search';
        $this->apikey = env('FACEPLUSPLUS_API_KEY');
        $this->apisecret = env('FACEPLUSPLUS_SECRET_KEY');
        $this->faceset_token = env('FACEPLUSPLUS_FACESET_TOKEN');
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $client = new Client(['timeout' => 20, 'verify' => false]);

        $visitor_detections = VisitorDetection::where('label', 'out')
            ->where('is_registered', false)
            ->where('is_matched', false)
            ->whereNull('face_token')
            ->whereNotNull('person_pic_url')
            ->latest()
            ->limit(25)
            ->get();

        foreach ($visitor_detections as $detection) {
            $detection->save();

            try {
                $imageUrl = $detection->person_pic_url;
                if (!$imageUrl) {
                    Log::warning("Skipping detection ID {$detection->id}: person_pic_url is empty.");
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

                $response = $client->post($this->faceplus_search_url, [
                    'multipart' => [
                        ['name' => 'api_key', 'contents' => $this->apikey],
                        ['name' => 'api_secret', 'contents' => $this->apisecret],
                        ['name' => 'faceset_token', 'contents' => $this->faceset_token],
                        ['name' => 'image_file', 'contents' => Storage::get($filePath), 'filename' => basename($filePath)],
                    ],
                ]);

                $data = json_decode($response->getBody()->getContents(), true);
                $detection->is_registered = true;

                if (isset($data['results']) && !empty($data['results'])) {
                    $best_match = $data['results'][0];
                    // Threshold can be found in $data['thresholds']
                    if ($best_match['confidence'] >= $data['thresholds']['1e-5']) {
                        $detection->is_matched = true;
                        
                        // get rec_no from visitor in data
                        $visitor_in_data = VisitorDetection::select(['id', 'rec_no', 'locale_time'])->where('label', 'in')->where('rec_no', $best_match['rec_no'])->first();
                        
                        // get duration data
                        $localeOutTime = Carbon::parse($detection->locale_time);
                        $localeInTime = Carbon::parse($visitor_in_data->locale_time);
                        $detection->duration = $localeOutTime->diffInSeconds($localeInTime);

                        // store visitor in rec_no id
                        $detection->rec_no_in = $visitor_in_data->rec_no;

                        // get machine learning acuracy
                        $detection->similarity = $best_match['confidence'];

                        $detection->save();
                        Log::info("Match found for detection ID {$detection->id} with confidence {$best_match['confidence']}.");
                    } else {
                        Log::info("No confident match found for detection ID {$detection->id}. Highest confidence: {$best_match['confidence']}");
                    }
                } else {
                    Log::info("No match found for detection ID {$detection->id}.");
                }

            } catch (Exception $err) {
                // Revert is_registered status if API call fails to allow reprocessing
                $detection->is_registered = false;
                $detection->status = false;
                $detection->save();
                Log::error('Error processing detection ID ' . $detection->id . ': ' . $err->getMessage());
            }
        }
    }
}
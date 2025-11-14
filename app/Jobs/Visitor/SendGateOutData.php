<?php

namespace App\Jobs\Visitor;

use Exception;
use Carbon\Carbon;
use GuzzleHttp\Client;
use App\Models\VisitorDetection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;

class SendGateOutData implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected string $apikey;
    protected string $apisecret;
    protected string $faceset_token;
    protected string $faceplus_search_url;

    public function __construct()
    {
        $baseUrl = rtrim(env('FACEPLUSPLUS_URL'), '/');

        $this->faceplus_search_url = $baseUrl . '/search';
        $this->apikey        = env('FACEPLUSPLUS_API_KEY');
        $this->apisecret     = env('FACEPLUSPLUS_SECRET_KEY');
        $this->faceset_token = env('FACEPLUSPLUS_FACESET_TOKEN');
    }

    public function handle(): void
    {
        $this->info("=== [SendGateOutData] Started ===");

        $client = new Client([
            'timeout' => 20,
            'verify' => false,
        ]);

        $visitor_detections = VisitorDetection::where('label', 'out')
            ->where('is_registered', false)
            ->where('is_matched', false)
            ->whereNull('face_token')
            ->whereNotNull('person_pic_url')
            ->latest()
            ->limit(25)
            ->get();

        $this->info("Found {$visitor_detections->count()} detections.");

        foreach ($visitor_detections as $detection) {

            // Delay mirip Gate In (hindari rate limit)
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
                    $this->info("Detection {$detection->id}: file not found {$filePath}");
                    continue;
                }

                $imageBinary = Storage::get($filePath);

                // ====================================================
                // 1. SEARCH FACE++  (mengikuti pola Gate In)
                // ====================================================
                $search_response = $client->post($this->faceplus_search_url, [
                    'multipart' => [
                        ['name' => 'api_key', 'contents' => $this->apikey],
                        ['name' => 'api_secret', 'contents' => $this->apisecret],
                        ['name' => 'faceset_token', 'contents' => $this->faceset_token],
                        ['name' => 'image_file', 'contents' => $imageBinary, 'filename' => basename($filePath)],
                    ],
                    'http_errors' => false,
                ]);

                $status = $search_response->getStatusCode();
                $body   = (string) $search_response->getBody();

                Storage::put("face_search_log_out_{$detection->id}.log", "HTTP {$status}\n{$body}");

                if ($status !== 200) {
                    $this->info("Detection {$detection->id} - SearchFace: HTTP {$status}");
                    continue;
                }

                $data = json_decode($body, true);
                $detection->is_registered = true;

                // ====================================================
                // 2. PROCESS SEARCH RESULT
                // ====================================================
                if (empty($data['results'])) {
                    $this->info("Detection {$detection->id}: no result.");
                    $detection->save();
                    continue;
                }

                $best_match = $data['results'][0];
                $threshold = $data['thresholds']['1e-5'];

                if ($best_match['confidence'] >= $threshold) {

                    $this->info("Match {$detection->id} confidence {$best_match['confidence']}");

                    // ambil data gate-in
                    $visitor_in_data = VisitorDetection::select(['id', 'rec_no', 'locale_time'])
                        ->where('label', 'in')
                        ->where('rec_no', $best_match['rec_no'])
                        ->first();

                    if ($visitor_in_data) {
                        // durasi
                        $localeOut = Carbon::parse($detection->locale_time);
                        $localeIn  = Carbon::parse($visitor_in_data->locale_time);

                        $detection->duration = $localeOut->diffInSeconds($localeIn);

                        // set rec_no_in
                        $detection->rec_no_in = $visitor_in_data->rec_no;
                    }

                    // akurasi
                    $detection->similarity = $best_match['confidence'];
                    $detection->is_matched = true;
                    $detection->status = true;

                } else {
                    $this->info("No confident match for ID {$detection->id} ({$best_match['confidence']})");
                }

                $detection->save();

            } catch (Exception $err) {

                // revert status
                $detection->is_registered = false;
                $detection->status = false;
                $detection->save();

                $this->info("Error on detection {$detection->id}: " . $err->getMessage());
            }
        }

        $this->info("=== [SendGateOutData] Finished ===");
    }
}

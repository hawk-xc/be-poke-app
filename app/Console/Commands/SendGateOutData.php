<?php

namespace App\Console\Commands;

use Carbon\Carbon;
use Exception;
use GuzzleHttp\Client;
use Illuminate\Console\Command;
use App\Models\VisitorDetection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class SendGateOutData extends Command
{
    protected $signature = 'visitor:send-gate-out';
    protected $description = 'Process outgoing visitor detections and match faces using Face++';

    protected string $apikey;
    protected string $apisecret;
    protected string $faceset_token;
    protected string $faceplus_search_url;

    public function __construct()
    {
        parent::__construct();

        $baseUrl = rtrim(env('FACEPLUSPLUS_URL'), '/');

        $this->faceplus_search_url = $baseUrl . '/search';
        $this->apikey        = env('FACEPLUSPLUS_API_KEY');
        $this->apisecret     = env('FACEPLUSPLUS_SECRET_KEY');
        $this->faceset_token = env('FACEPLUSPLUS_FACESET_TOKEN');
    }

    public function handle()
    {
        $this->info('=== [SendGateOutData] Command Started ===');

        $client = new Client([
            'timeout' => 20,
            'verify'  => false,
        ]);

        $visitor_detections = VisitorDetection::where('label', 'out')
            ->where('is_registered', false)
            ->where('is_matched', false)
            ->whereNull('rec_no_in')
            ->whereNull('face_token')
            ->whereNotNull('person_pic_url')
            ->latest()
            ->limit(25)
            ->get();

        $this->info("Found {$visitor_detections->count()} unprocessed gate-out detections.");

        foreach ($visitor_detections as $detection) {
            // Avoid throttle
            sleep(12);

            try {
                $imageUrl = $detection->person_pic_url;

                // ======================================================
                // URL FILE Validation
                // ======================================================
                if (!str_starts_with($imageUrl, '/storage/')) {
                    $this->info("Detection {$detection->id}: invalid storage URL {$imageUrl}");
                    continue;
                }

                // storage path
                $filePath = 'public/' . ltrim(str_replace('/storage/', '', $imageUrl), '/');
                $this->info("Processing file: {$filePath}");

                if (!Storage::exists($filePath)) {
                    $this->info("Detection {$detection->id}: file not found {$filePath}");
                    continue;
                }

                $imageBinary = Storage::get($filePath);

                // ======================================================
                // 1. REQUEST SEARCH (FACE++ SEARCH API)
                // ======================================================
                $search_response = $client->post($this->faceplus_search_url, [
                    'multipart' => [
                        ['name' => 'api_key',        'contents' => $this->apikey],
                        ['name' => 'api_secret',     'contents' => $this->apisecret],
                        ['name' => 'faceset_token',  'contents' => $this->faceset_token],
                        ['name' => 'image_file',     'contents' => $imageBinary, 'filename' => basename($filePath)],
                    ],
                    'http_errors' => false,
                ]);

                $status  = $search_response->getStatusCode();
                $body    = (string) $search_response->getBody();

                Storage::put("face_search_log_{$detection->id}.log", "HTTP {$status}\n{$body}");

                if ($status !== 200) {
                    $this->info("Detection {$detection->id} - Search: HTTP {$status}");
                    continue;
                }

                $search_data = json_decode($body, true);

                // ======================================================
                // Result
                // ======================================================
                $detection->is_registered = true;

                if (!isset($search_data['results']) || empty($search_data['results'])) {
                    $this->info("Detection {$detection->id} - No match found");
                    $detection->save();
                    continue;
                }

                $best_match = $search_data['results'][0];
                $threshold  = $search_data['thresholds']['1e-5'] ?? 75; // acuracy threshold

                if ($best_match['confidence'] < $threshold) {
                    $this->info("Detection {$detection->id} - Low confidence match {$best_match['confidence']}");
                    $detection->save();
                    continue;
                }

                // ======================================================
                // MATCH VALID
                // ======================================================
                $this->info("Detection {$detection->id} MATCHED with confidence {$best_match['confidence']}");

                $visitor_in = VisitorDetection::select(['id', 'rec_no', 'locale_time'])
                    ->where('label', 'in')
                    ->where('face_token', $best_match['face_token'])
                    ->first();

                if (!$visitor_in) {
                    $this->info("No matching 'IN' record found for rec_no {$best_match['rec_no']}");
                    continue;
                }

                // Durasi kunjungan
                $localeOut = Carbon::parse($detection->locale_time);
                $localeIn  = Carbon::parse($visitor_in->locale_time);
                $duration  = $localeOut->diffInSeconds($localeIn);

                // Simpan hasil
                $detection->is_matched  = true;
                $detection->face_token  = $search_data['faces'][0]['face_token'] ?? null;
                $detection->embedding_id= $search_data['image_id'] ?? null;
                $detection->rec_no_in   = $visitor_in->rec_no;
                $detection->duration    = $duration;
                $detection->similarity  = $best_match['confidence'];
                $detection->status      = true;

                $detection->save();

            } catch (Exception $err) {

                $detection->is_registered = false;
                $detection->status = false;
                $detection->save();

                $this->info("Error on detection {$detection->id}: " . $err->getMessage());
            }
        }

        $this->info('=== [SendGateOutData] Finished ===');
        return 0;
    }
}

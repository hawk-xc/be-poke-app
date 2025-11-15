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
    protected string $faceplus_delete_face_token;
    protected string $faceplus_search_url;
    protected int $toleranceMaxStayMin = 40; // minutes
    protected int $getOutAttendingDataMin = 30; // minutes
    protected int $expirateOutDataHour = 12; // hour
    protected int $acuracy = 84; // percentage

    public function __construct()
    {
        parent::__construct();

        $baseUrl = rtrim(env('FACEPLUSPLUS_URL'), '/');

        $this->faceplus_search_url          = $baseUrl . '/search';
        $this->faceplus_delete_face_token   = $baseUrl . '/faceset/removeface';
        $this->apikey                       = env('FACEPLUSPLUS_API_KEY');
        $this->apisecret                    = env('FACEPLUSPLUS_SECRET_KEY');
        $this->faceset_token                = env('FACEPLUSPLUS_FACESET_TOKEN');
    }

    public function handle()
    {
        $this->info('=== [SendGateOutData] Command Started ===');

        $visitor_detections = VisitorDetection::where('label', 'out')
            ->where('is_matched', false)
            ->whereNull('rec_no_in')
            ->whereNotNull('person_pic_url')
            ->whereBetween('locale_time', [Carbon::now()->subHours($this->expirateOutDataHour), Carbon::now()->subMinutes($this->getOutAttendingDataMin)])
            ->latest()
            // ->limit(25)
            ->get();

        $this->info("Found {$visitor_detections->count()} unprocessed gate-out detections.");

        foreach ($visitor_detections as $detection) {
            // Avoid throttle
            sleep(2);

            $this->info($detection);

            try {
                $imageUrl = $detection->person_pic_url;

                // ======================================================
                // URL FILE Validation
                // ======================================================
                $filePath = normalizeFaceImagePath($imageUrl);

                $storagePath  = $filePath['storage'];
                $absolutePath = $filePath['absolute'];

                // ============================
                // CEK FILE DI STORAGE
                // ============================
                if (!Storage::exists($storagePath)) {
                    $this->info("Detection {$detection->id}: FILE NOT FOUND {$storagePath}");
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
                            $absolutePath,                      // FULL FILE PATH
                            mime_content_type($absolutePath),   // MIME TYPE
                            basename($absolutePath)             // FILENAME
                        ),
                    ]
                );

                $status = $detect_response['status'];
                $body   = $detect_response['body'];

                $this->info($body);

                if ($status !== 200) {
                    $this->info("Detection {$detection->id} - Search: HTTP {$status}");
                    continue;
                }

                $search_data = json_decode($body, true);

                // ======================================================
                // Result
                // ======================================================
                if (!isset($search_data['results']) || empty($search_data['results'])) {
                    $this->info("Detection {$detection->id} - No match found");
                    $detection->is_matched = false;
                    $detection->is_registered = true;
                    $detection->face_token = $search_data['results'][0]['face_token'] ?? null;
                    $detection->save();
                    continue;
                }

                $best_match = $search_data['results'][0];
                $threshold  = $search_data['thresholds']['1e-5'] ?? 75; // acuracy threshold

                if ($best_match['confidence'] < $this->acuracy ?? $threshold) {
                    $detection->save();
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

                $this->info($visitor_in);

                $minutes = 0;

                if (!$visitor_in) {
                    $this->info("No matching 'IN' record found");
                    continue;
                } else {
                    // Durasi kunjungan
                    $minutes = Carbon::parse($visitor_in->locale_time)
                        ->diffInMinutes(Carbon::parse($detection->locale_time));
                }

                // Simpan hasil
                $detection->is_matched      = true;
                $detection->face_token      = $search_data['faces'][0]['face_token'] ?? null;
                $detection->embedding_id    = $search_data['image_id'] ?? null;
                $detection->rec_no_in       = $visitor_in->rec_no;
                $detection->duration        = $minutes;
                $detection->similarity      = $best_match['confidence'];
                $detection->status          = true;

                $facetokens = $search_data['faces'][0]['face_token'] . ',' . $visitor_in->face_token;

                $this->info("Detection {$detection->id} MATCHED with confidence {$best_match['confidence']}");

                $deleteFaceTokenRequest = curlMultipart(
                    $this->faceplus_delete_face_token,
                    [
                        'api_key'           => $this->apikey,
                        'api_secret'        => $this->apisecret,
                        'faceset_token'     => $this->faceset_token,
                        'face_tokens'       => $facetokens
                    ]
                );

                if ($deleteFaceTokenRequest['status'] === 200) {
                    $this->info("Face tokens deleted : " . $facetokens);
                }

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

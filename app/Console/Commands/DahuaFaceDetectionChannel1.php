<?php

namespace App\Console\Commands;

use Illuminate\Support\Facades\Storage;
use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use App\Models\VisitorDetection;

use Illuminate\Console\Command;

class DahuaFaceDetectionChannel1 extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'dahua:face-detection-channel1 {start?} {end?}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    protected int $channel = 1; // out gate

    /**
     * Execute the console command.
     */
    public function handle()
    {
        date_default_timezone_set('Asia/Jakarta');
        $start_time = $this->argument('start') ?? date('Y-m-d H:i:s', strtotime('-17 minutes'));
        $end_time   = $this->argument('end')   ?? date('Y-m-d H:i:s', strtotime('-2 minutes'));

        $endpoint = rtrim(env('DAHUA_API_ENDPOINT'), '/');
        $username = env('DAHUA_DIGEST_USERNAME');
        $password = env('DAHUA_DIGEST_PASSWORD');

        $client = new Client([
            'base_uri' => $endpoint,
            'timeout' => 30,
            'verify' => false,
        ]);
        $jar = new CookieJar();

        $objId = null;

        try {
            // STEP 1: Create finder
            $res = $client->request('GET', '/cgi-bin/mediaFileFind.cgi', [
                'auth' => [$username, $password, 'digest'],
                'cookies' => $jar,
                'http_errors' => false,
                'query' => ['action' => 'factory.create'],
            ]);

            $body = trim((string) $res->getBody());
            if (!str_starts_with($body, 'result=')) {
                $this->error("Failed to create finder: {$body}");
                return 1;
            }

            $objId = str_replace('result=', '', $body);
            $this->info("Finder created: {$objId}");
            $this->info("Fetching data from {$start_time} to {$end_time}...");

            // STEP 2: Set conditions
            $client->request('GET', '/cgi-bin/mediaFileFind.cgi', [
                'auth' => [$username, $password, 'digest'],
                'cookies' => $jar,
                'http_errors' => false,
                'query' => [
                    'action' => 'findFile',
                    'object' => $objId,
                    'condition.Channel' => $this->channel,
                    'condition.StartTime' => $start_time,
                    'condition.EndTime' => $end_time,
                    'condition.Types[0]' => 'jpg',
                    'condition.Flags[0]' => 'Event',
                    'condition.Events[0]' => 'FaceDetection',
                    'condition.DB.FaceDetectionRecordFilter.ImageType' => 'GlobalSence'
                    // 'condition.DB.FaceRecognitionRecordFilter.StartTime' => $start_time,
                    // 'condition.DB.FaceRecognitionRecordFilter.EndTime' => $end_time,
                    // 'condition.DB.FaceRecognitionRecordFilter.RegType' => 'RecSuccess',
                ],
            ]);

            // STEP 3: Fetch results with pagination (loop only while last batch == $batchCount)
            $allItems = [];
            $totalReported = 0; // sum of parsed['found'] (API reported)
            $page = 1;
            $batchCount = 100; // Dahua limit per request
            $maxPages = 1000; // safety guard

            do {
                if ($page > $maxPages) {
                    $this->warn("Reached max pages ({$maxPages}), stopping to avoid infinite loop.");
                    break;
                }

                $resMediaFile = $client->request('GET', '/cgi-bin/mediaFileFind.cgi', [
                    'auth' => [$username, $password, 'digest'],
                    'cookies' => $jar,
                    'http_errors' => false,
                    'query' => [
                        'action' => 'findNextFile',
                        'object' => $objId,
                        'count' => $batchCount,
                    ],
                ]);

                $raw = (string) $resMediaFile->getBody();
                Storage::append('dahua_keepalive.log', "=== PAGE {$page} ===\n" . $raw);

                $parsed = $this->parseMediaFileResponse($raw);

                $lastCount = count($parsed['items']); // actual number of items parsed this page
                $this->info("Page {$page}: API reported found={$parsed['found']} | parsed items={$lastCount}");

                $totalReported += $parsed['found'];
                if (!empty($parsed['items'])) {
                    $allItems = array_merge($allItems, $parsed['items']);
                }

                $page++;
                // continue loop only if the last parsed item count equals batchCount (means likely more pages)
            } while ($lastCount === $batchCount);

            // SUMMARY dari API
            $this->info("Total API reported found sum: {$totalReported}");
            $this->info("Total Parsed Items collected: " . count($allItems));

            // STEP 3b: Simpan ke DB
            $skipped_no_rec_no = 0;
            $skipped_duplicate = 0;
            $saved_count = 0;
            $error_count = 0;

            foreach ($allItems as $index => $item) {
                if (empty($item['rec_no'])) {
                    $this->warn("Item index {$index} has empty rec_no, skipping");
                    $skipped_no_rec_no++;
                    continue;
                }

                if (VisitorDetection::where('rec_no', $item['rec_no'])->exists()) {
                    $skipped_duplicate++;
                    continue;
                }

                try {
                    VisitorDetection::create($item);
                    $saved_count++;
                    $this->info("Saved Visitor RecNo={$item['rec_no']}");
                } catch (\Exception $e) {
                    $error_count++;
                    $this->error("Failed to save RecNo={$item['rec_no']}: " . $e->getMessage());
                    $this->error("Item structure: " . json_encode($item, JSON_PRETTY_PRINT));
                }
            }

            // SUMMARY penyimpanan
            $this->info("=== SUMMARY ===");
            $this->info("Saved: {$saved_count}");
            $this->info("Skipped (no rec_no): {$skipped_no_rec_no}");
            $this->info("Skipped (duplicate): {$skipped_duplicate}");
            $this->info("Errors: {$error_count}");
        } catch (\Exception $e) {
            $this->error("Fetch error: " . $e->getMessage());
        } finally {
            // STEP 4: Close finder jika berhasil dibuat
            if (!empty($objId)) {
                try {
                    $client->request('GET', '/cgi-bin/mediaFileFind.cgi', [
                        'auth' => [$username, $password, 'digest'],
                        'cookies' => $jar,
                        'http_errors' => false,
                        'query' => ['action' => 'close', 'object' => $objId],
                    ]);
                    $this->info("Finder destroyed: {$objId}");
                } catch (\Exception $e) {
                    $this->warn("Failed to destroy finder {$objId}: " . $e->getMessage());
                }
            }
        }

        return 0;
    }

    private function parseMediaFileResponse(string $raw): array
    {
        $lines = explode("\n", trim($raw));
        $result = ['items' => [], 'found' => 0];

        $endpoint = rtrim(env('DAHUA_API_ENDPOINT'), '/');
        $username = env('DAHUA_DIGEST_USERNAME');
        $password = env('DAHUA_DIGEST_PASSWORD');

        foreach ($lines as $line) {
            $line = trim($line);

            if (preg_match('/^found=(\d+)/', $line, $m)) {
                $result['found'] = (int)$m[1];
                continue;
            }

            if (preg_match('/^items\[(\d+)\]\.(.+?)=(.*)$/', $line, $m)) {
                $idx = (int)$m[1];
                $key = $m[2];
                $val = trim($m[3]);

                if (!isset($result['items'][$idx])) {
                    $result['items'][$idx] = [];
                }

                switch ($key) {
                    case 'RecNo':
                        $result['items'][$idx]['rec_no'] = (int)$val;
                        break;
                    case 'Channel':
                        $result['items'][$idx]['channel'] = (int)$val;
                        break;
                    case 'StartTime':
                        $result['items'][$idx]['locale_time'] = $val;
                        break;
                    case 'EndTime':
                        $result['items'][$idx]['locale_time_end'] = $val;
                        break;
                    case 'StartTimeRealUTC':
                        $result['items'][$idx]['utc'] = strtotime($val);
                        $result['items'][$idx]['real_utc'] = strtotime($val);
                        break;
                    case 'EndTimeRealUTC':
                        $result['items'][$idx]['utc_end'] = strtotime($val);
                        break;
                    case 'FilePath':
                        try {
                            if (
                                !empty($result['items'][$idx]['rec_no']) &&
                                \App\Models\VisitorDetection::where('rec_no', $result['items'][$idx]['rec_no'])->exists()
                            ) {
                                break;
                            }

                            $client = new \GuzzleHttp\Client([
                                'auth' => [$username, $password, 'digest'],
                                'verify' => false,
                            ]);

                            $fileName = basename($val);
                            $savePath = storage_path('app/public/faceDetection_folder/' . $fileName);

                            if (!file_exists(dirname($savePath))) {
                                mkdir(dirname($savePath), 0775, true);
                            }

                            $res = $client->get($endpoint . '/RPC_Loadfile' . $val, [
                                'sink' => $savePath,
                            ]);

                            if ($res->getStatusCode() == 200) {
                                $result['items'][$idx]['person_pic_url'] = '/storage/faceDetection_folder/' . $fileName;
                            }
                        } catch (\Exception $e) {
                            system_log('error', 'Gagal mengunduh gambar FaceDetection file_path image : ' . $e->getMessage());
                        }
                        break;

                    case 'SummaryNew[0].EventType':
                        $result['items'][$idx]['event_type'] = $val;
                        break;
                    case 'SummaryNew[0].Value.Age':
                        $result['items'][$idx]['face_age'] = (int)$val;
                        break;
                    case 'SummaryNew[0].Value.Sex':
                        $result['items'][$idx]['face_sex'] = $val;
                        break;
                    case 'SummaryNew[0].Value.Emotion':
                        $result['items'][$idx]['emotion'] = $val;
                        break;
                    case 'SummaryNew[0].Value.Attractive':
                        $result['items'][$idx]['face_quality'] = (int)$val;
                        break;
                    case 'TaskID':
                        $result['items'][$idx]['task_id'] = (int)$val;
                        break;
                    case 'TaskName':
                        $result['items'][$idx]['task_name'] = $val;
                        break;
                }
            }
        }

        // Default values biar match tabel
        foreach ($result['items'] as &$item) {
            $item = array_merge([
                'channel' => $this->channel,
                'rec_no' => null,
                'channel' => 0,
                'code' => null,
                'action' => null,
                'class' => null,
                'event_type' => null,
                'name' => null,
                'is_global_scene' => 0,
                'locale_time' => null,
                'utc' => null,
                'real_utc' => null,
                'face_age' => null,
                'face_sex' => null,
                'face_quality' => null,
                'emotion' => null,
                'person_pic_url' => null,
                'status' => 0,
            ], $item);
        }

        return $result;
    }
}

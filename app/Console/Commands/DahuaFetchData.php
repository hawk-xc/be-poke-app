<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use App\Models\Visitor;

class DahuaFetchData extends Command
{
    protected $signature = 'dahua:fetch-data {start?} {end?}';
    protected $description = 'Fetch Dahua media files by time range';

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

            // STEP 2: Set conditions
            $client->request('GET', '/cgi-bin/mediaFileFind.cgi', [
                'auth' => [$username, $password, 'digest'],
                'cookies' => $jar,
                'http_errors' => false,
                'query' => [
                    'action' => 'findFile',
                    'object' => $objId,
                    'condition.Channel' => 1,
                    'condition.StartTime' => $start_time,
                    'condition.EndTime' => $end_time,
                    'condition.Types[0]' => 'jpg',
                    'condition.Flags[0]' => 'Event',
                    'condition.Events[0]' => 'FaceDetection,FaceRecognition',
                    'condition.DB.FaceRecognitionRecordFilter.StartTime' => $start_time,
                    'condition.DB.FaceRecognitionRecordFilter.EndTime' => $end_time,
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

                if (Visitor::where('rec_no', $item['rec_no'])->exists()) {
                    $skipped_duplicate++;
                    continue;
                }

                try {
                    Visitor::create($item);
                    $saved_count++;
                    $this->info("Saved Visitor RecNo={$item['rec_no']} UID={$item['person_uid']}");
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
                        $result['items'][$idx]['start_time'] = $this->parseDateTime($val);
                        break;
                    case 'EndTime':
                        $result['items'][$idx]['end_time'] = $this->parseDateTime($val);
                        break;
                    case 'StartTimeRealUTC':
                        $result['items'][$idx]['start_time_utc'] = $this->parseDateTime($val);
                        break;
                    case 'EndTimeRealUTC':
                        $result['items'][$idx]['end_time_utc'] = $this->parseDateTime($val);
                        break;
                    case 'FilePath':
                        try {
                            $client = new \GuzzleHttp\Client([
                                'auth' => [$username, $password, 'digest'],
                                'verify' => false,
                            ]);

                            $fileName = basename($val);
                            $savePath = storage_path('app/public/faceRecognition_folder/' . $fileName);

                            if (!file_exists(dirname($savePath))) {
                                mkdir(dirname($savePath), 0775, true);
                            }

                            $res = $client->get($endpoint . '/RPC_Loadfile' . $val, [
                                'sink' => $savePath,
                            ]);

                            if ($res->getStatusCode() == 200) {
                                $result['items'][$idx]['file_path'] = '/storage/faceRecognition_folder/' . $fileName;
                            }
                        } catch (\Exception $e) {
                            system_log('error', 'Gagal mengunduh gambar FaceRecognition file_path image : ' . $e->getMessage());
                        }

                        break;
                    case 'VideoPath':
                        $result['items'][$idx]['video_path'] = $val;
                        break;
                    case 'Length':
                        $result['items'][$idx]['length'] = (int)$val;
                        break;
                    case 'SecondaryAnalyseType':
                        $result['items'][$idx]['secondary_analyse_type'] = (int)$val;
                        break;
                    case 'SummaryNew[0].Value.Candidates[0].Person.UID':
                        $result['items'][$idx]['person_uid'] = !empty($val) ? (int)$val : null;
                        break;
                    case 'SummaryNew[0].Value.Candidates[0].Person.GroupName':
                        $result['items'][$idx]['person_group'] = $val;
                        break;
                    case 'SummaryNew[0].Value.Candidates[0].Similarity':
                        $result['items'][$idx]['similarity'] = (int)$val;
                        break;
                    case 'SummaryNew[0].Value.Candidates[0].Person.Image[0].FilePath':
                        $result['items'][$idx]['person_image'] = $val;
                        break;
                    case 'SummaryNew[0].Value.Object.Age':
                        $result['items'][$idx]['age'] = (int)$val;
                        break;
                    case 'SummaryNew[0].Value.Object.Sex':
                        $result['items'][$idx]['sex'] = $val;
                        break;
                    case 'SummaryNew[0].Value.Object.Mask':
                        $result['items'][$idx]['mask'] = (int)$val;
                        break;  
                    case 'SummaryNew[0].Value.Object.Glasses':
                        $result['items'][$idx]['glasses'] = (int)$val;
                        break;
                    case 'SummaryNew[0].Value.Object.Beard':
                        $result['items'][$idx]['beard'] = (int)$val;
                        break;
                    case 'SummaryNew[0].Value.Object.Emotion':
                        $result['items'][$idx]['emotion'] = $val;
                        break;
                    case 'SummaryNew[0].Value.Object.Attractive':
                        $result['items'][$idx]['attractive'] = (int)$val;
                        break;
                    case 'SummaryNew[0].Value.Object.Mouth':
                        $result['items'][$idx]['mouth'] = (int)$val;
                        break;
                    case 'SummaryNew[0].Value.Object.Eye':
                        $result['items'][$idx]['eye'] = (int)$val;
                        break;
                    case 'SummaryNew[0].Value.Object.Strabismus':
                        $result['items'][$idx]['strabismus'] = (int)$val;
                        break;
                    case 'SummaryNew[0].Value.Object.Nation':
                        $result['items'][$idx]['nation'] = (int)$val;
                        break;
                    case 'SummaryNew[0].Value.Object.Image.FilePath':
                        $result['items'][$idx]['object_image'] = $val;
                        break;
                    case 'SummaryNew[0].Value.ImageInfo.FilePath':
                        try {
                            $client = new \GuzzleHttp\Client([
                                'auth' => [$username, $password, 'digest'],
                                'verify' => false,
                            ]);

                            $fileName = basename($val); // get file name
                            $savePath = storage_path('app/public/faceRecognition_folder/' . $fileName);

                            if (!file_exists(dirname($savePath))) {
                                mkdir(dirname($savePath), 0775, true);
                            }

                            $res = $client->get($endpoint . '/RPC_Loadfile' . $val, [
                                'sink' => $savePath,
                            ]);

                            if ($res->getStatusCode() == 200) {
                                $result['items'][$idx]['image_info_path'] = '/storage/faceRecognition_folder/' . $fileName;
                            }
                        } catch (\Exception $e) {
                            system_log('error', 'Gagal mengunduh gambar FaceRecognition file_path image : ' . $e->getMessage());
                        }

                        break;
                    case 'SummaryNew[0].Value.ImageInfo.Length':
                        $result['items'][$idx]['image_length'] = (int)$val;
                        break;
                    case 'SummaryNew[0].Value.Center[0]':
                        $result['items'][$idx]['center_x'] = (int)$val;
                        break;
                    case 'SummaryNew[0].Value.Center[1]':
                        $result['items'][$idx]['center_y'] = (int)$val;
                        break;
                    case 'SummaryNew[0].Value.MachineAddress':
                        $result['items'][$idx]['machine_address'] = (int)$val;
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

        foreach ($result['items'] as $idx => &$item) {
            if (isset($item['center_x']) && isset($item['center_y'])) {
                $item['center'] = json_encode([$item['center_x'], $item['center_y']]);
                unset($item['center_x'], $item['center_y']);
            }

            $item = array_merge([
                'rec_no' => null,
                'channel' => 0,
                'person_uid' => null,
                'person_group' => '',
                'similarity' => 0,
                'age' => 0,
                'sex' => 'Unknown',
                'mask' => 0,
                'glasses' => 0,
                'beard' => 0,
                'emotion' => 'Unknown',
                'attractive' => 0,
                'mouth' => 0,
                'eye' => 0,
                'strabismus' => 0,
                'nation' => 0,
                'center' => '[]',
            ], $item);
        }

        $result['items'] = array_values($result['items']);

        return $result;
    }

    private function parseDateTime($dateString): string
    {
        if (str_contains($dateString, 'T') && str_contains($dateString, 'Z')) {
            return date('Y-m-d H:i:s', strtotime($dateString));
        }

        return date('Y-m-d H:i:s', strtotime($dateString));
    }
}

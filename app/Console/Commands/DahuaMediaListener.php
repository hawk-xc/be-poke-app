<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use App\Models\Visitor;

class DahuaMediaListener extends Command
{
    protected $signature = 'dahua:media-listen';
    protected $description = 'Test mediaFileFind without keep-alive, parse and save to Visitor model';

    public function handle()
    {
        date_default_timezone_set('Asia/Jakarta');
        $start_time = date('Y-m-d') . ' 01:00:00';
        $end_time = date('Y-m-d H:i:s');

        $this->info('Starting Dahua IVS media listener...');

        $endpoint = rtrim(env('DAHUA_API_ENDPOINT'), '/');
        $username = env('DAHUA_DIGEST_USERNAME');
        $password = env('DAHUA_DIGEST_PASSWORD');

        $client = new Client([
            'base_uri' => $endpoint,
            'timeout'  => 15,
            'verify'   => false,
        ]);
        $jar = new CookieJar();

        try {
            // STEP 1: create object finder
            $res = $client->request('GET', '/cgi-bin/mediaFileFind.cgi', [
                'auth' => [$username, $password, 'digest'],
                'cookies' => $jar,
                'http_errors' => false,
                'query' => ['action' => 'factory.create'],
            ]);

            $body = trim((string) $res->getBody());
            if (stripos($body, 'result=') === false) {
                $this->error("Failed to create finder object");
                return;
            }

            $objId = str_replace('result=', '', $body);
            $this->info("Finder Object created: {$objId}");

            // STEP 2: set condition
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
                    'condition.Events[0]' => 'FaceRecognition',
                    'condition.DB.FaceRecognitionRecordFilter.RegType' => 'RecSuccess',
                    'condition.DB.FaceRecognitionRecordFilter.StartTime' => $start_time,
                    'condition.DB.FaceRecognitionRecordFilter.EndTime' => $end_time,
                ],
            ]);

            // STEP 3: get media files
            $resMediaFile = $client->request('GET', '/cgi-bin/mediaFileFind.cgi', [
                'auth' => [$username, $password, 'digest'],
                'cookies' => $jar,
                'http_errors' => false,
                'query' => [
                    'action' => 'findNextFile',
                    'object' => $objId,
                    'count' => 100,
                ],
            ]);

            $raw = (string) $resMediaFile->getBody();

            // simpan raw ke log file
            Storage::put('dahua_media.log', $raw);

            // parse
            $parsed = $this->parseMediaFileResponse($raw);

            if (isset($parsed['items']) && count($parsed['items']) > 0) {
                // urutkan berdasarkan Person UID
                usort($parsed['items'], function ($a, $b) {
                    return ($a['person_uid'] ?? 0) <=> ($b['person_uid'] ?? 0);
                });

                foreach ($parsed['items'] as $item) {
                    if (!$item['rec_no']) {
                        continue;
                    }

                    // skip jika RecNo sudah ada
                    if (Visitor::where('rec_no', $item['rec_no'])->exists()) {
                        continue;
                    }

                    Visitor::create($item);
                    $this->info("Saved Visitor RecNo={$item['rec_no']} UID={$item['person_uid']}");
                }
            }

            // STEP 4: destroy finder
            $client->request('GET', '/cgi-bin/mediaFileFind.cgi', [
                'auth' => [$username, $password, 'digest'],
                'cookies' => $jar,
                'http_errors' => false,
                'query' => ['action' => 'close', 'object' => $objId],
            ]);
            $this->info("Finder Object destroyed: {$objId}");
        } catch (\Exception $e) {
            $this->error('Error: ' . $e->getMessage());
            Log::error($e);
        }
    }

    private function parseMediaFileResponse(string $raw): array
    {
        $lines = explode("\n", trim($raw));
        $result = ['items' => []];
        $current = [];

        foreach ($lines as $line) {
            $line = trim($line);

            if (preg_match('/^found=(\d+)/', $line, $m)) {
                $result['found'] = (int) $m[1];
                continue;
            }

            if (preg_match('/^items\[(\d+)\]\.(.+?)=(.*)$/', $line, $m)) {
                $idx = $m[1];
                $key = $m[2];
                $val = trim($m[3]);

                if (!isset($result['items'][$idx])) {
                    $result['items'][$idx] = [];
                }

                // map key -> kolom Visitor
                switch ($key) {
                    case 'RecNo':
                        $result['items'][$idx]['rec_no'] = (int)$val;
                        break;
                    case 'Channel':
                        $result['items'][$idx]['channel'] = (int)$val;
                        break;
                    case 'StartTime':
                        $result['items'][$idx]['start_time'] = date('Y-m-d H:i:s', strtotime($val));
                        break;
                    case 'EndTime':
                        $result['items'][$idx]['end_time'] = date('Y-m-d H:i:s', strtotime($val));
                        break;
                    case 'StartTimeRealUTC':
                        $result['items'][$idx]['start_time_utc'] = date('Y-m-d H:i:s', strtotime($val));
                        break;
                    case 'EndTimeRealUTC':
                        $result['items'][$idx]['end_time_utc'] = date('Y-m-d H:i:s', strtotime($val));
                        break;
                    case 'FilePath':
                        $result['items'][$idx]['file_path'] = $val;
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

                    // Person Info
                    case 'SummaryNew[0].Value.Candidates[0].Person.UID':
                        $result['items'][$idx]['person_uid'] = (int)$val;
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

                    // Object Info
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

                    // ImageInfo
                    case 'SummaryNew[0].Value.ImageInfo.FilePath':
                        $result['items'][$idx]['image_info_path'] = $val;
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

                    // Task
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

        // gabungkan center jadi JSON
        foreach ($result['items'] as &$item) {
            if (isset($item['center_x']) && isset($item['center_y'])) {
                $item['center'] = json_encode([$item['center_x'], $item['center_y']]);
                unset($item['center_x'], $item['center_y']);
            }
        }

        return $result;
    }
}

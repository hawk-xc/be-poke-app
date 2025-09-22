<?php

namespace App\Console\Commands;

use GuzzleHttp\Client;
use Illuminate\Console\Command;
use GuzzleHttp\Cookie\CookieJar;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Artisan;

class DahuaEventListener extends Command
{
    protected $signature = 'dahua:listen-events';
    protected $description = 'Listen to Dahua IVS Event Manager via keep-alive request';

    public function handle()
    {
        date_default_timezone_set('Asia/Jakarta');
        $start_time = date('Y-m-d 01:00:00', strtotime('-1 day'));
        $end_time = date('Y-m-d H:i:s');

        $this->info('Starting Dahua IVS event listener...');

        $endpoint = rtrim(env('DAHUA_API_ENDPOINT'), '/');
        $url = $endpoint . '/eventManager.cgi?action=attach&codes=[All]';
        $username = env('DAHUA_DIGEST_USERNAME');
        $password = env('DAHUA_DIGEST_PASSWORD');

        $ch = curl_init();

        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_USERPWD, "$username:$password");
        curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_DIGEST);
        curl_setopt($ch, CURLOPT_WRITEFUNCTION, function ($ch, $data) use ($endpoint, $username, $password, $start_time, $end_time) {
            $event = trim($data);

            if (!empty($event)) {
                $client = new Client([
                    'base_uri' => $endpoint,
                    'timeout' => 15,
                    'verify' => false,
                ]);
                $jar = new CookieJar();

                try {
                    // call the fetch data command
                    Artisan::call('dahua:fetch-data');
                    echo Artisan::output();
                    echo "Fetch command executed\n";
                } catch (\Exception $e) {
                    Log::error("Keep-alive error: " . $e->getMessage());
                }
            }

            return strlen($data); // keep connection alive
        });

        curl_setopt($ch, CURLOPT_TIMEOUT, 0);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 0);

        $this->info('Listening... press CTRL+C to stop');
        curl_exec($ch);

        if (curl_errno($ch)) {
            $this->error('cURL error: ' . curl_error($ch));
        }

        curl_close($ch);
    }

    private function parseMediaFileResponse(string $raw): array
    {
        $lines = explode("\n", trim($raw));
        $result = ['items' => []];

        foreach ($lines as $line) {
            $line = trim($line);
            if (preg_match('/^found=(\d+)/', $line, $m)) {
                $result['found'] = (int)$m[1];
                continue;
            }

            if (preg_match('/^items\[(\d+)\]\.(.+?)=(.*)$/', $line, $m)) {
                $idx = $m[1];
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

        foreach ($result['items'] as &$item) {
            if (isset($item['center_x']) && isset($item['center_y'])) {
                $item['center'] = json_encode([$item['center_x'], $item['center_y']]);
                unset($item['center_x'], $item['center_y']);
            }
        }

        return $result;
    }
}

<?php

use Illuminate\Support\Facades\Auth;
use App\Models\UserLog;
use App\Models\SystemLog;

if (!function_exists('user_log')) {
    function user_log($action, $user_id = null, $email = null, $type = 'auth', $message = null)
    {
        return UserLog::create([
            'user_id' => $user_id,
            'email'   => $email,
            'type'    => $type,
            'action'  => $action,
            'message' => $message,
        ]);
    }
}

if (!function_exists('system_log')) {
    function system_log($type = 'info', $message = null)
    {
        SystemLog::create([
            'type' => $type,
            'message' => $message
        ]);
    }
}

if (!function_exists('parseFeceDetectionData')) {
    function parseFeceDetectionData($raw, int $channel = 1, string $label = 'in')
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
                'label' => $label,
                'channel' => $channel,
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

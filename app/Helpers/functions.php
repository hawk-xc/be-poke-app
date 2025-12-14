<?php

use App\Models\UserLog;
use App\Models\SystemLog;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;

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
    function parseFeceDetectionData($raw, int $channel = 1, string $label = 'in', string $gate_name = 'Gate-In-1')
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
                            $downloadedUrl = downloadMedia(ltrim($val, '/'), $label ?? 'out');

                            if (str_starts_with($downloadedUrl, '/storage/')) {
                                $result['items'][$idx]['person_pic_url'] = $downloadedUrl;
                                Log::info("Download sukses via helper: {$downloadedUrl}");
                            } else {
                                Log::error("Download gagal via helper: {$downloadedUrl}");
                            }
                        } catch (\Exception $e) {
                            Log::error("Gagal unduh FaceDetection {$val}: " . $e->getMessage());
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
                'gate_name' => $gate_name,
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

if (!function_exists('downloadMedia')) {
    function downloadMedia(string $fileName, string $label = "in")
    {

        $endpoint = rtrim(env('DAHUA_API_ENDPOINT'), '/');
        $username = env('DAHUA_DIGEST_USERNAME');
        $password = env('DAHUA_DIGEST_PASSWORD');

        try {
            $savePath = storage_path('app/public/faceDetection_folder/' . $label . '/' . $fileName);

            if (!file_exists(dirname($savePath))) {
                mkdir(dirname($savePath), 0775, true);
            }

            $jar = new \GuzzleHttp\Cookie\CookieJar();

            $client = new \GuzzleHttp\Client([
                'base_uri' => $endpoint,
                'verify' => false,
                'cookies' => $jar,
                'timeout' => 60,
                'curl' => [
                    CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                    CURLOPT_FORBID_REUSE => true,
                    CURLOPT_FRESH_CONNECT => true,
                ],
                'headers' => [
                    'User-Agent' => 'curl/7.85.0',
                    'Connection' => 'close',
                    'Accept' => '*/*',
                ],
                'http_errors' => false,
            ]);

            $url = '/cgi-bin/RPC_Loadfile/' . $fileName;

            try {
                $client->get($url, ['http_errors' => false]);
            } catch (\Exception $e) {
                return $e->getMessage();
            }

            // 2) real request with digest auth
            $res = $client->get($url, [
                'auth' => [$username, $password, 'digest'],
                'sink' => $savePath,
                'http_errors' => false,
                'allow_redirects' => false,
            ]);

            $status = $res->getStatusCode();
            if ($status === 200 && file_exists($savePath)) {
                return '/storage/faceDetection_folder/' . $label . '/' . $fileName;
            } else {
                return "Download gagal: HTTP {$status}, val={$val}";
            }
        } catch (\Exception $err) {
            return $err->getMessage();
        }
    }
}

// if (!function_exists('normalizeFaceImagePath')) {
//     function normalizeFaceImagePath($urlOrPath)
//     {
//         $clean = parse_url($urlOrPath, PHP_URL_PATH);

//         $pos = strpos($clean, 'faceDetection_folder');

//         if ($pos === false) {
//             throw new Exception("Invalid face image path: {$urlOrPath}");
//         }

//         $relative = substr($clean, $pos);

//         $relative = ltrim($relative, '/');

//         return [
//             'storage'  => "public/{$relative}",
//             'absolute' => storage_path("app/public/{$relative}"),
//         ];
//     }
// }

function normalizeFaceImagePath(string $path): array
{
    $path = ltrim($path, '/');

    if (!str_starts_with($path, 'face-detection/')) {
        throw new Exception("Invalid face image path: {$path}");
    }

    return [
        'storage' => $path, // ONLY THIS
    ];
}


/**
 * Curl Multipart (upload file)
 */
if (!function_exists('curlMultipart')) {
    function curlMultipart(string $url, array $fields): array
    {
        $ch = curl_init();

        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_POSTFIELDS => $fields,
        ]);

        $body   = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $errno  = curl_errno($ch);

        curl_close($ch);

        return [
            'status' => $status,
            'body'   => $body,
            'error'  => $errno,
        ];
    }
}

/**
 * Curl application/x-www-form-urlencoded
 */
if (!function_exists('curlUrlencoded')) {

    function curlUrlencoded(string $url, array $fields): array
    {
        $ch = curl_init();

        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
            CURLOPT_POSTFIELDS => http_build_query($fields),
        ]);

        $body   = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $errno  = curl_errno($ch);

        curl_close($ch);

        return [
            'status' => $status,
            'body'   => $body,
            'error'  => $errno,
        ];
    }
}

if (!function_exists('sendTelegram')) {
    /**
     * Send message to Telegram
     *
     * @param string $message Message to send
     * @return array|null Response from Telegram API or null on error
     */
    function sendTelegram($message)
    {
        $TG_TOKEN   = env('TG_TOKEN');
        $TG_CHAT_ID = env('TG_CHAT_ID');

        if (!$TG_TOKEN || !$TG_CHAT_ID) {
            return false;
        }

        $telegramApi = "https://api.telegram.org/bot{$TG_TOKEN}/sendMessage";

        $postData = [
            'chat_id' => $TG_CHAT_ID,
            'text'    => $message,
            'parse_mode' => 'HTML',
        ];

        // CURL
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $telegramApi);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        $result = curl_exec($ch);
        $error  = curl_error($ch);
        curl_close($ch);

        if ($error) {
            Log::error('Telegram send error: ' . $error);
            return false;
        }

        return json_decode($result, true);
    }
}

if (!function_exists('exportMatchedCSV')) {
    function exportMatchedCSV($query)
    {
        $filename = "twc_visitors_" . now()->format('Ymd_His') . ".csv";

        $headers = [
            "Content-Type"        => "text/csv",
            "Content-Disposition" => "attachment; filename=$filename",
        ];

        $callback = function () use ($query) {

            $file = fopen('php://output', 'w');

            fputcsv($file, [
                'IN ID',
                'OUT ID',
                'GATE IN',
                'GATE OUT',
                'TIME IN',
                'TIME OUT',
                'EMOTION',
                'SEX'
            ]);

            $query->orderBy('id')->chunk(500, function ($rows) use ($file) {
                foreach ($rows as $out) {

                    $ins = $out->visitorIn()->orderBy('locale_time', 'desc')->get();

                    if ($ins->isEmpty()) {
                        fputcsv($file, [
                            null,
                            $out->id,
                            null,
                            $out->gate_name,
                            null,
                            $out->locale_time,
                            $out->emotion,
                            $out->face_sex,
                        ]);
                        continue;
                    }

                    foreach ($ins as $in) {
                        fputcsv($file, [
                            $in->id,
                            $out->id,
                            $in->gate_name,
                            $out->gate_name,
                            $in->locale_time,
                            $out->locale_time,
                            $out->emotion,
                            $out->face_sex,
                        ]);
                    }
                }
            });

            fclose($file);
        };

        return response()->stream($callback, 200, $headers);
    }
}

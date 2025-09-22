<?php

namespace App\Console\Commands;

use App\Models\VisitorDetection;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class DahuaHumanDetectionListener extends Command
{
    protected $signature = 'dahua:human-detection-listener';
    protected $description = 'Listen to Dahua IVS Event Manager for FaceDetection, FaceRecognition via keep-alive request';

    public function handle()
    {
        date_default_timezone_set('Asia/Jakarta');

        $this->info('Starting Dahua IVS event listener...');

        $endpoint = rtrim(env('DAHUA_API_ENDPOINT'), '/');
        $url = $endpoint . '/eventManager.cgi?action=attach&codes=[FaceDetection,FaceRecognition]';
        $username = env('DAHUA_DIGEST_USERNAME');
        $password = env('DAHUA_DIGEST_PASSWORD');

        $ch = curl_init();

        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_USERPWD, "$username:$password");
        curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_DIGEST);

        curl_setopt($ch, CURLOPT_WRITEFUNCTION, function ($ch, $data) {
            static $buffer = '';

            $buffer .= $data;

            while (($pos = strpos($buffer, '--myboundary')) !== false) {
                $eventBlock = substr($buffer, 0, $pos);
                $buffer = substr($buffer, $pos + strlen('--myboundary'));

                $event = trim($eventBlock);

                if (empty($event) || $event === "OK") {
                    continue;
                }

                echo "=== Event Baru ===\n";

                if (preg_match('/(Code=.*?;.*?)data=({.*})/s', $event, $matches)) {
                    $meta = $matches[1];
                    $jsonStr = $matches[2];

                    $lastBrace = strrpos($jsonStr, '}');
                    if ($lastBrace !== false) {
                        $jsonStr = substr($jsonStr, 0, $lastBrace + 1);
                    }

                    $json = json_decode($jsonStr, true);
                    if (json_last_error() !== JSON_ERROR_NONE) {
                        echo "Invalid JSON: " . json_last_error_msg() . PHP_EOL;
                        continue;
                    }

                    foreach (explode(';', trim($meta, ';')) as $pair) {
                        if (empty($pair)) continue;
                        [$k, $v] = explode('=', $pair);
                        $json[ucfirst(strtolower($k))] = $v;
                    }

                    echo json_encode($json, JSON_PRETTY_PRINT) . PHP_EOL;

                    $this->processEvent($json);
                } else {
                    echo "Event tidak sesuai pola:\n$event\n";
                }
            }

            return strlen($data);
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

    private function processEvent(array $data)
    {
        try {
            $face = $data['Face'] ?? [];
            $object = $data['Object'] ?? [];
            $passerby = $data['Passerby'] ?? [];
            $candidates = $data['Candidates'] ?? [];
            $candidate0 = $candidates[0] ?? null;
            $person = $candidate0['Person'] ?? [];

            $payload = [
                'code' => $data['Code'] ?? $data['code'] ?? null,
                'action' => $data['Action'] ?? $data['action'] ?? null,
                'class' => $data['Class'] ?? null,
                'event_type' => $data['EventType'] ?? null,
                'name' => $data['Name'] ?? null,
                'is_global_scene' => $data['IsGlobalScene'] ?? null,
                'locale_time' => $data['LocaleTime'] ?? null,
                'utc' => $data['UTC'] ?? null,
                'real_utc' => $data['RealUTC'] ?? null,
                'sequence' => $data['Sequence'] ?? null,

                // Face
                'face_age' => $face['Age'] ?? null,
                'face_sex' => $face['Sex'] ?? null,
                'face_quality' => $face['FaceQuality'] ?? null,
                'face_angle' => isset($face['Angle']) ? json_encode($face['Angle']) : null,
                'face_bounding_box' => isset($face['BoundingBox']) ? json_encode($face['BoundingBox']) : null,
                'face_center' => isset($face['Center']) ? json_encode($face['Center']) : null,
                'face_feature' => isset($face['Feature']) ? json_encode($face['Feature']) : null,
                'face_object_id' => $face['ObjectID'] ?? $face['ObjectId'] ?? null,

                // Object
                'object_action' => $object['Action'] ?? null,
                'object_bounding_box' => isset($object['BoundingBox']) ? json_encode($object['BoundingBox']) : null,
                'object_age' => $object['Age'] ?? null,
                'object_sex' => $object['Sex'] ?? null,
                'frame_sequence' => $object['FrameSequence'] ?? null,
                'emotion' => $object['Emotion'] ?? null,

                // Passerby
                'passerby_group_id' => $passerby['GroupID'] ?? null,
                'passerby_uid' => $passerby['UID'] ?? null,

                // Person (Candidates)
                'person_id' => $person['PersonID'] ?? $person['PersonId'] ?? null,
                'person_uid' => $person['UID'] ?? null,
                'person_name' => $person['Name'] ?? null,
                'person_sex' => $person['Sex'] ?? null,
                'person_group_name' => $person['GroupName'] ?? null,
                'person_group_type' => $person['GroupType'] ?? null,
                'person_pic_url' => $person['PicUrl'] ?? $person['picUrl'] ?? null,
                'person_pic_quality' => $person['PicQuality'] ?? null,
                'similarity' => $candidate0['Similarity'] ?? null,

                'raw_data' => json_encode($data),
            ];

            try {
                $saved = VisitorDetection::create($payload);
                $this->info("✅ Event saved: " . json_encode([
                    'code' => $payload['code'],
                    'action' => $payload['action'],
                    'uid' => $payload['person_uid']
                ]));
            } catch (\Exception $e) {
                $this->error("❌ Failed to save event: " . $e->getMessage());
                $this->error("Payload: " . json_encode($payload, JSON_PRETTY_PRINT));
            }
        } catch (\Throwable $e) {
            $this->error("❌ processEvent crashed: " . $e->getMessage());
            $this->error("Raw data: " . json_encode($data, JSON_PRETTY_PRINT));
        }
    }
}

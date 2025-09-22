<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Visitor;

class AddVisitorData extends Command
{
    protected $signature = 'visitor:import-data {file=storage/app/dahua_test_api_data.txt}';
    protected $description = 'Import old visitor events from IVS txt file';

    public function handle()
    {
        $filePath = base_path($this->argument('file'));

        if (!file_exists($filePath)) {
            $this->error("File tidak ditemukan: $filePath");
            return;
        }

        $content = file_get_contents($filePath);

        // Cari semua blok data={...}
        preg_match_all('/data=\{.*?\}\s*(?=\n--myboundary|$)/s', $content, $matches);

        $total = 0;
        foreach ($matches[0] as $block) {
            // Ambil JSON murni setelah "data="
            $jsonPart = trim(substr($block, 5));

            // Hapus koma terakhir sebelum tutup kurung
            $jsonPart = preg_replace('/,(\s*[}\]])/', '$1', $jsonPart);

            $data = json_decode($jsonPart, true);

            if (json_last_error() === JSON_ERROR_NONE && $data) {
                Visitor::create([
                    'event_code'     => $data['Code'] ?? null,
                    'event_id'       => $data['EventID'] ?? null,
                    'event_type'     => $data['EventType'] ?? null,
                    'group_id'       => $data['GroupID'] ?? null,
                    'sequence'       => $data['Sequence'] ?? null,
                    'locale_time'    => $data['LocaleTime'] ?? null,
                    'real_utc'       => $data['RealUTC'] ?? null,

                    'age'            => $data['Age'] ?? null,
                    'sex'            => $data['Sex'] ?? null,
                    'mask'           => $data['Mask'] ?? null,
                    'glass'          => $data['Glass'] ?? null,
                    'beard'          => $data['Beard'] ?? null,
                    'emotion'        => $data['Object']['Emotion'] ?? null,
                    'attractive'     => $data['Attractive'] ?? null,
                    'face_quality'   => $data['FaceQuality'] ?? null,
                    'bounding_box'   => isset($data['Object']['BoundingBox']) ? json_encode($data['Object']['BoundingBox']) : null,

                    'object_id'      => $data['Object']['ObjectID'] ?? null,
                    'object_type'    => $data['Object']['ObjectType'] ?? null,
                    'frame_sequence' => $data['Object']['FrameSequence'] ?? null,

                    'image_width'    => $data['Object']['Image']['Width'] ?? null,
                    'image_height'   => $data['Object']['Image']['Height'] ?? null,
                    'image_length'   => $data['Object']['Image']['Length'] ?? null,
                ]);

                $total++;
            } else {
                $this->warn("JSON error: " . json_last_error_msg());
            }
        }

        $this->info("Import selesai. Total data masuk: $total");
    }
}

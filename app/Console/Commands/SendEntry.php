<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use GuzzleHttp\Client;
use App\Models\VisitorDetection;
use Illuminate\Support\Facades\Log;

class SendInEntry extends Command
{
    protected $endpoint;

    public function __construct()
    {
        parent::__construct();
        $this->endpoint = env('MACHINE_LEARNING_ENDPOINT');
    }

    protected $signature = 'ml:send-entry
                                {--limit=10 : Jumlah data yang dikirim}
                                {--label=in : Label filter (default: in)}
                                {--order=desc : Urutan locale_time (asc|desc)}
                                {--endpoint=http://localhost:8000/entry : Endpoint tujuan}';

    protected $description = 'Kirim data VisitorDetection dengan label tertentu ke endpoint API dan update hasilnya.';

    public function handle()
    {
        $limit    = (int) $this->option('limit');
        $label    = $this->option('label');
        $order    = strtolower($this->option('order')) === 'asc' ? 'asc' : 'desc';
        $endpoint = $this->option('endpoint');

        $this->info("Mengambil data: label={$label}, order={$order}, limit={$limit}");

        $data = VisitorDetection::where('label', $label)
            ->orderBy('locale_time', $order)
            ->limit($limit)
            ->get();

        if ($data->isEmpty()) {
            $this->warn('⚠️ Tidak ada data ditemukan.');
            return;
        }

        $payload = [
            'data' => $data->map(function ($item) {
                $imageBase64 = null;
                if (!empty($item->person_pic_url)) {
                    try {
                        $path = str_replace(url('/storage'), storage_path('app/public'), $item->person_pic_url);
                        if (file_exists($path)) {
                            $imageBase64 = base64_encode(file_get_contents($path));
                        }
                    } catch (\Exception $e) {
                        $this->warn("Gagal encode gambar ID {$item->id}: " . $e->getMessage());
                    }
                }

                return [
                    'id'        => $item->id,
                    'rec_no'    => $item->rec_no,
                    'label'     => $item->label,
                    'timestamp' => $item->locale_time,
                    'gate'      => $item->gate_name ?? 'gate-in-A',
                    'image'     => $imageBase64,
                ];
            })->toArray(),
        ];

        try {
            $client = new Client(['verify' => false]);
            $response = $client->post($this->endpoint ?? $endpoint, [
                'headers' => ['Content-Type' => 'application/json'],
                'body'    => json_encode($payload),
            ]);

            $status = $response->getStatusCode();
            $body = (string) $response->getBody();

            if ($status < 200 || $status >= 300) {
                $this->error("❌ Gagal kirim data. Status: {$status}");
                return;
            }

            $this->info("✅ Data berhasil dikirim ke {$endpoint}");
            $this->line($body);

            // Decode response dari machine learning
            $responseData = json_decode($body, true);

            if (!is_array($responseData)) {
                $this->error('❌ Response dari server tidak valid JSON.');
                return;
            }

            // Proses update ke database
            foreach ($responseData as $res) {
                try {
                    $query = null;

                    if ($res['label'] === 'in') {
                        $query = VisitorDetection::where('rec_no', $res['rec_no']);
                    } elseif ($res['label'] === 'out' && !empty($res['rec_no_in'])) {
                        $query = VisitorDetection::where('rec_no', $res['rec_no_in']);
                    }

                    if ($query) {
                        $updated = $query->update([
                            'embedding_id'  => $res['embedding_id'] ?? null,
                            'status'        => $res['status'] ?? null,
                            'is_registered' => $res['is_registered'] ?? null,
                            'rec_no_in'     => $res['rec_no_in'] ?? null,
                        ]);

                        if ($updated) {
                            $this->info("✅ Data rec_no {$res['rec_no']} berhasil diupdate.");
                        } else {
                            $this->warn("⚠️ Data rec_no {$res['rec_no']} tidak ditemukan di DB.");
                        }
                    }
                } catch (\Exception $e) {
                    $this->error("❌ Gagal update rec_no {$res['rec_no']}: " . $e->getMessage());
                    Log::error($e);
                }
            }

            $this->info('🚀 Semua data telah diproses.');

        } catch (\Exception $e) {
            $this->error("❌ Error saat mengirim: " . $e->getMessage());
            Log::error($e);
        }
    }
}

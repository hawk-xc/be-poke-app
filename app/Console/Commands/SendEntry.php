<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use GuzzleHttp\Client;
use App\Models\VisitorDetection;
use Illuminate\Support\Facades\Log;

class SendEntry extends Command
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
            ->whereNotNull('person_pic_url')
            ->where('is_registered', false)
            ->where('person_pic_url', '<>', '')
            ->orderBy('locale_time', $order)
            ->limit($limit)
            ->get();

        if ($data->isEmpty()) {
            $this->warn("⚠️ Tidak ada data yang ditemukan.");
            return;
        }

        // Map dan encode gambar
        $payloadData = $data->map(function ($item) {
            $imageBase64 = null;

            if (!empty($item->person_pic_url)) {
                try {
                    $path = str_replace('/storage', storage_path('app/public'), $item->person_pic_url);

                    if (file_exists($path)) {
                        $imageBase64 = base64_encode(file_get_contents($path));
                    }
                } catch (\Exception $e) {
                    $this->warn("Gagal encode gambar ID {$item->id}: " . $e->getMessage());
                }
            }

            if ($imageBase64 === null) {
                // Skip item ini nanti
                return null;
            }

            return [
                'id'        => $item->id,
                'rec_no'    => $item->rec_no,
                'label'     => $item->label,
                'timestamp' => $item->locale_time,
                'gate'      => $item->gate_name ?? 'gate-in-A',
                'image'     => $imageBase64,
            ];
        })
            ->filter() // buang yang null (image kosong)
            ->values()
            ->toArray();

        if (empty($payloadData)) {
            $this->warn("⚠️ Tidak ada data yang memiliki gambar valid untuk dikirim.");
            return;
        }

        $payload = ['data' => $payloadData];

        try {
            $client = new Client(['verify' => false]);
            $response = $client->post($endpoint ?? $this->endpoint, [
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

            $responseData = json_decode($body, true);

            if (!is_array($responseData)) {
                return;
            }

            foreach ($responseData['data'] as $res) {
                try {
                    $label = $res['label'] ?? null;
                    $recNo = $res['rec_no'] ?? null;
                    $recNoIn = $res['rec_no_in'] ?? null;

                    if (empty($label) || empty($recNo)) {
                        $this->warn("⚠️ Data tidak valid: label atau rec_no kosong, id={$res['id']}");
                        continue;
                    }

                    if ($label === 'in') {
                        $updated = VisitorDetection::where('rec_no', $recNo)->update([
                            'embedding_id'  => $res['embedding_id'] ?? null,
                            'status'        => $res['status'] ?? null,
                            'is_registered' => $res['is_registered'] ?? null,
                        ]);

                        if ($updated) {
                            $this->info("✅ Data IN rec_no {$recNo} berhasil diupdate.");
                        } else {
                            $this->warn("⚠️ Data IN rec_no {$recNo} tidak ditemukan di DB.");
                        }
                    }

                    elseif ($label === 'out') {
                        if (empty($recNoIn)) {
                            $this->warn("⚠️ Lewati OUT id={$res['id']} karena rec_no_in kosong.");
                            continue;
                        }

                        $updatedIn = VisitorDetection::where('rec_no', $recNoIn)->update([
                            'embedding_id'  => $res['embedding_id'] ?? null,
                            'status'        => $res['status'] ? true : false,
                            'is_registered' => $res['is_registered'] ? true : false,
                        ]);

                        $updatedOut = VisitorDetection::where('rec_no', $recNo)->update([
                            'embedding_id'  => $res['embedding_id'] ?? null,
                            'status'        => $res['status'] ? true : false,
                            'is_registered' => $res['is_registered'] ? true : false,
                            'rec_no_in'     => $recNoIn,
                        ]);

                        if ($updatedIn || $updatedOut) {
                            $this->info("✅ Data OUT rec_no {$recNo} & rec_no_in {$recNoIn} berhasil diupdate.");
                        } else {
                            $this->warn("⚠️ Tidak ditemukan data OUT rec_no {$recNo} atau IN {$recNoIn} di DB.");
                        }
                    }
                } catch (\Exception $e) {
                    $this->error("❌ Gagal update data rec_no {$res['rec_no']} (label={$res['label']}): " . $e->getMessage());
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

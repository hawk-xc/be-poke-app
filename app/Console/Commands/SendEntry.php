<?php

namespace App\Console\Commands;

use App\Models\VisitorDetection;
use App\Models\VisitorQueue;
use Carbon\Carbon;
use GuzzleHttp\Client;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SendEntry extends Command
{
    protected $signature = 'ml:send-entry 
                            {--rec_no= : Nomor record yang akan dikirim} 
                            {--label=in : Label data (in/out)}';

    protected $description = 'Kirim satu data VisitorDetection ke ML API berdasarkan label.';

    public function handle()
    {
        $recNo = $this->option('rec_no');
        $label = $this->option('label');

        if (!$recNo) {
            $this->error('❌ Parameter --rec_no wajib diisi.');
            return;
        }

        $endpointBase = env('MACHINE_LEARNING_ENDPOINT');
        $endpoint = rtrim($endpointBase, '/') . ($label === 'out' ? '/exit' : '/entry');

        $data = VisitorDetection::where('rec_no', $recNo)->first();

        if (!$data) {
            $this->error("❌ Data VisitorDetection dengan rec_no={$recNo} tidak ditemukan.");
            return;
        }

        $imageBase64 = null;
        if (!empty($data->person_pic_url)) {
            try {
                $path = str_replace('/storage', storage_path('app/public'), $data->person_pic_url);

                if (file_exists($path)) {
                    $imageBase64 = base64_encode(file_get_contents($path));
                } else {
                    $this->warn("⚠️ Gambar tidak ditemukan: {$path}");
                }
            } catch (\Exception $e) {
                $this->warn("⚠️ Gagal encode gambar ID {$data->id}: " . $e->getMessage());
            }
        }

        if (!$imageBase64) {
            $this->warn("⚠️ Data rec_no={$recNo} tidak memiliki gambar valid.");
            return;
        }

        $payload = [
            'data' => [[
                'id'        => $data->id,
                'rec_no'    => $data->rec_no,
                'label'     => $data->label,
                'timestamp' => Carbon::parse($data->locale_time)->toIso8601String(),
                'gate'      => $data->gate_name ?? 'Gate-A',
                'image'     => $imageBase64,
            ]]
        ];

        $this->info("🚀 Mengirim data rec_no={$recNo} ke {$endpoint}");
        $this->line(json_encode($payload, JSON_PRETTY_PRINT));

        try {
            $client = new Client(['verify' => false]);
            $response = $client->post($endpoint, [
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

            if (isset($responseData['data']) && is_array($responseData['data'])) {
                foreach ($responseData['data'] as $res) {
                    $this->updateVisitor($res);
                }
            }

            VisitorQueue::where('rec_no', $recNo)->update(['status' => 'registered']);

        } catch (\Exception $e) {
            $this->error("❌ Error saat mengirim: " . $e->getMessage());
            Log::error($e);
        }
    }

    protected function updateVisitor($res)
    {
        $label = $res['label'] ?? null;
        $recNo = $res['rec_no'] ?? null;
        $recNoIn = $res['rec_no_in'] ?? null;

        if (empty($label) || empty($recNo)) {
            $this->warn("⚠️ Data tidak valid: label atau rec_no kosong.");
            return;
        }

        if ($label === 'in') {
            $updated = VisitorDetection::where('rec_no', $recNo)->update([
                'embedding_id'  => $res['embedding_id'] ?? null,
                'status'        => $res['status'] ?? false,
                'is_registered' => $res['is_registered'] ?? false,
            ]);

            $updated ? 
                $this->info("✅ Data IN rec_no={$recNo} berhasil diupdate.") :
                $this->warn("⚠️ Data IN rec_no={$recNo} tidak ditemukan.");
        }

        if ($label === 'out') {
            if (empty($recNoIn)) {
                $this->warn("⚠️ Lewati OUT rec_no={$recNo} karena rec_no_in kosong.");
                return;
            }

            VisitorDetection::where('rec_no', $recNo)->update([
                'embedding_id'  => $res['embedding_id'] ?? null,
                'status'        => $res['status'] ?? false,
                'is_registered' => $res['is_registered'] ?? false,
                'rec_no_in'     => $recNoIn,
            ]);

            $this->info("✅ Data OUT rec_no={$recNo} dan IN={$recNoIn} berhasil diupdate.");
        }
    }
}

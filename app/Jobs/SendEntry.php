<?php

namespace App\Jobs;

use App\Models\VisitorDetection;
use App\Models\VisitorQueue;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;

class SendEntry implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $visitorId;

    public function __construct($visitorId)
    {
        $this->visitorId = $visitorId;
    }

    public function handle()
    {
        try {
            $data = VisitorDetection::find($this->visitorId);

            if (!$data) {
                Log::warning("⚠️ VisitorDetection ID {$this->visitorId} tidak ditemukan.");
                return;
            }

            // Simpan ke tabel visitor_queues
            $queue = VisitorQueue::create([
                'rec_no' => $data->rec_no,
                'label'  => $data->label,
                'status' => 'pending',
            ]);

            dump($queue);

            // Jalankan command untuk kirim data
            Artisan::call('ml:send-entry', [
                '--rec_no' => $data->rec_no,
                '--label'  => $data->label,
            ]);

            Log::info("📤 Job SendEntry sukses dijalankan untuk rec_no={$data->rec_no}, label={$data->label}");
        } catch (\Exception $e) {
            Log::error("❌ Error di job SendEntry: " . $e->getMessage());
        }
    }
}

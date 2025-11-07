<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\VisitorDetection;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class PairVisitorDetections extends Command
{
    protected $signature = 'visitor:pair';
    protected $description = 'Pair visitor detections (in & out) between 2025-10-01 and 2025-10-25, assign unique embedding_id (UUID), person_id (from in.id), and calculate duration.';

    public function handle()
    {
        $this->info('⏳ Starting pairing process between 2025-10-01 and 2025-10-25...');

        DB::beginTransaction();

        try {
            $ins = VisitorDetection::where('label', 'in')
                ->whereBetween(DB::raw('DATE(created_at)'), ['2025-10-01', '2025-10-30'])
                ->orderBy('created_at', 'asc')
                ->limit(50)
                ->get();

            if ($ins->isEmpty()) {
                $this->warn('⚠️ Tidak ditemukan data label=in di rentang tanggal tersebut.');
                return Command::SUCCESS;
            }

            $this->info("🔎 Menemukan {$ins->count()} data 'in'. Mencari pasangan 'out' untuk masing-masing...");

            $paired = 0;
            foreach ($ins as $in) {
                $uuid = (string) Str::uuid();

                // cari data out berdasarkan waktu & face_object_id
                $out = VisitorDetection::where('label', 'out')
                    ->where(function ($q) use ($in) {
                        if (!is_null($in->face_object_id)) {
                            $q->where('face_object_id', $in->face_object_id);
                        }
                    })
                    ->where('created_at', '>=', $in->created_at)
                    ->orderBy('created_at', 'asc')
                    ->first();

                // fallback jika tidak ditemukan
                if (!$out) {
                    $out = VisitorDetection::where('label', 'out')
                        ->where('created_at', '>=', $in->created_at)
                        ->orderBy('created_at', 'asc')
                        ->first();
                }

                if (!$out) {
                    $this->line("❌ Tidak ditemukan OUT untuk IN id={$in->id} (skip).");
                    continue;
                }

                // Hitung durasi dalam detik
                $duration = Carbon::parse($in->created_at)->diffInSeconds(Carbon::parse($out->created_at));

                // Assign ID dan durasi
                $in->embedding_id = $uuid;
                $out->embedding_id = $uuid;

                $in->person_id = $in->id;
                $out->person_id = $in->id;

                $in->duration = 0; // untuk IN biasanya 0
                $out->duration = $duration; // simpan durasi pada OUT

                if (in_array('person_uid', (new VisitorDetection)->getFillable())) {
                    $in->person_uid = $uuid;
                    $out->person_uid = $uuid;
                }

                $in->save();
                $out->save();

                $paired++;
                $this->line("✅ Paired IN id={$in->id} ↔ OUT id={$out->id} | duration={$duration}s | embedding_id={$uuid}");
            }

            DB::commit();

            $this->info("🎉 Pairing selesai! Total paired: {$paired}");
        } catch (\Throwable $e) {
            DB::rollBack();
            $this->error('❌ Error: ' . $e->getMessage());
        }

        return Command::SUCCESS;
    }
}

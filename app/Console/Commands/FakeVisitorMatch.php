<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\VisitorDetection;
use Carbon\Carbon;

class FakeVisitorMatch extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'ml:fake-visitor-match';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Pasangkan data visitor in dan out secara otomatis (fake matcher)';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Mulai memproses fake visitor match...');

        // Ambil semua data IN yang belum match
        $inVisitors = VisitorDetection::where('label', 'in')
            ->where('is_matched', false)
            ->orderBy('locale_time', 'asc')
            ->get();

        $countMatched = 0;

        foreach ($inVisitors as $in) {
            // Hitung range waktu 1 - 3 jam setelah data IN
            $minTime = Carbon::parse($in->locale_time)->addHour(1);
            $maxTime = Carbon::parse($in->locale_time)->addHours(3);

            // Cari OUT yang belum match dan waktunya dalam range
            $out = VisitorDetection::where('label', 'out')
                ->where('is_matched', false)
                ->whereBetween('locale_time', [$minTime, $maxTime])
                ->orderBy('locale_time', 'asc')
                ->first();

            // Jika ditemukan pasangan
            if ($out) {
                $inTime = Carbon::parse($in->locale_time);
                $outTime = Carbon::parse($out->locale_time);

                // Hitung durasi dalam menit (bisa diubah ke jam jika mau)
                $durationMinutes = $inTime->diffInMinutes($outTime);

                $out->update([
                    'rec_no_in'     => $in->rec_no,
                    'is_registered' => true,
                    'is_matched'    => true,
                    'duration'      => $durationMinutes,
                ]);

                $in->update([
                    'is_registered' => true,
                    'is_matched'    => true,
                ]);

                $countMatched++;

                $this->info("Matched: IN #{$in->id} <-> OUT #{$out->id} | Durasi: {$durationMinutes} menit");
            }
        }

        $this->info("Selesai! Total pasangan ditemukan: {$countMatched}");
    }
}

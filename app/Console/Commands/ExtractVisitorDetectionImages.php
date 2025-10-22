<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use App\Models\VisitorDetection;

class ExtractVisitorDetectionImages extends Command
{
    /**
     * The name and signature of the console command.
     *
     * Contoh pemakaian:
     * php artisan extract:visitor-detection            → default tanggal 2025-10-11
     * php artisan extract:visitor-detection 2025-10-12 → tanggal spesifik
     */
    protected $signature = 'extract:visitor-detection {date?}';

    /**
     * The console command description.
     */
    protected $description = 'Ekstrak 100 data VisitorDetection berdasarkan tanggal dan salin gambar ke folder in/out sesuai label.';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $date = $this->argument('date') ?? '2025-10-11';
        $this->info("Menjalankan ekstraksi data untuk tanggal: {$date}");

        // Ambil 100 data berdasarkan tanggal created_at
        $records = VisitorDetection::whereDate('created_at', $date)
            ->limit(100)
            ->get();

        if ($records->isEmpty()) {
            $this->warn("Tidak ada data yang ditemukan pada tanggal {$date}.");
            return Command::SUCCESS;
        }

        $this->info("Ditemukan {$records->count()} data, mulai menyalin gambar...");

        foreach ($records as $record) {
            $relativePath = str_replace('/storage/', '', $record->person_pic_url);
            $sourcePath = 'public/' . $relativePath;

            $label = strtolower(trim($record->label ?? 'unknown'));
            $destinationDir = "public/faceDetection/{$label}/";

            if (!Storage::exists($destinationDir)) {
                Storage::makeDirectory($destinationDir);
            }

            $fileName = basename($record->person_pic_url);
            $destinationPath = $destinationDir . $fileName;

            if (Storage::exists($sourcePath)) {
                Storage::copy($sourcePath, $destinationPath);
                $this->line("✅ {$fileName} → {$label}/");
            } else {
                $this->warn("⚠️ File tidak ditemukan: {$sourcePath}");
            }
        }

        $this->info("Selesai! Semua gambar telah disalin sesuai label.");
        return Command::SUCCESS;
    }
}

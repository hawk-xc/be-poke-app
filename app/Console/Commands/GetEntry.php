<?php

namespace App\Console\Commands;

use App\Models\VisitorDetection;
use Carbon\Carbon;
use Exception;
use GuzzleHttp\Client;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class GetEntry extends Command
{
    protected $signature = 'ml:get-entry';
    protected $description = 'Get visitor match result from ML and update VisitorDetection records.';

    public function handle()
    {
        $endpointBase = env('MACHINE_LEARNING_ENDPOINT');
        $endpoint = $endpointBase . '/results/all?status=matched&limit=100';

        try {
            $client = new Client(['verify' => false]);
            $response = $client->get($endpoint, [
                'headers' => ['Content-Type' => 'application/json'],
                'timeout' => 20,
            ]);

            $data = json_decode($response->getBody()->getContents(), true);

            if (!isset($data['data']) || !is_array($data['data'])) {
                $this->error('⚠️ Invalid response format.');
                return;
            }

            $updatedCount = 0;
            $failedCount = 0;

            foreach ($data['data'] as $item) {
                $recNoOut = $item['rec_no'] ?? null;
                $recNoIn  = $item['rec_no_in'] ?? null;
                $embeddingId = $item['embedding_id'] ?? null;

                if (!$recNoOut || !$recNoIn) {
                    Log::warning('⚠️ Missing rec_no or rec_no_in', $item);
                    $failedCount++;
                    continue;
                }

                // Ambil data "in" dan "out"
                $inRecord = VisitorDetection::where('rec_no', $recNoIn)
                    ->where('is_matched', false)
                    ->where('label', 'in')
                    ->first();

                $outRecord = VisitorDetection::where('rec_no', $recNoOut)
                    ->where('is_matched', false)
                    ->where('label', 'out')
                    ->first();

                $recordStatus = true;

                // Jika salah satu tidak ditemukan, set status = false
                if (!$inRecord || !$outRecord) {
                    Log::warning("⚠️ Record missing: in=$recNoIn, out=$recNoOut");

                    $recordStatus = false;

                    $failedCount++;
                    continue;
                }

                // duration (menit)
                $inTime = Carbon::parse($inRecord->locale_time);
                $outTime = Carbon::parse($outRecord->locale_time);
                $duration = $inTime->diffInMinutes($outTime);

                // Update data "out"
                $outRecord->update([
                    'embedding_id' => $embeddingId,
                    'is_matched' => true,
                    'status' => $recordStatus ?? false,
                    'duration' => $duration,
                ]);

                // Update data "in"
                $inRecord->update([
                    'is_matched' => true,
                    'status' => $recordStatus ?? false,
                ]);

                $updatedCount++;
            }

            $this->info("✅ Successfully updated {$updatedCount} records.");
            $this->info("⚠️ {$failedCount} records failed due to missing in/out pair.");
        } catch (Exception $e) {
            Log::error('❌ Error in ml:get-entry: ' . $e->getMessage());
            $this->error('❌ ' . $e->getMessage());
        }
    }
}

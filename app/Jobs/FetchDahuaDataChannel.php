<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use App\Jobs\SendEntry;
use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use App\Models\VisitorDetection;

class FetchDahuaDataChannel implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected int $channel;
    protected string $label;
    protected string $gate_name = 'Gate-In-1';
    protected string $startTime;
    protected string $endTime;

    /**
     * Create a new job instance.
     */
    public function __construct(int $channel = 1, ?string $label = 'in', ?string $gate_name = 'Gate-In-1', ?string $startTime = null, ?string $endTime = null)
    {
        date_default_timezone_set('Asia/Jakarta');

        $this->channel   = $channel;
        $this->label     = $label;
        $this->gate_name = $gate_name;
        $this->startTime = $startTime ?? date('Y-m-d H:i:s', strtotime('-15 minutes'));
        $this->endTime   = $endTime   ?? date('Y-m-d H:i:s', strtotime('-2 minutes'));
    }

    /**
     * Execute the console command.
     */
    public function handle(): void
    {
        $endpoint = rtrim(env('DAHUA_API_ENDPOINT'), '/');
        $username = env('DAHUA_DIGEST_USERNAME');
        $password = env('DAHUA_DIGEST_PASSWORD');

        $client = new Client([
            'base_uri' => $endpoint,
            'timeout' => 30,
            'verify' => false,
        ]);
        $jar = new CookieJar();

        $objId = null;

        try {
            // STEP 1: Create finder
            $res = $client->request('GET', '/cgi-bin/mediaFileFind.cgi', [
                'auth' => [$username, $password, 'digest'],
                'cookies' => $jar,
                'http_errors' => false,
                'query' => ['action' => 'factory.create'],
            ]);

            $body = trim((string) $res->getBody());
            if (!str_starts_with($body, 'result=')) {
                Log::error("Failed to create finder: {$body}");
                return;
            }

            $objId = str_replace('result=', '', $body);
            Log::info("Finder created: {$objId}");
            Log::info("Fetching data from {$this->startTime} to {$this->endTime}...");

            // STEP 2: Set conditions
            $client->request('GET', '/cgi-bin/mediaFileFind.cgi', [
                'auth' => [$username, $password, 'digest'],
                'cookies' => $jar,
                'http_errors' => false,
                'query' => [
                    'action' => 'findFile',
                    'object' => $objId,
                    'condition.Channel' => $this->channel,
                    'condition.StartTime' => $this->startTime,
                    'condition.EndTime' => $this->endTime,
                    'condition.Types[0]' => 'jpg',
                    'condition.Flags[0]' => 'Event',
                    'condition.Events[0]' => 'FaceDetection',
                    'condition.DB.FaceDetectionRecordFilter.ImageType' => 'GlobalSence'
                ],
            ]);

            // STEP 3: Fetch results with pagination
            $allItems = [];
            $totalReported = 0;
            $page = 1;
            $batchCount = 100;
            $maxPages = 1000;

            do {
                if ($page > $maxPages) {
                    Log::warning("Reached max pages ({$maxPages}), stopping to avoid infinite loop.");
                    break;
                }

                $resMediaFile = $client->request('GET', '/cgi-bin/mediaFileFind.cgi', [
                    'auth' => [$username, $password, 'digest'],
                    'cookies' => $jar,
                    'http_errors' => false,
                    'query' => [
                        'action' => 'findNextFile',
                        'object' => $objId,
                        'count' => $batchCount,
                    ],
                ]);

                $raw = (string) $resMediaFile->getBody();

                $filename = storage_path('logs/dahua_keepalive.log');
                file_put_contents($filename, "=== PAGE {$page} ===\n" . $raw . PHP_EOL, FILE_APPEND | LOCK_EX);

                $parsed = parseFeceDetectionData($raw, $this->  channel, $this->label, $this->gate_name);

                $lastCount = count($parsed['items']);
                Log::info("Page {$page}: API reported found={$parsed['found']} | parsed items={$lastCount}");

                $totalReported += $parsed['found'];
                if (!empty($parsed['items'])) {
                    $allItems = array_merge($allItems, $parsed['items']);
                }

                $page++;
            } while ($lastCount === $batchCount);

            Log::info("Total API reported found sum: {$totalReported}");
            Log::info("Total Parsed Items collected: " . count($allItems));

            // STEP 3b: Save to DB
            $skipped_no_rec_no = $skipped_duplicate = $saved_count = $error_count = 0;

            foreach ($allItems as $index => $item) {
                if (empty($item['rec_no'])) {
                    Log::warning("Item index {$index} has empty rec_no, skipping");
                    $skipped_no_rec_no++;
                    continue;
                }

                if (VisitorDetection::where('rec_no', $item['rec_no'])->exists()) {
                    $skipped_duplicate++;
                    continue;
                }

                try {
                    VisitorDetection::create($item);

                    // dispatch to send ML Api
                    // SendEntry::dispatch($visitor_data);
                    // SendEntry::dispatchSync($visitor_data->id);

                    $saved_count++;
                    Log::info("Saved Visitor RecNo={$item['rec_no']}");
                } catch (\Exception $e) {
                    $error_count++;
                    Log::error("Failed to save RecNo={$item['rec_no']}: " . $e->getMessage());
                    Log::error("Item structure: " . json_encode($item, JSON_PRETTY_PRINT));
                }
            }

            Log::info("=== SUMMARY ===");
            Log::info("Saved: {$saved_count}");
            Log::info("Skipped (no rec_no): {$skipped_no_rec_no}");
            Log::info("Skipped (duplicate): {$skipped_duplicate}");
            Log::info("Errors: {$error_count}");
        } catch (\Exception $e) {
            Log::error("Fetch error: " . $e->getMessage());
        } finally {
            if (!empty($objId)) {
                try {
                    $client->request('GET', '/cgi-bin/mediaFileFind.cgi', [
                        'auth' => [$username, $password, 'digest'],
                        'cookies' => $jar,
                        'http_errors' => false,
                        'query' => ['action' => 'close', 'object' => $objId],
                    ]);
                    Log::info("Finder destroyed: {$objId}");
                } catch (\Exception $e) {
                    Log::warning("Failed to destroy finder {$objId}: " . $e->getMessage());
                }
            }
        }
    }
}

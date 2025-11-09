<?php

namespace App\Console\Commands;

use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use Illuminate\Support\Facades\Log;
use App\Jobs\FetchDahuaDataChannel;

use Illuminate\Console\Command;

class DahuaEventListenerChannel extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'dahua:face-detection-event-listener';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Listen to Dahua IVS Event Manager via keep-alive request';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Starting Dahua IVS event listener...');

        $endpoint = rtrim(env('DAHUA_API_ENDPOINT'), '/');
        $url = $endpoint . '/cgi-bin/eventManager.cgi?action=attach&codes=[All]';
        $username = env('DAHUA_DIGEST_USERNAME');
        $password = env('DAHUA_DIGEST_PASSWORD');

        while (true) {
            try {
                $this->info("Connecting to $url");

                $ch = curl_init();
                curl_setopt($ch, CURLOPT_URL, $url);
                curl_setopt($ch, CURLOPT_USERPWD, "$username:$password");
                curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_DIGEST);
                curl_setopt($ch, CURLOPT_TIMEOUT, 0);
                curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

                curl_setopt($ch, CURLOPT_WRITEFUNCTION, function ($ch, $data) use ($endpoint) {
                    $event = trim($data);

                    if (!empty($event)) {
                        try {
                            FetchDahuaDataChannel::dispatch(1, 'in', 'Gate-In-A');
                            FetchDahuaDataChannel::dispatch(2, 'in', 'Gate-In-B');
                            FetchDahuaDataChannel::dispatch(3, 'in', 'Gate-In-C');
                            FetchDahuaDataChannel::dispatch(4, 'in', 'Gate-In-D');
                            FetchDahuaDataChannel::dispatch(5, 'in', 'Gate-In-E');
                            FetchDahuaDataChannel::dispatch(6, 'in', 'Gate-In-F');
                            FetchDahuaDataChannel::dispatch(7, 'out', 'Gate-Out-A');
                            FetchDahuaDataChannel::dispatch(8, 'in', 'Gate-In-G');

                            echo "Fetch command executed at " . now() . "\n";
                        } catch (\Exception $e) {
                            Log::error("Job dispatch error: " . $e->getMessage());
                        }
                    }

                    return strlen($data);
                });

                $this->info('Listening... press CTRL+C to stop');
                curl_exec($ch);

                if (curl_errno($ch)) {
                    $this->error('cURL error: ' . curl_error($ch));
                    Log::error('cURL error: ' . curl_error($ch));
                }

                curl_close($ch);
            } catch (\Exception $e) {
                Log::error('Request error: ' . $e->getMessage());
                $this->error('Request error: ' . $e->getMessage());
            }

            $this->warn("Reconnecting to Dahua event stream in 5 seconds...");
            sleep(5);
        }
    }
}

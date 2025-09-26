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
        $url = $endpoint . '/eventManager.cgi?action=attach&codes=[All]';
        $username = env('DAHUA_DIGEST_USERNAME');
        $password = env('DAHUA_DIGEST_PASSWORD');

        $ch = curl_init();

        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_USERPWD, "$username:$password");
        curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_DIGEST);
        curl_setopt($ch, CURLOPT_WRITEFUNCTION, function ($ch, $data) use ($endpoint) {
            $event = trim($data);

            if (!empty($event)) {
                $client = new Client([
                    'base_uri' => $endpoint,
                    'timeout' => 15,
                    'verify' => false,
                ]);
                $jar = new CookieJar();

                try {
                    // run the job
                    FetchDahuaDataChannel::dispatch(1, 'out');
                    FetchDahuaDataChannel::dispatch(2, 'in');
                    FetchDahuaDataChannel::dispatch(3, 'in');
                    echo "Fetch command executed\n";
                } catch (\Exception $e) {
                    Log::error("Keep-alive error: " . $e->getMessage());
                }
            }

            return strlen($data); // keep connection alive
        });

        curl_setopt($ch, CURLOPT_TIMEOUT, 0);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 0);

        $this->info('Listening... press CTRL+C to stop');
        curl_exec($ch);

        if (curl_errno($ch)) {
            $this->error('cURL error: ' . curl_error($ch));
        }

        curl_close($ch);
    }
}

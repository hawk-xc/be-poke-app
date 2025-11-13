<?php

namespace App\Console\Commands;

use Exception;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use GuzzleHttp\Client;

class GetFaceSetToken extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'faceplus:get-faceset-token';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Get or create a FaceSet token from Face++ API';

    protected string $faceplus_url;
    protected string $apikey;
    protected string $apisecret;
    protected ?string $faceset_token;

    public function __construct()
    {
        parent::__construct();

        $this->faceplus_url = rtrim(env('FACEPLUSPLUS_URL'), '/') . '/faceset/create';
        $this->apikey = env('FACEPLUSPLUS_API_KEY', '');
        $this->apisecret = env('FACEPLUSPLUS_SECRET_KEY', '');
        $this->faceset_token = env('FACEPLUSPLUS_FACESET_TOKEN', null);
    }

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('=== Starting Face++ FaceSet Token Command ===');

        // Validasi awal env
        if (empty($this->apikey) || empty($this->apisecret)) {
            $this->error('Missing FACEPLUSPLUS_API_KEY or FACEPLUSPLUS_SECRET_KEY in .env');
            return 1;
        }

        $client = new Client([
            'timeout' => 20,
            'verify' => false,
        ]);

        try {
            if (empty($this->faceset_token)) {
                $this->info('No existing faceset_token found. Requesting new one...');

                $response = $client->post($this->faceplus_url, [
                    'form_params' => [
                        'api_key' => $this->apikey,
                        'api_secret' => $this->apisecret,
                        'display_name' => 'faceset_db',
                        'outer_id' => 'faceset_db',
                    ],
                    'http_errors' => false,
                ]);

                $status = $response->getStatusCode();
                $body = (string) $response->getBody();

                // Simpan untuk debugging
                Storage::put('faceplus_response.log', "HTTP {$status}\n{$body}");

                $data = json_decode($body, true);

                if ($status !== 200) {
                    $this->error("Face++ API returned HTTP {$status}");
                    $this->error("Response: " . $body);
                    Log::error("Face++ API Error: " . $body);
                    return 1;
                }

                if (!isset($data['faceset_token'])) {
                    $this->error('Response does not contain faceset_token field');
                    $this->error('Full response: ' . json_encode($data));
                    return 1;
                }

                $this->faceset_token = $data['faceset_token'];
                $this->info("✅ New FaceSet Token created: {$this->faceset_token}");
            } else {
                $this->info("Existing FaceSet Token found: {$this->faceset_token}");
            }
        } catch (Exception $err) {
            $this->error('❌ Error: ' . $err->getMessage());
            Log::error('FacePlus Command Error: ' . $err->getMessage());
            return 1;
        }

        $this->info('=== Command Finished Successfully ===');
        return 0;
    }
}

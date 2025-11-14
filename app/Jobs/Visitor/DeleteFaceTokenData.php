<?php

namespace App\Console\Commands;

use Exception;
use GuzzleHttp\Client;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class DeleteFaceTokenData extends Command
{
    protected $signature = 'visitor:delete-face-tokens';
    protected $description = 'Remove all face tokens from the Face++ FaceSet';

    protected string $apikey;
    protected string $apisecret;
    protected string $faceset_token;
    protected string $faceplus_removeface_url;

    public function __construct()
    {
        parent::__construct();

        $baseUrl = rtrim(env('FACEPLUSPLUS_URL'), '/');

        $this->faceplus_removeface_url = $baseUrl . '/faceset/removeface';
        $this->apikey        = env('FACEPLUSPLUS_API_KEY');
        $this->apisecret     = env('FACEPLUSPLUS_SECRET_KEY');
        $this->faceset_token = env('FACEPLUSPLUS_FACESET_TOKEN');
    }

    public function handle()
    {
        $this->info("=== [DeleteFaceTokenData] Clearing FaceSet... ===");

        $client = new Client([
            'timeout' => 30,
            'verify'  => false,
        ]);

        try {

            $this->info("Sending request to remove all tokens...");

            $response = $client->post($this->faceplus_removeface_url, [
                'form_params' => [
                    'api_key'       => $this->apikey,
                    'api_secret'    => $this->apisecret,
                    'faceset_token' => $this->faceset_token,
                    'face_tokens'   => 'RemoveAllFaceTokens',
                ],
                'http_errors' => false,
            ]);

            $status = $response->getStatusCode();
            $body   = (string) $response->getBody();
            $data   = json_decode($body, true);

            $this->info("HTTP Status: {$status}");
            $this->info("Response: " . $body);

            if ($status !== 200) {
                $this->error("Failed to delete tokens. HTTP {$status}");
                return 1;
            }

            if (isset($data['failure_detail'])) {
                Log::warning('Partial failure while removing face tokens', $data['failure_detail']);
                $this->warn("Partial failure reported. Check log file.");
            }

            $this->info("FaceSet tokens removed", [
                'faceset_token' => $data['faceset_token'] ?? null,
                'face_removed'  => $data['face_removed'] ?? 0,
            ]);

            $this->info("All face tokens removed successfully.");

        } catch (Exception $e) {

            Log::error("Remove FaceToken ERROR: " . $e->getMessage());
            $this->error("Error: " . $e->getMessage());

            return 1;
        }

        $this->info("=== [DeleteFaceTokenData] Finished ===");

        return 0;
    }
}

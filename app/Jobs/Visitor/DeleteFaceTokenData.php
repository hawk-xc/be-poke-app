<?php

namespace App\Jobs\Visitor;

use Exception;
use GuzzleHttp\Client;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class DeleteFaceTokenData implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $apikey;
    protected $apisecret;
    protected $faceset_token;
    protected $faceplus_removeface_url;

    /**
     * Create a new job instance.
     */
    public function __construct()
    {
        $this->faceplus_removeface_url = env('FACEPLUSPLUS_URL') . '/faceset/removeface';
        $this->apikey = env('FACEPLUSPLUS_API_KEY');
        $this->apisecret = env('FACEPLUSPLUS_SECRET_KEY');
        $this->faceset_token = env('FACEPLUSPLUS_FACESET_TOKEN');
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $client = new Client(['timeout' => 30, 'verify' => false]);

        try {
            Log::info('Attempting to remove all face tokens from FaceSet...');

            $response = $client->post($this->faceplus_removeface_url, [
                'form_params' => [
                    'api_key'       => $this->apikey,
                    'api_secret'    => $this->apisecret,
                    'faceset_token' => $this->faceset_token,
                    'face_tokens'   => 'RemoveAllFaceTokens',
                ],
            ]);

            $data = json_decode($response->getBody()->getContents(), true);

            if (isset($data['failure_detail'])) {
                Log::warning('Face++ removeface partial failure.', $data['failure_detail']);
            }

            Log::info("Successfully sent request to remove all face tokens. FaceSet updated.", [
                'faceset_token' => $data['faceset_token'] ?? null,
                'face_removed' => $data['face_count'] ?? 0,
            ]);

        } catch (Exception $err) {
            Log::error('Error while trying to remove all face tokens from FaceSet: ' . $err->getMessage());
        }
    }
}
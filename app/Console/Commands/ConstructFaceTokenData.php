<?php

namespace App\Console\Commands;

use Exception;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ConstructFaceTokenData extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'visitor:construct-face-token-data';

    protected $apikey;
    protected $apisecret;
    protected $faceset_token;
    protected $faceplus_remove_url;

    public function __construct()
    {
        $this->faceplus_remove_url = env('FACEPLUSPLUS_URL') . "/faceset/removeface";
        $this->apikey = env('FACEPLUSPLUS_API_KEY');
        $this->apisecret = env('FACEPLUSPLUS_SECRET_KEY');
        $this->faceset_token = env('FACEPLUSPLUS_FACESET_TOKEN');
        parent::__construct();
    }

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Construct Face Token data for Visitor Detection';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $client = new \GuzzleHttp\Client();

        try {
            $response = curlUrlencoded($this->faceplus_remove_url, [
                'api_key' => $this->apikey,
                'api_secret' => $this->apisecret,
                'faceset_token' => $this->faceset_token,
                'face_tokens' => 'RemoveAllFaceTokens',
            ]);

            $message = "
🔵 <b>[FaceSet DB]</b>
Face Token data was successfully removed from Faceset

<code>Date: " . now() . "</code>";
            
            sendTelegram($message);
            $this->info("Face token data was successfully removed from Faceset");
        } catch (Exception $err) {
            $message = "
🔴 <b>[FaceSet DB]</b>
Face Token data was failed to be removed from Faceset

<code>Date: " . now() . "</code>";

            sendTelegram($message);
            Log::info('Construct Error : ' . $err->getMessage());
        }
    }
}

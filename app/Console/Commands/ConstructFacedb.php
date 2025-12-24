<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class ConstructFacedb extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'visitor:construct-facedb';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Construct facedb from db';

    protected string $api_url;
    protected int $sleepRequestTime = 1;

    public function __construct()
    {
        parent::__construct();

        $this->api_url = env('INSIGHTFACE_API_URL') . '/clear-all?confirm=yes';
    }

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $exit_response = curlMultipart(
                    $this->api_exit_url . '?similarity_method=compreface',
                    [
                        'image' => new \CURLFile(
                            $tempFile,
                            mime_content_type($tempFile),
                            basename($tempFile)
                        ),
                    ]
                );
    }
}

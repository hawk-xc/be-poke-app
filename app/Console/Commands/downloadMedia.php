<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Exception\RequestException;

class downloadMedia extends Command
{
    protected $signature = 'dahua:download-media {filePath} {fileName}';
    protected $description = 'Download 1 file dari Dahua DVR dengan nama file custom';

    public function handle()
    {
        $endpoint = rtrim('http://36.94.79.178:7980', '/');
        $username = env('DAHUA_DIGEST_USERNAME', 'admin');
        $password = env('DAHUA_DIGEST_PASSWORD', 'admin123');

        $filePath = $this->argument('filePath');   // contoh: picid/39280761.jpg atau /picid/39280761.jpg
        $fileName = $this->argument('fileName');   // contoh: 4.jpg

        $savePath = storage_path('app/public/dahua_files/' . $fileName);
        if (!file_exists(dirname($savePath))) {
            mkdir(dirname($savePath), 0775, true);
        }

        $jar = new CookieJar();

        $client = new Client([
            'base_uri' => $endpoint,
            'verify' => false,
            'cookies' => $jar,
            'timeout' => 60,
            // paksa HTTP/1.1 dan fresh connect
            'curl' => [
                CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                CURLOPT_FORBID_REUSE => true,
                CURLOPT_FRESH_CONNECT => true,
            ],
            'headers' => [
                'User-Agent' => 'curl/7.85.0',
                'Connection' => 'close',
                'Accept' => '*/*',
            ],
            'http_errors' => false,
        ]);

        $url = '/cgi-bin/RPC_Loadfile/' . ltrim($filePath, '/');
        $this->info("Downloading: {$endpoint}{$url}");

        // 1) coba request tanpa auth dulu untuk dapat challenge (WWW-Authenticate)
        try {
            $first = $client->get($url, [
                'http_errors' => false,
            ]);
            $this->info("First request status: " . $first->getStatusCode());
        } catch (\Exception $e) {
            $this->warn("First request error (ignored): " . $e->getMessage());
        }

        // 2) request sebenarnya dengan digest auth (pakai same CookieJar)
        try {
            $res = $client->get($url, [
                'auth' => [$username, $password, 'digest'],
                'sink' => $savePath,
                // jangan throw, supaya kita bisa baca status / body
                'http_errors' => false,
                // nonaktifkan redirects (Dahua kadang redirect ke kosong)
                'allow_redirects' => false,
            ]);

            $status = $res->getStatusCode();
            $this->info("Download request returned HTTP {$status}");

            if ($status === 200) {
                $this->info("Download sukses → {$savePath}");
                $this->info("Akses di web Laravel: /storage/dahua_files/{$fileName}");
            } elseif ($status === 401) {
                $this->error("Unauthorized (401). Cek username/password dan realm. Response body: " . $this->shortBody($res));
            } else {
                $this->error("Gagal: HTTP {$status}. Body: " . $this->shortBody($res));
            }
        } catch (RequestException $e) {
            $this->error("RequestException: " . $e->getMessage());

            // tampilkan handler context dari curl (berguna untuk error cURL 52)
            if (method_exists($e, 'getHandlerContext')) {
                $ctx = $e->getHandlerContext();
                $this->error('Handler context: ' . json_encode($ctx));
            }
        } catch (\Exception $e) {
            $this->error("Error during download: " . $e->getMessage());
        }

        return 0;
    }

    private function shortBody($res, $max = 500)
    {
        try {
            $body = (string)$res->getBody();
            return strlen($body) > $max ? substr($body, 0, $max) . '...' : $body;
        } catch (\Exception $e) {
            return '[unable to read body]';
        }
    }
}

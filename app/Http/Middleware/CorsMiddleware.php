<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;

class CorsMiddleware
{
    public function handle(Request $request, Closure $next)
    {
        $origin = $request->headers->get('Origin');
        $allowedOrigins = Config::get('cors.allowed_origins', []);

        // Tentukan apakah origin diizinkan
        $allowOrigin = null;
        foreach ($allowedOrigins as $allowed) {
            if ($allowed === '*' || strcasecmp($allowed, $origin) === 0) {
                $allowOrigin = $origin;
                break;
            }
        }

        // Kalau ini adalah preflight OPTIONS request, langsung balas
        if ($request->getMethod() === 'OPTIONS') {
            $headers = [
                'Access-Control-Allow-Origin' => $allowOrigin ?? $origin ?? '*',
                'Access-Control-Allow-Methods' => 'GET, POST, PUT, PATCH, DELETE, OPTIONS',
                'Access-Control-Allow-Headers' => 'Content-Type, Authorization, X-Requested-With',
                'Access-Control-Allow-Credentials' => 'true',
            ];

            return response()->noContent(204)->withHeaders($headers);
        }

        // Untuk request normal
        $response = $next($request);

        $response->headers->set('Access-Control-Allow-Origin', $allowOrigin ?? $origin ?? '*');
        $response->headers->set('Access-Control-Allow-Methods', 'GET, POST, PUT, PATCH, DELETE, OPTIONS');
        $response->headers->set('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-Requested-With');
        $response->headers->set('Access-Control-Allow-Credentials', 'true');

        return $response;
    }
}


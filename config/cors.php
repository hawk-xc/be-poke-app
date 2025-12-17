<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may configure your settings for cross-origin resource sharing
    | or "CORS". This determines what cross-origin operations may execute
    | in web browsers. You are free to adjust these settings as needed.
    |
    | To learn more: https://developer.mozilla.org/en-US/docs/Web/HTTP/CORS
    |
    */

    // 'paths' => ['*', 'sanctum/csrf-cookie'],
    'paths' => ['*'],

    'allowed_methods' => ['*'],

    'allowed_origins' => [
	'*',
        'http://36.94.79.204:24443',
	'http://168.231.119.80:3000',
	'http://twb-fe:3000',
        'http://localhost:3000',
        'https://twb.deraly.id',
        'http://twb.deraly.id',
        'https://api-andromeda.deraly.id',
    ],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => true,

];

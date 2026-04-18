<?php

/*
 * Ref: https://laravel.com/docs/12.x/configuration
 */

return [

    'name'     => env('APP_NAME', 'SaintAugustin-Auth'),
    'env'      => env('APP_ENV', 'production'),
    'debug'    => (bool) env('APP_DEBUG', false),
    'url'          => env('APP_URL', 'http://localhost'),
    'frontend_url' => env('APP_FRONTEND_URL', 'http://localhost:5173'),

    'timezone' => 'UTC',
    'locale'   => env('APP_LOCALE', 'en'),
    'fallback_locale'   => 'en',
    'faker_locale'      => 'en_US',
    'cipher' => 'AES-256-CBC',
    'key'    => env('APP_KEY'),

    'maintenance' => [
        'driver' => 'file',
    ],

];

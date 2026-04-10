<?php

/*
 * Ref: https://laravel.com/docs/12.x/cache
 * Ref: https://redis.io/docs/
 */

return [

    'default' => env('CACHE_STORE', 'redis'),

    'stores' => [
        'redis' => [
            'driver'     => 'redis',
            'connection' => 'cache',
            'lock_connection' => 'default',
        ],
        'array' => [
            'driver'    => 'array',
            'serialize' => false,
        ],
    ],

    'prefix' => env('CACHE_PREFIX', 'sa_auth_cache_'),
];

<?php

return [

    'paths' => ['api/*'],

    'allowed_methods' => ['*'],

    'allowed_origins' => [
        // Allow the frontend and projection services to make requests to the auth service
        env('APP_FRONTEND_URL', 'http://localhost:5173'),
        env('APP_PROJECTION_URL', 'http://localhost:3000'),
        'http://localhost:8001',
    ],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => true,

];

<?php

return [

    'default' => env('LOG_CHANNEL', 'stack'),

    'channels' => [
        'stack' => [
            'driver'   => 'stack',
            'channels' => explode(',', env('LOG_STACK', 'stderr')),
            'ignore_exceptions' => false,
        ],
        'stderr' => [
            'driver'    => 'monolog',
            'level'     => env('LOG_LEVEL', 'debug'),
            'handler'   => Monolog\Handler\StreamHandler::class,
            'with'      => ['stream' => 'php://stderr'],
            'formatter' => env('LOG_STDERR_FORMATTER'),
        ],
        'daily' => [
            'driver' => 'daily',
            'path'   => storage_path('logs/laravel.log'),
            'level'  => env('LOG_LEVEL', 'debug'),
            'days'   => 14,
        ],
    ],

];

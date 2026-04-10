<?php

/*
 * Laravel Octane – persistent worker process (no per-request bootstrap).
 * Ref: https://laravel.com/docs/12.x/octane
 */

return [

    'server' => env('OCTANE_SERVER', 'frankenphp'),

    'https' => false,

    'listeners' => [],

    'warm' => [],

    'flush' => [],

    'garbage' => 50,

    'max_execution_time' => 30,

    'state_file' => storage_path('logs/octane-state.json'),

    'tables' => [],

    'watch' => [
        'app',
        'bootstrap',
        'config',
        'database',
        'public/**/*.php',
        'resources/**/*.php',
        'routes',
        'composer.lock',
        '.env',
    ],

];

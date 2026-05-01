<?php

/*
 * Database-per-service pattern: Auth Service owns saintaugustin_db.
 * Ref: https://laravel.com/docs/12.x/database
 * Ref: https://www.postgresql.org/docs/16/
 */

return [

    'default' => env('DB_CONNECTION', 'pgsql'),

    'connections' => [
        'pgsql' => [
            'driver'         => 'pgsql',
            'host'           => env('DB_HOST', 'postgres'),
            'port'           => env('DB_PORT', '5432'),
            'database'       => env('DB_DATABASE', 'saintaugustin_db'),
            'username'       => env('DB_USERNAME', 'saintaugustin'),
            'password'       => env('DB_PASSWORD', ''),
            'charset'        => 'utf8',
            'prefix'         => '',
            'prefix_indexes' => true,
            'search_path'    => 'public',
            'sslmode'        => 'prefer',
        ],
    ],

    'migrations' => [
        'table' => 'migrations',
        'update_date_on_migration' => true,
    ],

];

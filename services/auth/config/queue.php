<?php

/*
 * Ref: https://laravel.com/docs/12.x/queues
 * Ref: https://redis.io/docs/data-types/streams/
 */

return [

    'default' => env('QUEUE_CONNECTION', 'redis'),

    'connections' => [
        'redis' => [
            'driver'       => 'redis',
            'connection'   => 'default',
            'queue'        => env('REDIS_QUEUE', 'sa_auth'),
            'retry_after'  => 90,
            'block_for'    => null,
            'after_commit' => false,
        ],
        'sync' => [
            'driver' => 'sync',
        ],
    ],

    'batching' => [
        'database'    => env('DB_CONNECTION', 'pgsql'),
        'table'       => 'job_batches',
        'prune_after' => '24 hours',
    ],

    'failed' => [
        'driver'   => env('QUEUE_FAILED_DRIVER', 'database-uuids'),
        'database' => env('DB_CONNECTION', 'pgsql'),
        'table'    => 'failed_jobs',
    ],

];

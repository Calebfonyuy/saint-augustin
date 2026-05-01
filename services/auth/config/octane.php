<?php

use Laravel\Octane\Events\OperationTerminated;
use Laravel\Octane\Events\RequestHandled;
use Laravel\Octane\Events\RequestReceived;
use Laravel\Octane\Events\RequestTerminated;
use Laravel\Octane\Events\TaskReceived;
use Laravel\Octane\Events\TaskTerminated;
use Laravel\Octane\Events\TickReceived;
use Laravel\Octane\Events\TickTerminated;
use Laravel\Octane\Events\WorkerErrorOccurred;
use Laravel\Octane\Events\WorkerStarting;
use Laravel\Octane\Events\WorkerStopping;
use Laravel\Octane\Listeners\CloseMonologHandlers;
use Laravel\Octane\Listeners\EnsureUploadedFilesAreValid;
use Laravel\Octane\Listeners\EnsureUploadedFilesCanBeMoved;
use Laravel\Octane\Listeners\FlushOnce;
use Laravel\Octane\Listeners\FlushTemporaryContainerInstances;
use Laravel\Octane\Listeners\ReportException;
use Laravel\Octane\Listeners\StopWorkerIfNecessary;
use Laravel\Octane\Octane;

/*
 * Laravel Octane – persistent worker process (no per-request bootstrap).
 * Ref: https://laravel.com/docs/12.x/octane
 */

return [

    'server' => env('OCTANE_SERVER', 'frankenphp'),

    'https' => false,

    /*
     * Octane lifecycle listeners. These are the package defaults — they MUST
     * be present. An empty array here overrides the defaults via array_merge
     * in mergeConfigFrom(), which silently disables all request-state cleanup.
     *
     * The critical path for auth:
     *   RequestReceived  → prepareApplicationForNextRequest() rebinds the
     *                       Request in the container for this request.
     *   OperationTerminated → FlushTemporaryContainerInstances clears facade
     *                          caches (Auth, etc.) and processes the `flush`
     *                          array below. Without this listener, nothing
     *                          is ever reset and every request after the first
     *                          runs with stale auth state → 401 on all
     *                          authenticated routes.
     */
    'listeners' => [
        WorkerStarting::class => [
            EnsureUploadedFilesAreValid::class,
            EnsureUploadedFilesCanBeMoved::class,
        ],

        RequestReceived::class => [
            ...Octane::prepareApplicationForNextOperation(),
            ...Octane::prepareApplicationForNextRequest(),
        ],

        RequestHandled::class => [
            //
        ],

        RequestTerminated::class => [
            //
        ],

        TaskReceived::class => [
            ...Octane::prepareApplicationForNextOperation(),
        ],

        TaskTerminated::class => [
            //
        ],

        TickReceived::class => [
            ...Octane::prepareApplicationForNextOperation(),
        ],

        TickTerminated::class => [
            //
        ],

        OperationTerminated::class => [
            FlushOnce::class,
            FlushTemporaryContainerInstances::class,
        ],

        WorkerErrorOccurred::class => [
            ReportException::class,
            StopWorkerIfNecessary::class,
        ],

        WorkerStopping::class => [
            CloseMonologHandlers::class,
        ],
    ],

    'warm' => [
        ...Octane::defaultServicesToWarm(),
    ],

    /*
     * `auth` is listed here so FlushTemporaryContainerInstances re-instantiates
     * the AuthManager on every request. This ensures the Sanctum RequestGuard
     * is always created with the current request's headers rather than a
     * reference to the first request the worker ever handled.
     */
    'flush' => [
        'auth',
    ],

    'garbage' => 50,

    'max_execution_time' => 30,

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

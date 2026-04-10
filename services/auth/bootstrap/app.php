<?php

/*
 * SaintAugustin Auth Service – Application Bootstrap
 *
 * Laravel 12 uses a streamlined bootstrap that replaces the older
 * Kernel classes with a fluent Application builder.
 *
 * Ref: https://laravel.com/docs/12.x/structure#the-bootstrap-directory
 */

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        health: '/health',                    // <-- built-in /health endpoint
    )
    ->withMiddleware(function (Middleware $middleware) {
        // Sanctum stateless token auth for API
        $middleware->statefulApi();
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })
    ->create();

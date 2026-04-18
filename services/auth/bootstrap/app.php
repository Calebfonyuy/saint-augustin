<?php

/*
 * SaintAugustin Auth Service – Application Bootstrap
 *
 * Laravel 12 uses a streamlined bootstrap that replaces the older
 * Kernel classes with a fluent Application builder.
 *
 * Ref: https://laravel.com/docs/12.x/structure#the-bootstrap-directory
 */

use App\Http\Middleware\RequireAdminRole;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        web: __DIR__.'/../routes/web.php',
        health: '/health',                    // <-- built-in /health endpoint
    )
    ->withMiddleware(function (Middleware $middleware) {
        // Stateless Bearer token auth — do NOT call statefulApi() here.
        // statefulApi() adds Sanctum's EnsureFrontendRequestsAreStateful middleware
        // which enables cookie/session auth and CSRF checks for SPAs.
        // This service issues opaque API tokens only; CSRF protection is irrelevant.
        // Ref: https://laravel.com/docs/12.x/sanctum#api-token-authentication
        // $middleware->statefulApi();

        $middleware->alias([
            'admin' => RequireAdminRole::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })
    ->create();

<?php

/*
 * SaintAugustin Auth Service – API Routes
 *
 * All routes are prefixed with /api automatically by Laravel.
 * The /health endpoint is registered in bootstrap/app.php via
 * Laravel 12's built-in health routing.
 *
 * Ref: https://laravel.com/docs/12.x/routing
 */

use Illuminate\Support\Facades\Route;

Route::fallback(function () {
    // if (request()->is('api/*')) {
    //     abort(404);
    // }
    return redirect('/health');
});

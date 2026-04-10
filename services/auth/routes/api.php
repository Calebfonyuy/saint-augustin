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

/*
|--------------------------------------------------------------------------
| Auth Routes (Phase 1)
|--------------------------------------------------------------------------
*/

Route::prefix('auth')->group(function () {
    // Phase 1: these will be implemented with controllers
    // Route::post('/register', [AuthController::class, 'register']);
    // Route::post('/login',    [AuthController::class, 'login']);
    // Route::post('/logout',   [AuthController::class, 'logout'])->middleware('auth:sanctum');
    // Route::get('/me',        [AuthController::class, 'me'])->middleware('auth:sanctum');

    // Placeholder: confirm the service is routable
    Route::get('/status', function () {
        return response()->json([
            'service' => 'auth-service',
            'status'  => 'ok',
            'version' => '0.1.0',
        ]);
    });
});

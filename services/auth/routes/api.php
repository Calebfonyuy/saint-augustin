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

use App\Http\Controllers\AuthController;
use App\Http\Controllers\InvitationController;
use App\Http\Controllers\PasswordResetController;
use App\Http\Controllers\StatusController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Auth Routes (Phase 1)
|--------------------------------------------------------------------------
*/

Route::prefix('auth')->group(function () {
    Route::get('/status', StatusController::class);

    // Public
    Route::post('/login',                        [AuthController::class, 'login']);
    Route::post('/password/forgot',              [PasswordResetController::class, 'forgot']);
    Route::post('/password/reset',               [PasswordResetController::class, 'reset']);
    Route::get('/invitations/{token}',           [InvitationController::class, 'verify']);
    Route::post('/register',                     [InvitationController::class, 'register']);

    // Protected – require a valid Sanctum token
    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/logout',  [AuthController::class, 'logout']);
        Route::post('/refresh', [AuthController::class, 'refresh']);

        // Admin only
        Route::middleware('admin')->group(function () {
            Route::post('/invitations', [InvitationController::class, 'store']);
        });
    });
});

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
use App\Http\Controllers\SongbookController;
use App\Http\Controllers\SongController;
use App\Http\Controllers\SongSheetController;
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

/*
|--------------------------------------------------------------------------
| Songbook Routes (Phase 1 — merged into Auth Service)
|--------------------------------------------------------------------------
| Authorization per SRS 2.2 — Admin has "full CRUD on songbooks":
|   • Read  — any authenticated user (needed for UI filtering/categorization)
|   • Create/Update/Delete — admin only
*/

Route::middleware('auth:sanctum')->prefix('songbooks')->group(function () {
    Route::get('/',     [SongbookController::class, 'index']);
    Route::get('/{id}', [SongbookController::class, 'show']);

    Route::middleware('admin')->group(function () {
        Route::post('/',      [SongbookController::class, 'store']);
        Route::put('/{id}',   [SongbookController::class, 'update']);
        Route::delete('/{id}', [SongbookController::class, 'destroy']);
    });
});

/*
|--------------------------------------------------------------------------
| Song Routes (Phase 1 — merged into Auth Service)
|--------------------------------------------------------------------------
| Authorization per SRS 3.1.2:
|   • Read  — any authenticated user
|   • Create/Update — admin OR musician (enforced in SongController)
|   • Delete/Restore — admin only (enforced via the `admin` middleware)
*/

Route::middleware('auth:sanctum')->prefix('songs')->group(function () {
    Route::get('/',            [SongController::class, 'index']);
    Route::get('/{id}',        [SongController::class, 'show']);
    Route::post('/',           [SongController::class, 'store']);
    Route::put('/{id}',        [SongController::class, 'update']);

    Route::middleware('admin')->group(function () {
        Route::delete('/{id}',         [SongController::class, 'destroy']);
        Route::post('/{id}/restore',   [SongController::class, 'restore']);
    });

    // Sheet attachments nested under a song (Phase 2, FR5).
    // Role checks for upload happen inside SongSheetController::store.
    Route::get('/{songId}/sheets',  [SongSheetController::class, 'index']);
    Route::post('/{songId}/sheets', [SongSheetController::class, 'store']);
});

/*
|--------------------------------------------------------------------------
| Song Sheet Routes (Phase 2 — File Service merged into Auth Service)
|--------------------------------------------------------------------------
| Authorization per SRS 2.2 + FR5:
|   • List/Show     — any authenticated user
|   • Upload        — admin or musician (enforced in SongSheetController)
|   • Delete        — admin only (enforced via the `admin` middleware)
*/

Route::middleware('auth:sanctum')->prefix('sheets')->group(function () {
    Route::get('/{id}', [SongSheetController::class, 'show']);

    Route::middleware('admin')->group(function () {
        Route::delete('/{id}', [SongSheetController::class, 'destroy']);
    });
});

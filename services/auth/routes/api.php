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
use App\Http\Controllers\BibleController;
use App\Http\Controllers\BibleSettingsController;
use App\Http\Controllers\ExportController;
use App\Http\Controllers\InvitationController;
use App\Http\Controllers\PasswordResetController;
use App\Http\Controllers\PlaylistController;
use App\Http\Controllers\PlaylistExportController;
use App\Http\Controllers\PlaylistItemController;
use App\Http\Controllers\ShareLinkController;
use App\Http\Controllers\SongbookController;
use App\Http\Controllers\SongbookExportController;
use App\Http\Controllers\SongController;
use App\Http\Controllers\SongImportController;
use App\Http\Controllers\SongSheetController;
use App\Http\Controllers\StatusController;
use App\Http\Controllers\StaugImportController;
use App\Http\Controllers\TagController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Auth Routes (Phase 1)
|--------------------------------------------------------------------------
*/

Route::prefix('auth')->group(function () {
    Route::get('/status', StatusController::class);

    // Public
    Route::post('/login', [AuthController::class, 'login']);
    Route::post('/password/forgot', [PasswordResetController::class, 'forgot']);
    Route::post('/password/reset', [PasswordResetController::class, 'reset']);
    Route::get('/invitations/{token}', [InvitationController::class, 'verify']);
    Route::post('/register', [InvitationController::class, 'register']);

    // Protected – require a valid Sanctum token
    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::post('/refresh', [AuthController::class, 'refresh']);
        // Token introspection — returns the authenticated user. Other
        // microservices (projection) call this to resolve a Bearer token
        // to a user identity and role set.
        Route::get('/me', [AuthController::class, 'me']);
        // Self-service profile editing — change display_name and/or password.
        Route::patch('/me', [AuthController::class, 'updateProfile']);

        // Admin only
        Route::middleware('admin')->group(function () {
            Route::get('/invitations', [InvitationController::class, 'index']);
            Route::post('/invitations', [InvitationController::class, 'store']);
            Route::post('/invitations/{id}/resend', [InvitationController::class, 'resend']);
            Route::delete('/invitations/{id}', [InvitationController::class, 'destroy']);
        });
    });
});

/*
|--------------------------------------------------------------------------
| User Management Routes (Admin)
|--------------------------------------------------------------------------
| Admin → Users screen (FR3). All routes require both Sanctum auth and the
| `admin` middleware. List/edit/delete a user; invitations live under
| /auth/invitations because they belong to the auth flow.
*/

Route::middleware(['auth:sanctum', 'admin'])->prefix('users')->group(function () {
    Route::get('/', [UserController::class, 'index']);
    Route::put('/{id}', [UserController::class, 'update']);
    Route::delete('/{id}', [UserController::class, 'destroy']);
});

/*
|--------------------------------------------------------------------------
| Bulk Song Import (Admin)
|--------------------------------------------------------------------------
| Used by the Admin → Import screen to dry-run + commit imports of
| third-party song libraries (currently VideoPsalm `.vpagd` archives).
*/

Route::middleware(['auth:sanctum', 'admin'])->prefix('imports')->group(function () {
    Route::post('/videopsalm', [SongImportController::class, 'videopsalm']);
    // Signed STAUG archive import (non-destructive merge).
    Route::post('/staug', [StaugImportController::class, 'staug']);
});

/*
|--------------------------------------------------------------------------
| Bulk Exports (Admin)
|--------------------------------------------------------------------------
| Full-library STAUG export. Queued; emails the admin a 48h download link.
*/

Route::middleware(['auth:sanctum', 'admin'])->prefix('exports')->group(function () {
    Route::post('/full', [ExportController::class, 'full']);
});

/*
|--------------------------------------------------------------------------
| Bible Routes (Stage 7 — FR-BI)
|--------------------------------------------------------------------------
| Reads (settings/books/resolve) are open to any authenticated user;
| translation management is admin-only. Scripture text is fetched from the
| HelloAO API and Redis-cached — never persisted.
*/

Route::middleware('auth:sanctum')->prefix('bible')->group(function () {
    Route::get('/settings', [BibleController::class, 'settings']);
    Route::get('/books', [BibleController::class, 'books']);
    Route::get('/resolve', [BibleController::class, 'resolve']);

    Route::middleware('admin')->group(function () {
        Route::get('/translations/available', [BibleSettingsController::class, 'available']);
        Route::put('/settings', [BibleSettingsController::class, 'update']);
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
    Route::get('/', [SongbookController::class, 'index']);
    Route::get('/{id}/export', SongbookExportController::class);
    Route::get('/{id}', [SongbookController::class, 'show']);

    Route::middleware('admin')->group(function () {
        Route::post('/', [SongbookController::class, 'store']);
        Route::put('/{id}', [SongbookController::class, 'update']);
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
    Route::get('/', [SongController::class, 'index']);
    Route::get('/{id}', [SongController::class, 'show']);
    Route::post('/', [SongController::class, 'store']);
    Route::put('/{id}', [SongController::class, 'update']);

    Route::middleware('admin')->group(function () {
        Route::delete('/{id}', [SongController::class, 'destroy']);
        Route::post('/{id}/restore', [SongController::class, 'restore']);
    });

    // Sheet attachments nested under a song (Phase 2, FR5).
    // Role checks for upload happen inside SongSheetController::store.
    Route::get('/{songId}/sheets', [SongSheetController::class, 'index']);
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

/*
|--------------------------------------------------------------------------
| Tag Suggestions (Stage 4 — FR-PL-1)
|--------------------------------------------------------------------------
| Cross-entity autocomplete source: the distinct union of tags used on
| songs and playlists. Any authenticated user may read it.
*/

Route::middleware('auth:sanctum')->get('/tags', [TagController::class, 'index']);

/*
|--------------------------------------------------------------------------
| Playlist Routes (Phase 3 — Playlist Service merged into Auth Service)
|--------------------------------------------------------------------------
| Authorization per SRS 3.3:
|   • List/Show       — any authenticated user
|   • Create          — any authenticated user
|   • Update/Delete   — owner OR admin (enforced in controller)
|   • Duplicate       — any authenticated user (the duplicator becomes the
|                       new owner of the copy)
|   • Items + Share   — owner OR admin
|   • Export          — any authenticated user
*/

Route::middleware('auth:sanctum')->prefix('playlists')->group(function () {
    Route::get('/', [PlaylistController::class, 'index']);
    Route::post('/', [PlaylistController::class, 'store']);
    Route::get('/{id}', [PlaylistController::class, 'show']);
    Route::put('/{id}', [PlaylistController::class, 'update']);
    Route::delete('/{id}', [PlaylistController::class, 'destroy']);
    Route::post('/{id}/duplicate', [PlaylistController::class, 'duplicate']);

    // Items
    Route::post('/{playlistId}/items', [PlaylistItemController::class, 'store']);
    Route::put('/{playlistId}/items/reorder', [PlaylistItemController::class, 'reorder']);
    Route::put('/{playlistId}/items/{itemId}', [PlaylistItemController::class, 'update']);
    Route::delete('/{playlistId}/items/{itemId}', [PlaylistItemController::class, 'destroy']);

    // Share links
    Route::get('/{playlistId}/share', [ShareLinkController::class, 'index']);
    Route::post('/{playlistId}/share', [ShareLinkController::class, 'store']);

    // Export
    Route::get('/{id}/export', PlaylistExportController::class);
});

// Share-link revocation by id (lives outside the /playlists prefix because
// the link id is the natural key here).
Route::middleware('auth:sanctum')->delete('/share-links/{id}', [ShareLinkController::class, 'destroy']);

/*
|--------------------------------------------------------------------------
| Public Share Endpoint (Phase 3)
|--------------------------------------------------------------------------
| No authentication. Throttled to keep automated probes from enumerating
| tokens. Returns the playlist payload shaped for either musician or
| projection mode based on the share link's stored `mode`.
*/

Route::middleware('throttle:60,1')->get('/share/{token}', [ShareLinkController::class, 'resolve']);

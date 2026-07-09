<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Share links table (SRS 3.3 / 7.1).
 *
 * A share link is a public, role-aware URL to a playlist. The token is a
 * URL-safe random string lookable up via `GET /api/share/:token` without
 * authentication. `mode` is one of:
 *   • musician     — show chords/key/sheets (Musician View per song)
 *   • projection   — lyrics-only, presentation-ready
 *
 * `expires_at` is reserved for a future "self-expiring share" feature; left
 * nullable so we can ship without it for now.
 *
 * `revoked_at` (nullable) lets the owner kill a leaked link without
 * deleting the row, preserving the audit trail.
 *
 * Multiple share links can exist per playlist (e.g. one musician + one
 * projection link), so no uniqueness constraint on `playlist_id`.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::create('share_links', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('playlist_id')
                  ->constrained('playlists')
                  ->cascadeOnDelete();
            $table->string('token', 64)->unique();
            $table->string('mode', 16); // 'musician' | 'projection'
            $table->foreignUuid('created_by')->nullable()
                  ->constrained('users')
                  ->nullOnDelete();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index('playlist_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('share_links');
    }
};

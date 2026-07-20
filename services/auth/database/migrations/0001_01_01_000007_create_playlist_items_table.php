<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Playlist items table (SRS 6.1 — PlaylistItem).
 *
 * One row per song included in a playlist. `position` is the 0-based
 * insertion index used for drag-and-drop ordering. `target_key` lets the
 * playlist override the song's `original_key` for this event only —
 * leaving it null means "play in the original key". `notes` is free-form
 * text shown on the per-item view (e.g. "skip second verse").
 *
 * The `(playlist_id, position)` unique index prevents two items at the
 * same slot. Reorder operations bump positions in a single transaction.
 *
 * The song FK uses `cascadeOnDelete` because a hard-deleted song can no
 * longer appear in any playlist. Soft-deleted songs (the common case)
 * stay in playlists by design — the editor will surface a "(deleted)"
 * badge but the item row keeps its slot.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::create('playlist_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('playlist_id')
                  ->constrained('playlists')
                  ->cascadeOnDelete();
            $table->foreignUuid('song_id')
                  ->constrained('songs')
                  ->cascadeOnDelete();
            $table->unsignedInteger('position');
            $table->string('target_key', 8)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['playlist_id', 'position']);
            $table->index('song_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('playlist_items');
    }
};

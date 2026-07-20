<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Polymorphic playlist items (SRS FR-PL-2, v0.2 Stage 5).
 *
 * A playlist item can now be either a song (the existing shape) or a
 * scripture reading. We keep a single table with a discriminator column
 * rather than a polymorphic join so the existing `(playlist_id, position)`
 * ordering, reorder transaction, and cascade all keep working unchanged.
 *
 *   - `item_type` discriminates 'song' | 'scripture' (defaults to 'song'
 *     so every existing row is a song with no backfill needed).
 *   - `song_id` becomes nullable — it is null for scripture rows. The FK
 *     and cascade are untouched; only the NOT NULL constraint is dropped.
 *   - Scripture rows carry a resolved USFM reference in discrete columns
 *     (preferred over a single JSON blob so ranges stay queryable/valid):
 *     translation_id, book_code, and start/end chapter+verse. `end_*` are
 *     null for a single-verse or open reference.
 *
 * Scripture *text* is never stored here — it is fetched/rendered at
 * projection time (Stage 7). These columns only pin down which passage.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::table('playlist_items', function (Blueprint $table) {
            $table->string('item_type', 16)->default('song')->after('playlist_id');

            // Scripture reference (USFM). All nullable — only populated on
            // scripture rows.
            $table->string('translation_id', 32)->nullable()->after('song_id');
            $table->string('book_code', 8)->nullable()->after('translation_id');
            $table->unsignedSmallInteger('start_chapter')->nullable()->after('book_code');
            $table->unsignedSmallInteger('start_verse')->nullable()->after('start_chapter');
            $table->unsignedSmallInteger('end_chapter')->nullable()->after('start_verse');
            $table->unsignedSmallInteger('end_verse')->nullable()->after('end_chapter');

            $table->index(['item_type']);
        });

        // Drop the NOT NULL on song_id so scripture rows can omit it. Done
        // as raw DDL to leave the existing FK constraint and index in place.
        DB::statement('ALTER TABLE playlist_items ALTER COLUMN song_id DROP NOT NULL');
    }

    public function down(): void
    {
        // Restoring NOT NULL would fail if any scripture rows exist; drop
        // them first so the rollback is deterministic.
        DB::statement("DELETE FROM playlist_items WHERE item_type = 'scripture'");
        DB::statement('ALTER TABLE playlist_items ALTER COLUMN song_id SET NOT NULL');

        Schema::table('playlist_items', function (Blueprint $table) {
            $table->dropIndex(['item_type']);
            $table->dropColumn([
                'item_type',
                'translation_id',
                'book_code',
                'start_chapter',
                'start_verse',
                'end_chapter',
                'end_verse',
            ]);
        });
    }
};

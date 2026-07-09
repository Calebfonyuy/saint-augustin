<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Songs table.
 *
 * Implements the Song entity from SRS 3.1.1 / 6.1.
 *
 * Key design choices:
 *   - ChordPro notation is stored inline in `lyrics` (SRS 3.2.1 — chords live
 *     inside the lyrics column via bracket notation), so no separate column
 *     for chords is required.
 *   - `tags` is a JSONB array for filtering and future GIN-index search.
 *   - `version` is a simple integer counter incremented on each update (SRS
 *     NFR-8 audit trail; full version history is a later-phase concern).
 *   - Soft-deletes give the 30-day recovery window required by NFR-8.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::create('songs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('title');
            $table->string('author')->nullable();
            $table->text('lyrics');                              // ChordPro content
            $table->string('original_key', 8)->nullable();       // e.g. C, F#, Am, Bbm
            $table->unsignedSmallInteger('tempo')->nullable();   // BPM
            $table->string('time_signature', 8)->nullable();     // e.g. 4/4, 6/8
            $table->foreignUuid('songbook_id')
                  ->constrained('songbooks')
                  ->cascadeOnDelete();
            $table->jsonb('tags')->default('[]');
            $table->string('preview_url', 500)->nullable();      // YouTube/audio link
            $table->string('ccli_number', 32)->nullable();
            $table->foreignUuid('created_by')->nullable()
                  ->constrained('users')
                  ->nullOnDelete();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->softDeletes();

            // Filter/search helpers
            $table->index('title');
            $table->index('author');
            $table->index(['songbook_id', 'deleted_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('songs');
    }
};

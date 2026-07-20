<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Playlists table (SRS 3.3 / 6.1, Dev Phase 3).
 *
 * A playlist is an ordered collection of songs assembled for an event
 * (e.g. "Sunday Service – April 13"). Playlists are owned by a user but
 * any authenticated user can list/view them — the share-link mechanism
 * is what makes them publicly viewable to non-authenticated visitors.
 *
 * `duplicated_from_id` is a soft pointer to the source playlist when the
 * row was created via /playlists/:id/duplicate. It's purely informational;
 * the duplicate is a fully independent copy and breaking the link does
 * not affect it.
 *
 * `tags` is a JSONB array (mirrors songs.tags) so we can reuse the same
 * `whereJsonContains` filtering pattern.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::create('playlists', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->date('event_date')->nullable();
            $table->jsonb('tags')->default('[]');
            $table->foreignUuid('created_by')->nullable()
                  ->constrained('users')
                  ->nullOnDelete();
            // Self-referencing FK — added in a second pass below so the
            // primary key on `playlists.id` exists before the constraint
            // is applied (Postgres rejects the constraint otherwise).
            $table->uuid('duplicated_from_id')->nullable();
            $table->timestamps();

            $table->index('name');
            $table->index('created_by');
            $table->index('event_date');
        });

        Schema::table('playlists', function (Blueprint $table) {
            $table->foreign('duplicated_from_id')
                  ->references('id')->on('playlists')
                  ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('playlists');
    }
};

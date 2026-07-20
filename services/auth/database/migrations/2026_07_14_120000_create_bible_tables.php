<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bible module tables (SRS FR-BI, v0.2 Stage 7).
 *
 *  - `bible_settings` is a single-row table holding which HelloAO
 *    translations the workspace has enabled and which is the default.
 *  - `bible_books` is a structure-only cache (FR-BI-2): the localized book
 *    names + chapter counts per enabled translation, populated from the
 *    translation's books.json when settings are saved. It holds NO verse
 *    text — scripture is fetched on demand and cached in Redis only.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::create('bible_settings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->jsonb('enabled_translations')->default('[]');
            $table->string('default_translation_id')->nullable();
            $table->timestamps();
        });

        Schema::create('bible_books', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('translation_id');
            $table->string('book_code', 8);       // USFM, e.g. JHN
            $table->string('name');               // localized display name
            $table->unsignedSmallInteger('chapter_count');
            $table->unsignedSmallInteger('order')->default(0);
            $table->timestamps();

            $table->unique(['translation_id', 'book_code']);
            $table->index('translation_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bible_books');
        Schema::dropIfExists('bible_settings');
    }
};

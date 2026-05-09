<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Songbooks table.
 *
 * Songbooks are logical collections that group songs (SRS 3.1.3). The system
 * ships with one default songbook ("is_default = true"); admins may create
 * additional songbooks. A song must belong to exactly one songbook.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('songbooks', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name')->unique();
            $table->string('description')->nullable();
            $table->boolean('is_default')->default(false);
            $table->foreignUuid('created_by')->nullable()
                  ->constrained('users')
                  ->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('songbooks');
    }
};

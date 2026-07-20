<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Invitations table for Auth Service.
 *
 * Admin-issued email invitations with pre-assigned roles (SRS Phase 1).
 * One pending invitation per email address (unique constraint on email).
 * Token is a 64-character secure random string used in the invite link.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::create('invitations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('email')->unique();
            $table->string('token', 64)->unique();
            $table->jsonb('roles')->default('["musician"]');    // pre-assigned roles (SRS D-3)
            $table->foreignUuid('invited_by')->nullable()       // null-safe if inviter is deleted
                  ->constrained('users')
                  ->nullOnDelete();
            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();       // set when registration completes
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invitations');
    }
};

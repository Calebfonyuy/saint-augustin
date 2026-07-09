<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Song sheets — file attachments on songs.
 *
 * Implements the SongSheet entity from SRS 6.1 and FR5 (Song Sheet
 * Attachments). Each row is a single PDF or image stored in MinIO; the
 * download URL is generated on demand as a short-lived presigned S3 URL,
 * so we only persist the storage key here, never the URL itself.
 *
 * Key design choices:
 *   - file_type is a constrained string ('pdf' | 'image') because the
 *     Musician View renders them with different viewers (pdfjs vs <img>).
 *   - song_id is cascade-delete: when a song is hard-deleted, its sheets
 *     go with it. Soft-deletes on the song leave sheets in place.
 *   - storage_disk is recorded so a later migration to a different disk
 *     (S3 in prod, local in tests, etc.) doesn't break existing rows.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::create('song_sheets', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->foreignUuid('song_id')
                  ->constrained('songs')
                  ->cascadeOnDelete();

            $table->string('original_filename');         // user-facing name (e.g. "amazing-grace.pdf")
            $table->string('storage_disk', 32)->default('minio');
            $table->string('storage_path');              // object key inside the bucket
            $table->string('file_type', 16);             // 'pdf' | 'image'
            $table->string('mime_type', 128);
            $table->unsignedBigInteger('size_bytes');

            $table->foreignUuid('uploaded_by')->nullable()
                  ->constrained('users')
                  ->nullOnDelete();

            $table->timestamps();

            $table->index('song_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('song_sheets');
    }
};

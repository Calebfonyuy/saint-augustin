<?php

namespace Database\Seeders;

use App\Models\Song;
use App\Models\SongSheet;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Seeds a handful of sample song-sheet PDFs so the Phase-2 Musician View has
 * something to display out of the box.
 *
 * Source files live in storage/seed-sheets/ (committed to the repo). Each is
 * uploaded to the same MinIO disk used by the production upload path, then
 * a SongSheet record is written that points at the resulting object key.
 *
 * Idempotent: a song that already has a sheet whose `original_filename`
 * matches the seed entry is left alone, so re-running `db:seed` won't
 * stack duplicate uploads in MinIO.
 *
 * Resilient: if MinIO isn't reachable (e.g. someone running the seeder
 * outside Docker Compose) we log + skip rather than abort the whole seed
 * run — the rest of the data is still useful.
 */
class SongSheetSeeder extends Seeder
{
    /**
     * Mapping of song title => list of seed sheet files.
     *
     * Filenames are resolved relative to storage/seed-sheets/. A song that
     * isn't in this map gets no sheets seeded; that's intentional so we can
     * exercise the "no sheets attached" empty state in the UI as well.
     *
     * @var array<string, array<int, array{file: string, mime: string}>>
     */
    private const SHEET_MAP = [
        'Amazing Grace' => [
            ['file' => 'amazing-grace-lead-sheet.pdf', 'mime' => 'application/pdf'],
        ],
        'Holy, Holy, Holy' => [
            ['file' => 'holy-holy-holy-choir-part.pdf', 'mime' => 'application/pdf'],
        ],
        'How Great Thou Art' => [
            ['file' => 'how-great-thou-art-lead-sheet.pdf', 'mime' => 'application/pdf'],
        ],
    ];

    public function run(): void
    {
        $sourceDir = storage_path('seed-sheets');

        if (! is_dir($sourceDir)) {
            $this->command?->warn("[SongSheetSeeder] No seed directory at {$sourceDir} — skipping.");

            return;
        }

        $disk = Storage::disk('minio');
        $this->ensureBucketExists($disk);
        $uploaderId = User::where('email', 'admin@saintaugustin.local')->value('id');

        foreach (self::SHEET_MAP as $title => $sheets) {
            $song = Song::where('title', $title)->first();

            if (! $song) {
                $this->command?->warn("[SongSheetSeeder] Song not found for '{$title}' — skipping its sheets.");

                continue;
            }

            foreach ($sheets as $entry) {
                $this->seedSheet($disk, $song, $uploaderId, $sourceDir, $entry);
            }
        }
    }

    /**
     * Upload one source file to MinIO and create the SongSheet row, if a
     * matching row doesn't already exist.
     *
     * @param  array{file: string, mime: string}  $entry
     */
    private function seedSheet(
        \Illuminate\Contracts\Filesystem\Filesystem $disk,
        Song $song,
        ?string $uploaderId,
        string $sourceDir,
        array $entry,
    ): void {
        $filename = $entry['file'];
        $sourcePath = $sourceDir.DIRECTORY_SEPARATOR.$filename;

        if (! is_file($sourcePath)) {
            $this->command?->warn("[SongSheetSeeder] Missing source file: {$sourcePath}");

            return;
        }

        // Idempotency: if a sheet with the same display filename already
        // exists for this song, skip.
        $alreadySeeded = SongSheet::where('song_id', $song->id)
            ->where('original_filename', $filename)
            ->exists();

        if ($alreadySeeded) {
            return;
        }

        $extension = pathinfo($filename, PATHINFO_EXTENSION) ?: 'pdf';
        $key = sprintf('song-sheets/%s/%s.%s', $song->id, (string) Str::uuid(), $extension);
        $contents = file_get_contents($sourcePath);
        $size = strlen($contents);

        try {
            $disk->put($key, $contents, [
                'ContentType' => $entry['mime'],
            ]);
        } catch (\Throwable $e) {
            // MinIO unreachable / bucket missing / credentials wrong. Log and
            // move on — the seeder is best-effort for local dev convenience,
            // not a production data-load tool.
            Log::warning('[SongSheetSeeder] Upload failed for '.$filename, [
                'song'  => $song->title,
                'error' => $e->getMessage(),
            ]);
            $this->command?->warn(
                "[SongSheetSeeder] Could not upload {$filename} for '{$song->title}': {$e->getMessage()}"
            );

            return;
        }

        SongSheet::create([
            'song_id'           => $song->id,
            'original_filename' => $filename,
            'storage_disk'      => 'minio',
            'storage_path'      => $key,
            'file_type'         => SongSheet::TYPE_PDF,
            'mime_type'         => $entry['mime'],
            'size_bytes'        => $size,
            'uploaded_by'       => $uploaderId,
        ]);

        $this->command?->info("[SongSheetSeeder] Seeded sheet '{$filename}' for '{$song->title}'.");
    }

    /**
     * Make sure the MinIO bucket exists before we try to upload anything.
     *
     * On a fresh `docker compose up`, MinIO comes up with no buckets — the
     * first PUT then fails with NoSuchBucket. This helper does a HEAD and
     * creates the bucket if it's missing so `db:seed` works out of the box.
     *
     * Best-effort: anything other than a clean success is logged and we
     * carry on. The subsequent upload will surface the real error if there
     * still is one.
     */
    private function ensureBucketExists(\Illuminate\Contracts\Filesystem\Filesystem $disk): void
    {
        $bucket = config('filesystems.disks.minio.bucket');

        if (! $bucket) {
            return;
        }

        // FilesystemAdapter exposes the underlying AWS S3 client; we need
        // it because Flysystem's API doesn't include bucket-level ops.
        if (! method_exists($disk, 'getClient')) {
            return;
        }

        /** @var \Aws\S3\S3Client $client */
        $client = $disk->getClient();

        try {
            $client->headBucket(['Bucket' => $bucket]);

            return;
        } catch (\Aws\S3\Exception\S3Exception $e) {
            $status = $e->getStatusCode();
            // 404 = doesn't exist; anything else (403, network, etc.) we
            // can't fix from here, so let the upload fail with a real msg.
            if ($status !== 404) {
                Log::warning('[SongSheetSeeder] headBucket failed', [
                    'bucket' => $bucket,
                    'status' => $status,
                    'error'  => $e->getMessage(),
                ]);

                return;
            }
        }

        try {
            $client->createBucket(['Bucket' => $bucket]);
            $this->command?->info("[SongSheetSeeder] Created MinIO bucket '{$bucket}'.");
        } catch (\Throwable $e) {
            Log::warning('[SongSheetSeeder] Bucket creation failed', [
                'bucket' => $bucket,
                'error'  => $e->getMessage(),
            ]);
            $this->command?->warn(
                "[SongSheetSeeder] Could not create bucket '{$bucket}': {$e->getMessage()}"
            );
        }
    }
}

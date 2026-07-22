<?php

namespace App\Jobs;

use App\Models\User;
use App\Notifications\StaugExportReadyNotification;
use App\Services\Staug\StaugArchiveWriter;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Builds the full-library STAUG archive off the request thread (SRS FR-DF),
 * uploads it to MinIO under the `exports/` prefix, and emails the requesting
 * admin a 48h presigned download link.
 *
 * Concurrency: the controller holds a one-at-a-time marker (Cache::add) that
 * this job releases on completion — in a `finally` for the happy/thrown paths
 * and again in failed() for job-level failures (timeout/manual). The marker's
 * own TTL is the ultimate backstop if the worker is hard-killed. `tries = 1`
 * because a retried job would release the lock in finally and let a concurrent
 * export slip in.
 */
class FullExportJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 900;

    public function __construct(public string $adminId)
    {
    }

    public function handle(StaugArchiveWriter $writer): void
    {
        try {
            $tmp = $writer->writeFull();

            try {
                $key = 'exports/staug-full-'.now()->format('Ymd-His').'-'.Str::uuid()->toString().'.zip';

                $stream = fopen($tmp, 'r');
                try {
                    Storage::disk(config('filesystems.default'))->put($key, $stream);
                } finally {
                    if (is_resource($stream)) {
                        fclose($stream);
                    }
                }

                $ttlHours = (int) config('staug.export_url_ttl_hours', 48);
                $url = $this->presignedUrl($key, $ttlHours);

                $admin = User::find($this->adminId);
                if ($admin) {
                    $admin->notify(new StaugExportReadyNotification($url, $ttlHours));
                }
            } finally {
                @unlink($tmp);
            }
        } finally {
            Cache::forget((string) config('staug.full_export_lock_key'));
        }
    }

    public function failed(?Throwable $e): void
    {
        // Backstop for job-level failures where handle()'s finally may not run.
        Cache::forget((string) config('staug.full_export_lock_key'));
    }

    /**
     * Mirror SongSheetController::formatSheet — only S3-style disks support
     * presigned temporary URLs; fall back to a plain URL otherwise (also makes
     * this testable under Storage::fake, which is a local disk).
     */
    private function presignedUrl(string $key, int $ttlHours): string
    {
        $disk = Storage::disk(config('filesystems.default'));

        return config('filesystems.disks.minio.driver') === 's3'
            ? $disk->temporaryUrl($key, now()->addHours($ttlHours))
            : $disk->url($key);
    }
}

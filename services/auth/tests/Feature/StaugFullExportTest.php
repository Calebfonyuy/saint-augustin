<?php

use App\Jobs\FullExportJob;
use App\Models\Song;
use App\Models\User;
use App\Notifications\StaugExportReadyNotification;
use App\Services\Staug\StaugArchiveReader;
use App\Services\Staug\StaugArchiveWriter;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

/*
 * Full-library export (FR-DF-1..3): controller lock + queued job.
 */

function lockKey(): string
{
    return (string) config('staug.full_export_lock_key');
}

// ── Controller (one-at-a-time guard) ─────────────────────────────────

test('admin can queue a full export and gets 202', function () {
    Queue::fake();
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)->postJson('/api/exports/full')
        ->assertStatus(202)
        ->assertJsonPath('status', 'queued');

    Queue::assertPushed(FullExportJob::class, 1);
    expect(Cache::has(lockKey()))->toBeTrue();
});

test('a second concurrent full export is rejected with 409', function () {
    Queue::fake();
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)->postJson('/api/exports/full')->assertStatus(202);
    $this->actingAs($admin)->postJson('/api/exports/full')->assertStatus(409);

    Queue::assertPushed(FullExportJob::class, 1); // only the first queued a job
});

test('non-admin cannot trigger a full export', function () {
    Queue::fake();
    $user = User::factory()->create();

    $this->actingAs($user)->postJson('/api/exports/full')->assertStatus(403);
    Queue::assertNotPushed(FullExportJob::class);
});

// ── Job ──────────────────────────────────────────────────────────────

test('the job writes a full archive to MinIO, emails the admin, and releases the lock', function () {
    Storage::fake('minio', ['url' => 'http://minio.test']);
    config(['filesystems.disks.minio.driver' => 'local']); // presignedUrl → url() fallback
    Notification::fake();

    Cache::add(lockKey(), true, now()->addMinutes(30)); // as the controller would
    Song::factory()->count(2)->create();
    $admin = User::factory()->admin()->create();

    (new FullExportJob($admin->id))->handle(app(StaugArchiveWriter::class));

    $files = Storage::disk(config('filesystems.default'))->files('exports');
    expect($files)->toHaveCount(1);
    expect($files[0])->toContain('staug-full-');

    // The uploaded object is a valid type:"full" STAUG archive.
    $tmp = tempnam(sys_get_temp_dir(), 'full-');
    file_put_contents($tmp, Storage::disk(config('filesystems.default'))->get($files[0]));
    expect(app(StaugArchiveReader::class)->open($tmp)->type)->toBe('full');
    @unlink($tmp);

    Notification::assertSentTo($admin, StaugExportReadyNotification::class);
    expect(Cache::has(lockKey()))->toBeFalse(); // lock released
});

test('the job releases the lock even when it fails', function () {
    // No 'minio' disk write target + s3 driver → put/temporaryUrl path blows up.
    Cache::add(lockKey(), true, now()->addMinutes(30));
    $admin = User::factory()->admin()->create();

    // Force a failure by mocking the writer to throw.
    $writer = Mockery::mock(StaugArchiveWriter::class);
    $writer->shouldReceive('writeFull')->andThrow(new RuntimeException('boom'));

    expect(fn () => (new FullExportJob($admin->id))->handle($writer))
        ->toThrow(RuntimeException::class);

    expect(Cache::has(lockKey()))->toBeFalse(); // finally released it
});

test('the presigned link is generated with the configured 48h TTL', function () {
    config(['filesystems.disks.minio.driver' => 's3', 'staug.export_url_ttl_hours' => 48]);
    Notification::fake();
    Cache::add(lockKey(), true, now()->addMinutes(30));
    Song::factory()->create();
    $admin = User::factory()->admin()->create();

    $disk = Mockery::mock();
    $disk->shouldReceive('put')->once();
    $disk->shouldReceive('temporaryUrl')->once()
        ->withArgs(fn ($key, $expiry) => $expiry->between(now()->addHours(47), now()->addHours(49)))
        ->andReturn('https://minio.test/presigned');
    Storage::shouldReceive('disk')->with('minio')->andReturn($disk);

    (new FullExportJob($admin->id))->handle(app(StaugArchiveWriter::class));

    Notification::assertSentTo(
        $admin,
        StaugExportReadyNotification::class,
        fn ($n) => $n->url === 'https://minio.test/presigned' && $n->expiresInHours === 48
    );
});

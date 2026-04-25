<?php

use App\Models\Song;
use App\Models\SongSheet;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/*
 * Feature tests for the SongSheet (File Service) endpoints — Phase 2 / FR5.
 *
 * Storage is faked on the `minio` disk so tests don't need a live MinIO.
 * The controller uses `instanceof Cloud` to avoid temporaryUrl() on the
 * faked local disk; we assert against `url()` here.
 */

beforeEach(function () {
    Storage::fake('minio');
});

// ── Listing ──────────────────────────────────────────────────────────

test('listing sheets requires authentication', function () {
    $song = Song::factory()->create();

    $this->getJson("/api/songs/{$song->id}/sheets")->assertStatus(401);
});

test('any authenticated user can list a song\'s sheets', function () {
    $song = Song::factory()->create();
    SongSheet::factory()->count(2)->create(['song_id' => $song->id]);

    // Projectionist (lowest-privileged role) can still read.
    $user = User::factory()->projectionist()->create();

    $response = $this->actingAs($user)->getJson("/api/songs/{$song->id}/sheets");

    $response->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonStructure([
            'data' => [
                ['id', 'song_id', 'original_filename', 'file_type', 'mime_type', 'size_bytes', 'url'],
            ],
        ]);
});

test('listing sheets for a missing song returns 404', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->getJson('/api/songs/00000000-0000-0000-0000-000000000000/sheets')
        ->assertStatus(404);
});

// ── Uploading ────────────────────────────────────────────────────────

test('uploading a sheet requires authentication', function () {
    $song = Song::factory()->create();

    $this->postJson("/api/songs/{$song->id}/sheets", [
        'file' => UploadedFile::fake()->create('sheet.pdf', 100, 'application/pdf'),
    ])->assertStatus(401);
});

test('admins can upload a PDF and the file lands on the minio disk', function () {
    $admin = User::factory()->admin()->create();
    $song  = Song::factory()->create();

    $file = UploadedFile::fake()->create('lead-sheet.pdf', 250, 'application/pdf');

    $response = $this->actingAs($admin)
        ->post("/api/songs/{$song->id}/sheets", ['file' => $file]);

    $response->assertCreated()
        ->assertJsonPath('original_filename', 'lead-sheet.pdf')
        ->assertJsonPath('file_type', SongSheet::TYPE_PDF)
        ->assertJsonPath('mime_type', 'application/pdf')
        ->assertJsonPath('song_id', $song->id);

    $sheet = SongSheet::where('song_id', $song->id)->firstOrFail();
    expect($sheet->uploaded_by)->toBe($admin->id);
    Storage::disk('minio')->assertExists($sheet->storage_path);
});

test('musicians can upload a PNG image', function () {
    $musician = User::factory()->create(); // default role: musician
    $song = Song::factory()->create();

    // Use create() rather than image() — GD isn't always present in CI
    // containers, and the controller relies on the client mime type anyway.
    $file = UploadedFile::fake()->create('choir-part.png', 80, 'image/png');

    $response = $this->actingAs($musician)
        ->post("/api/songs/{$song->id}/sheets", ['file' => $file]);

    $response->assertCreated()
        ->assertJsonPath('file_type', SongSheet::TYPE_IMAGE)
        ->assertJsonPath('mime_type', 'image/png');
});

test('projectionists cannot upload sheets', function () {
    $user = User::factory()->projectionist()->create();
    $song = Song::factory()->create();

    $this->actingAs($user)
        ->post("/api/songs/{$song->id}/sheets", [
            'file' => UploadedFile::fake()->create('x.pdf', 10, 'application/pdf'),
        ])
        ->assertStatus(403);
});

test('uploading rejects disallowed mime types', function () {
    $admin = User::factory()->admin()->create();
    $song  = Song::factory()->create();

    $this->actingAs($admin)
        ->withHeaders(['Accept' => 'application/json'])
        ->post("/api/songs/{$song->id}/sheets", [
            'file' => UploadedFile::fake()->create('virus.exe', 5, 'application/x-msdownload'),
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['file']);
});

test('uploading rejects files larger than the cap', function () {
    $admin = User::factory()->admin()->create();
    $song  = Song::factory()->create();

    // 11 MiB > 10 MiB cap.
    $oversize = UploadedFile::fake()->create('huge.pdf', 11 * 1024, 'application/pdf');

    $this->actingAs($admin)
        ->withHeaders(['Accept' => 'application/json'])
        ->post("/api/songs/{$song->id}/sheets", ['file' => $oversize])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['file']);
});

test('uploading to a missing song returns 404', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->post('/api/songs/00000000-0000-0000-0000-000000000000/sheets', [
            'file' => UploadedFile::fake()->create('x.pdf', 10, 'application/pdf'),
        ])
        ->assertStatus(404);
});

// ── Show ─────────────────────────────────────────────────────────────

test('showing a sheet requires authentication', function () {
    $sheet = SongSheet::factory()->create();

    $this->getJson("/api/sheets/{$sheet->id}")->assertStatus(401);
});

test('show returns metadata and a download url', function () {
    $user  = User::factory()->create();
    $sheet = SongSheet::factory()->create();

    $response = $this->actingAs($user)->getJson("/api/sheets/{$sheet->id}");

    $response->assertOk()
        ->assertJsonPath('id', $sheet->id)
        ->assertJsonStructure(['id', 'song_id', 'original_filename', 'url']);
});

test('show returns 404 for a missing sheet', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->getJson('/api/sheets/00000000-0000-0000-0000-000000000000')
        ->assertStatus(404);
});

// ── Delete ───────────────────────────────────────────────────────────

test('deleting a sheet requires authentication', function () {
    $sheet = SongSheet::factory()->create();

    $this->deleteJson("/api/sheets/{$sheet->id}")->assertStatus(401);
});

test('musicians cannot delete sheets', function () {
    $user  = User::factory()->create();
    $sheet = SongSheet::factory()->create();

    $this->actingAs($user)
        ->deleteJson("/api/sheets/{$sheet->id}")
        ->assertStatus(403);
});

test('admins can delete a sheet and the underlying file is removed', function () {
    $admin = User::factory()->admin()->create();
    $song  = Song::factory()->create();

    // Upload a real (faked) file first so we can verify it gets removed.
    $file = UploadedFile::fake()->create('to-delete.pdf', 50, 'application/pdf');
    $this->actingAs($admin)->post("/api/songs/{$song->id}/sheets", ['file' => $file]);

    $sheet = SongSheet::where('song_id', $song->id)->firstOrFail();
    Storage::disk('minio')->assertExists($sheet->storage_path);

    $this->actingAs($admin)
        ->deleteJson("/api/sheets/{$sheet->id}")
        ->assertStatus(204);

    expect(SongSheet::find($sheet->id))->toBeNull();
    Storage::disk('minio')->assertMissing($sheet->storage_path);
});

test('deleting a missing sheet returns 404', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->deleteJson('/api/sheets/00000000-0000-0000-0000-000000000000')
        ->assertStatus(404);
});

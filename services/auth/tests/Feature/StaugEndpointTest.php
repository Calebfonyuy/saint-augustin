<?php

use App\Models\Playlist;
use App\Models\PlaylistItem;
use App\Models\Song;
use App\Models\Songbook;
use App\Models\User;
use App\Services\Staug\StaugArchiveWriter;
use Illuminate\Http\UploadedFile;

/*
 * Feature coverage for the STAUG export/import HTTP endpoints (FR-PL-3,
 * FR-AB-1, FR-DI-1/2).
 */

/** Turn a writer temp-zip path into an uploadable file. */
function uploadableArchive(string $path): UploadedFile
{
    return new UploadedFile($path, 'archive.zip', 'application/zip', null, true);
}

function fullArchiveFile(): string
{
    return app(StaugArchiveWriter::class)->writeFull();
}

// ── Playlist export ──────────────────────────────────────────────────

test('playlist staug export returns a verifiable signed archive', function () {
    $admin = User::factory()->admin()->create();
    $playlist = Playlist::factory()->create(['created_by' => $admin->id]);
    $song = Song::factory()->create();
    PlaylistItem::factory()->create(['playlist_id' => $playlist->id, 'song_id' => $song->id, 'position' => 0]);

    $response = $this->actingAs($admin)->get("/api/playlists/{$playlist->id}/export?format=staug");

    $response->assertOk();
    expect($response->headers->get('content-disposition'))->toContain('.staug.zip');

    // The downloaded bytes open + verify as a STAUG archive.
    $tmp = tempnam(sys_get_temp_dir(), 'dl-');
    file_put_contents($tmp, $response->streamedContent());
    $archive = app(App\Services\Staug\StaugArchiveReader::class)->open($tmp);
    expect($archive->type)->toBe('playlist');
    @unlink($tmp);
});

test('playlist export still rejects an unsupported format', function () {
    $user = User::factory()->create();
    $playlist = Playlist::factory()->create();

    $this->actingAs($user)->get("/api/playlists/{$playlist->id}/export?format=xml")
        ->assertStatus(422);
});

// ── Songbook export ──────────────────────────────────────────────────

test('songbook staug export returns a verifiable archive', function () {
    $user = User::factory()->create();
    $songbook = Songbook::factory()->create();
    Song::factory()->count(2)->create(['songbook_id' => $songbook->id]);

    $response = $this->actingAs($user)->get("/api/songbooks/{$songbook->id}/export?format=staug");

    $response->assertOk();
    expect($response->headers->get('content-disposition'))->toContain('.staug.zip');
});

test('songbook export 404s for an unknown id and 422s for a bad format', function () {
    $user = User::factory()->create();
    $songbook = Songbook::factory()->create();

    $this->actingAs($user)->get('/api/songbooks/'.fake()->uuid().'/export')->assertStatus(404);
    $this->actingAs($user)->get("/api/songbooks/{$songbook->id}/export?format=pdf")->assertStatus(422);
});

// ── Import ───────────────────────────────────────────────────────────

test('staug import requires admin', function () {
    $user = User::factory()->create();
    $file = uploadableArchive(fullArchiveFile());

    $this->actingAs($user)->post('/api/imports/staug', ['file' => $file])->assertStatus(403);
});

test('staug import dry run previews without writing', function () {
    $admin = User::factory()->admin()->create();
    Song::factory()->count(2)->create();
    $file = uploadableArchive(fullArchiveFile());
    $before = Song::count();

    $this->actingAs($admin)->post('/api/imports/staug', ['file' => $file, 'dry_run' => '1'])
        ->assertOk()
        ->assertJsonStructure(['type', 'songs', 'songbooks']);

    expect(Song::count())->toBe($before);
});

test('staug import into a clean instance re-creates the songs (round-trip)', function () {
    // Build an archive from one instance's data...
    $songbook = Songbook::factory()->create(['name' => 'Portable Book']);
    $song = Song::factory()->create(['title' => 'Travels Well', 'songbook_id' => $songbook->id]);
    $archivePath = app(StaugArchiveWriter::class)->writeSongbook($songbook);
    $file = uploadableArchive($archivePath);

    // ...then wipe and import into the "clean" instance.
    Song::query()->forceDelete();
    Songbook::query()->delete();

    $admin = User::factory()->admin()->create();
    $this->actingAs($admin)->post('/api/imports/staug', ['file' => $file])
        ->assertCreated()
        ->assertJsonPath('created', 1);

    $restored = Song::find($song->id);
    expect($restored)->not->toBeNull();
    expect($restored->title)->toBe('Travels Well');
});

test('a tampered archive is rejected with 422 and writes nothing', function () {
    $admin = User::factory()->admin()->create();
    Song::factory()->create();
    $path = fullArchiveFile();

    // Corrupt the signature sidecar.
    $zip = new ZipArchive();
    $zip->open($path);
    $zip->addFromString('manifest.sig', 'deadbeef');
    $zip->close();

    $before = Song::count();
    $this->actingAs($admin)->post('/api/imports/staug', ['file' => uploadableArchive($path)])
        ->assertStatus(422);

    expect(Song::count())->toBe($before);
});

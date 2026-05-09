<?php

use App\Models\Song;
use App\Models\Songbook;
use App\Models\User;
use Illuminate\Http\UploadedFile;

/*
 * Feature tests for POST /api/imports/videopsalm — covers auth, admin guard,
 * dry-run preview shape, real import, and the guids[] filter.
 *
 * Builds tiny synthetic .vpagd zips so the suite stays fast. There's a
 * separate VpagdSampleParseTest that exercises the real ~11 MB sample if
 * present.
 */

/**
 * Build a temp .vpagd file as a Laravel UploadedFile suitable for the
 * controller. Caller does not need to clean up — UploadedFile keeps a
 * reference to the temp path during the request lifetime.
 *
 * @param array<string, string> $entries
 */
function buildVpagdUpload(array $entries, string $name = 'songs.vpagd'): UploadedFile
{
    $tmp = tempnam(sys_get_temp_dir(), 'vpagdU_');
    $zip = new ZipArchive();
    $zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    foreach ($entries as $entry => $content) {
        $zip->addFromString($entry, $content);
    }
    $zip->close();

    // Pass test=true so Laravel skips the moved-uploaded-file check.
    return new UploadedFile($tmp, $name, 'application/octet-stream', null, true);
}

function sampleVpagdEntries(): array
{
    return [
        'Song_0.json'     => '{Guid:"g-0",Text:"Alpha Song",Verses:[{Text:"alpha line one"}]}',
        'SongBook_0.json' => '{Guid:"sb-1",Text:"Alpha Book"}',
        'Song_1.json'     => '{Guid:"g-1",Text:"Beta Song",Verses:[{Text:"beta line"},{Tag:1,Text:"beta chorus"}]}',
        'SongBook_1.json' => '{Guid:"sb-1",Text:"Alpha Book"}',
        'Song_2.json'     => '{Guid:"g-2",Text:"Gamma",Verses:[{Text:"gamma"}]}',
        'SongBook_2.json' => '{Guid:"sb-2",Text:"Gamma Book"}',
    ];
}

// ── Auth guards ──────────────────────────────────────────────────────

test('unauthenticated requests are rejected', function () {
    $upload = buildVpagdUpload(sampleVpagdEntries());

    $this->postJson('/api/imports/videopsalm', ['file' => $upload])
        ->assertStatus(401);
});

test('non-admin users are forbidden', function () {
    $musician = User::factory()->create(); // default role: musician
    $upload = buildVpagdUpload(sampleVpagdEntries());

    $this->actingAs($musician)
        ->post('/api/imports/videopsalm', ['file' => $upload])
        ->assertStatus(403);
});

test('projectionists are forbidden', function () {
    $proj = User::factory()->projectionist()->create();
    $upload = buildVpagdUpload(sampleVpagdEntries());

    $this->actingAs($proj)
        ->post('/api/imports/videopsalm', ['file' => $upload])
        ->assertStatus(403);
});

// ── Validation ───────────────────────────────────────────────────────

test('missing file fails validation', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->withHeaders(['Accept' => 'application/json'])
        ->post('/api/imports/videopsalm', [])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['file']);
});

test('an unparseable archive returns 422 with an error message', function () {
    $admin = User::factory()->admin()->create();
    // Not a zip at all — just plain bytes.
    $tmp = tempnam(sys_get_temp_dir(), 'badvp_');
    file_put_contents($tmp, 'definitely not a zip');
    $upload = new UploadedFile($tmp, 'broken.vpagd', 'application/octet-stream', null, true);

    $this->actingAs($admin)
        ->withHeaders(['Accept' => 'application/json'])
        ->post('/api/imports/videopsalm', ['file' => $upload])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['file']);
});

// ── Dry-run preview ──────────────────────────────────────────────────

test('dry_run returns a preview list and per-songbook counts without writing', function () {
    $admin = User::factory()->admin()->create();
    $upload = buildVpagdUpload(sampleVpagdEntries());

    $response = $this->actingAs($admin)
        ->post('/api/imports/videopsalm', [
            'file'    => $upload,
            'dry_run' => '1',
        ]);

    $response->assertOk()
        ->assertJsonCount(3, 'songs')
        ->assertJsonStructure([
            'songs'     => [['guid', 'title', 'songbook', 'verse_count']],
            'songbooks',
        ]);

    $body = $response->json();
    $titles = array_column($body['songs'], 'title');
    expect($titles)->toContain('Alpha Song')
        ->toContain('Beta Song')
        ->toContain('Gamma');
    expect($body['songbooks']['Alpha Book'])->toBe(2);
    expect($body['songbooks']['Gamma Book'])->toBe(1);

    // Dry run must not have persisted anything.
    expect(Song::count())->toBe(0);
    expect(Songbook::where('name', 'Alpha Book')->count())->toBe(0);
});

// ── Commit ───────────────────────────────────────────────────────────

test('a real import persists songs, returns 201 with counts and songbook list', function () {
    $admin = User::factory()->admin()->create();
    $upload = buildVpagdUpload(sampleVpagdEntries());

    $response = $this->actingAs($admin)
        ->post('/api/imports/videopsalm', ['file' => $upload]);

    $response->assertCreated()
        ->assertJsonStructure(['created', 'skipped', 'total', 'songbooks'])
        ->assertJsonPath('created', 3)
        ->assertJsonPath('skipped', 0)
        ->assertJsonPath('total', 3);

    expect(Song::count())->toBe(3);
    expect(Songbook::where('name', 'Alpha Book')->count())->toBe(1);
    expect(Songbook::where('name', 'Gamma Book')->count())->toBe(1);

    $beta = Song::where('title', 'Beta Song')->firstOrFail();
    expect($beta->lyrics)->toContain('[Verse 1]')->toContain('[Chorus]');
    expect($beta->created_by)->toBe($admin->id);
});

test('re-importing the same archive skips already-present songs', function () {
    $admin = User::factory()->admin()->create();

    $first = $this->actingAs($admin)
        ->post('/api/imports/videopsalm', ['file' => buildVpagdUpload(sampleVpagdEntries())]);
    $first->assertCreated();

    $second = $this->actingAs($admin)
        ->post('/api/imports/videopsalm', ['file' => buildVpagdUpload(sampleVpagdEntries())]);

    $second->assertCreated()
        ->assertJsonPath('created', 0)
        ->assertJsonPath('skipped', 3)
        ->assertJsonPath('total', 3);

    expect(Song::count())->toBe(3);
});

test('guids[] filter restricts the import to the listed songs', function () {
    $admin = User::factory()->admin()->create();
    $upload = buildVpagdUpload(sampleVpagdEntries());

    $response = $this->actingAs($admin)
        ->post('/api/imports/videopsalm', [
            'file'     => $upload,
            'guids'    => ['g-0', 'g-2'],
        ]);

    $response->assertCreated()
        ->assertJsonPath('created', 2)
        ->assertJsonPath('total', 2);

    expect(Song::pluck('title')->sort()->values()->toArray())
        ->toBe(['Alpha Song', 'Gamma']);
});

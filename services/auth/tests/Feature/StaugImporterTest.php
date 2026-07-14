<?php

use App\Models\Song;
use App\Models\Songbook;
use App\Services\Staug\StaugArchive;
use App\Services\Staug\StaugImporter;
use Illuminate\Support\Str;

/*
 * StaugImporter — non-destructive merge decision table (FR-DI-2). Archives are
 * built as DTOs directly so each row exercises one branch precisely.
 */

function songRecord(array $over = []): array
{
    return array_merge([
        'id'             => Str::uuid()->toString(),
        'title'          => 'A Song',
        'author'         => null,
        'lyrics'         => 'la la',
        'original_key'   => null,
        'tempo'          => null,
        'time_signature' => null,
        'tags'           => [],
        'preview_url'    => null,
        'ccli_number'    => null,
        'version'        => 1,
        'songbook'       => ['id' => 'ignored', 'name' => 'Imported Book', 'description' => null, 'is_default' => false],
        'sheets'         => [],
    ], $over);
}

function fullArchive(array $songs): StaugArchive
{
    return new StaugArchive('full', '1', [], [], $songs);
}

function importer(): StaugImporter
{
    return app(StaugImporter::class);
}

test('an id not in the DB is created, preserving the archived UUID', function () {
    $uuid = Str::uuid()->toString();

    $result = importer()->import(fullArchive([songRecord(['id' => $uuid, 'title' => 'New Song'])]));

    expect($result->created)->toBe(1);
    $song = Song::find($uuid);
    expect($song)->not->toBeNull();
    expect($song->title)->toBe('New Song');
});

test('an exact id+title match is skipped and left untouched', function () {
    $existing = Song::factory()->create(['title' => 'Keeper', 'lyrics' => 'ORIGINAL']);

    $result = importer()->import(fullArchive([
        songRecord(['id' => $existing->id, 'title' => 'Keeper', 'lyrics' => 'INCOMING']),
    ]));

    expect($result->skipped)->toBe(1);
    expect($result->created)->toBe(0);
    expect(Song::whereKey($existing->id)->value('lyrics'))->toBe('ORIGINAL'); // non-destructive
    expect(Song::count())->toBe(1);
});

test('same id with a different title creates a new record and never overwrites', function () {
    $existing = Song::factory()->create(['title' => 'Original Title']);

    $result = importer()->import(fullArchive([
        songRecord(['id' => $existing->id, 'title' => 'Changed Title']),
    ]));

    expect($result->conflicted)->toBe(1);
    expect(Song::whereKey($existing->id)->value('title'))->toBe('Original Title'); // untouched
    // A new, distinct row exists for the incoming title.
    expect(Song::where('title', 'Changed Title')->where('id', '!=', $existing->id)->exists())->toBeTrue();
});

test('a record with no id is created with a fresh UUID', function () {
    $record = songRecord(['title' => 'Anonymous']);
    unset($record['id']);

    $result = importer()->import(fullArchive([$record]));

    expect($result->created)->toBe(1);
    expect(Song::where('title', 'Anonymous')->exists())->toBeTrue();
});

test('a soft-deleted exact match is skipped, not resurrected', function () {
    $song = Song::factory()->create(['title' => 'Deleted One']);
    $song->delete();

    $result = importer()->import(fullArchive([
        songRecord(['id' => $song->id, 'title' => 'Deleted One']),
    ]));

    expect($result->skipped)->toBe(1);
    expect(Song::withTrashed()->find($song->id)->trashed())->toBeTrue(); // still trashed
    expect(Song::find($song->id))->toBeNull();                            // not restored
});

test('songbooks are resolved by name and reused across songs', function () {
    importer()->import(fullArchive([
        songRecord(['title' => 'One', 'songbook' => ['name' => 'Shared Book']]),
        songRecord(['title' => 'Two', 'songbook' => ['name' => 'Shared Book']]),
    ]));

    expect(Songbook::where('name', 'Shared Book')->count())->toBe(1);
});

test('the whole batch rolls back if one row fails', function () {
    // Second record carries an id that is not a valid UUID → the Postgres uuid
    // column rejects it mid-batch; the first (valid) row must roll back too.
    $archive = fullArchive([
        songRecord(['title' => 'Would Be Created']),
        songRecord(['id' => 'not-a-valid-uuid', 'title' => 'Breaks']),
    ]);

    expect(fn () => importer()->import($archive))->toThrow(Illuminate\Database\QueryException::class);
    expect(Song::where('title', 'Would Be Created')->exists())->toBeFalse();
});

test('preview reports per-song actions without writing', function () {
    $existing = Song::factory()->create(['title' => 'Existing']);

    $preview = importer()->preview(fullArchive([
        songRecord(['id' => $existing->id, 'title' => 'Existing']),        // skip
        songRecord(['id' => $existing->id, 'title' => 'Renamed']),         // conflict
        songRecord(['title' => 'Brand New']),                             // create
    ]));

    expect(array_column($preview['songs'], 'action'))->toBe(['skip', 'conflict', 'create']);
    expect(Song::count())->toBe(1); // nothing written
});

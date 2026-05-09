<?php

use App\Models\Song;
use App\Models\Songbook;
use App\Models\User;
use App\Services\VideoPsalm\ParsedSong;
use App\Services\VideoPsalm\ParsedVerse;
use App\Services\VideoPsalm\VpagdImporter;

/*
 * Feature tests for VpagdImporter — the layer that turns ParsedSong DTOs
 * into Song / Songbook rows. Covers:
 *   • new songs are created
 *   • duplicate (case-insensitive title within the same songbook) is skipped
 *   • missing songbook name falls back to the "VideoPsalm Import" bucket
 *   • the same songbook is reused across multiple parsed songs
 *   • the onlyGuids filter restricts the import set
 *   • created_by is propagated onto both Song and Songbook rows
 */

function makeParsed(string $title, ?string $songbook = null, array $verses = [], string $guid = null): ParsedSong
{
    return new ParsedSong(
        index: 0,
        guid: $guid ?? ('guid-' . md5($title)),
        title: $title,
        verses: $verses ?: [new ParsedVerse(text: 'lorem', isChorus: false)],
        songbookName: $songbook,
        songbookGuid: null,
    );
}

test('imports a single new song into a new songbook', function () {
    $admin = User::factory()->admin()->create();
    $importer = new VpagdImporter();

    $result = $importer->import(
        songs: [makeParsed('Song A', 'My Songbook')],
        createdBy: $admin->id,
    );

    expect($result->created)->toBe(1);
    expect($result->skipped)->toBe(0);
    expect($result->total)->toBe(1);

    $songbook = Songbook::where('name', 'My Songbook')->firstOrFail();
    expect($songbook->created_by)->toBe($admin->id);

    $song = Song::where('title', 'Song A')->firstOrFail();
    expect($song->songbook_id)->toBe($songbook->id);
    expect($song->created_by)->toBe($admin->id);
    expect($song->tags)->toContain('videopsalm');
    expect($song->lyrics)->toContain('[Verse 1]');
});

test('uses the fallback songbook when the parsed song has no songbookName', function () {
    $importer = new VpagdImporter();

    $importer->import(songs: [makeParsed('Lone Song', null)]);

    $sb = Songbook::where('name', 'VideoPsalm Import')->firstOrFail();
    expect(Song::where('songbook_id', $sb->id)->count())->toBe(1);
});

test('reuses an existing songbook across multiple imported songs', function () {
    $importer = new VpagdImporter();

    $importer->import(songs: [
        makeParsed('First',  'Shared Book'),
        makeParsed('Second', 'Shared Book'),
        makeParsed('Third',  'Shared Book'),
    ]);

    expect(Songbook::where('name', 'Shared Book')->count())->toBe(1);
    expect(Song::count())->toBe(3);
});

test('skips a song with the same case-insensitive title within the same songbook', function () {
    $importer = new VpagdImporter();
    $sb = Songbook::factory()->create(['name' => 'Existing']);
    Song::factory()->create([
        'title'       => 'Amazing Grace',
        'songbook_id' => $sb->id,
    ]);

    $result = $importer->import(songs: [
        makeParsed('amazing GRACE', 'Existing'),  // dup, different case
        makeParsed('Brand New',     'Existing'),  // novel
    ]);

    expect($result->created)->toBe(1);
    expect($result->skipped)->toBe(1);
    expect(Song::where('songbook_id', $sb->id)->count())->toBe(2);
});

test('the same title in a different songbook is NOT a duplicate', function () {
    $importer = new VpagdImporter();
    $importer->import(songs: [
        makeParsed('Holy Holy', 'Book A'),
        makeParsed('Holy Holy', 'Book B'),
    ]);

    expect(Song::where('title', 'Holy Holy')->count())->toBe(2);
});

test('onlyGuids filter restricts which parsed songs get persisted', function () {
    $importer = new VpagdImporter();

    $a = makeParsed('A', 'Book', guid: 'guid-A');
    $b = makeParsed('B', 'Book', guid: 'guid-B');
    $c = makeParsed('C', 'Book', guid: 'guid-C');

    $result = $importer->import(
        songs: [$a, $b, $c],
        onlyGuids: ['guid-A', 'guid-C'],
    );

    expect($result->created)->toBe(2);
    expect($result->total)->toBe(2);
    expect(Song::pluck('title')->sort()->values()->toArray())->toBe(['A', 'C']);
});

test('result.songbooks lists the names actually touched', function () {
    $importer = new VpagdImporter();

    $result = $importer->import(songs: [
        makeParsed('A', 'Book One'),
        makeParsed('B', 'Book Two'),
        makeParsed('C', 'Book One'),  // dup-bookname not dup-song
    ]);

    expect($result->songbooks)->toHaveCount(2);
    expect($result->songbooks)->toContain('Book One');
    expect($result->songbooks)->toContain('Book Two');
});

test('lyrics body is composed from the parsed verses', function () {
    $importer = new VpagdImporter();

    $importer->import(songs: [
        new ParsedSong(
            index: 0,
            guid: 'g',
            title: 'Lyric Test',
            verses: [
                new ParsedVerse(text: 'first verse line', isChorus: false),
                new ParsedVerse(text: 'chorus body',      isChorus: true),
                new ParsedVerse(text: 'second verse',     isChorus: false),
            ],
            songbookName: 'Test',
            songbookGuid: null,
        ),
    ]);

    $song = Song::where('title', 'Lyric Test')->firstOrFail();
    expect($song->lyrics)->toContain('[Verse 1]');
    expect($song->lyrics)->toContain('[Chorus]');
    expect($song->lyrics)->toContain('[Verse 2]');
    expect($song->lyrics)->toContain('first verse line');
    expect($song->lyrics)->toContain('chorus body');
    expect($song->lyrics)->toContain('second verse');
});

test('empty songs array yields an empty result without errors', function () {
    $importer = new VpagdImporter();
    $result = $importer->import(songs: []);

    expect($result->created)->toBe(0);
    expect($result->skipped)->toBe(0);
    expect($result->total)->toBe(0);
    expect($result->songbooks)->toBe([]);
    expect(Song::count())->toBe(0);
});

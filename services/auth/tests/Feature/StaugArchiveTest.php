<?php

use App\Models\Playlist;
use App\Models\PlaylistItem;
use App\Models\Song;
use App\Models\Songbook;
use App\Services\Staug\StaugArchiveReader;
use App\Services\Staug\StaugArchiveWriter;
use App\Services\Staug\StaugSigner;
use App\Services\Staug\StaugValidationException;

/*
 * StaugArchiveWriter + StaugArchiveReader — round-trip and adversarial
 * verification (FR-DX-1..4, FR-DI-1).
 */

function writer(): StaugArchiveWriter
{
    return app(StaugArchiveWriter::class);
}

function reader(): StaugArchiveReader
{
    return app(StaugArchiveReader::class);
}

/** Mutate an existing zip in place via a callback receiving the open handle. */
function mutateZip(string $path, callable $fn): void
{
    $zip = new ZipArchive();
    expect($zip->open($path))->toBeTrue();
    $fn($zip);
    $zip->close();
}

test('playlist round-trips: songs get files, scripture stays inline', function () {
    $playlist = Playlist::factory()->create(['name' => 'Sunday']);
    $song = Song::factory()->create(['title' => 'Amazing Grace']);
    PlaylistItem::factory()->create([
        'playlist_id' => $playlist->id, 'song_id' => $song->id, 'position' => 0,
    ]);
    PlaylistItem::factory()->scripture(['end_chapter' => 4, 'end_verse' => 2])->create([
        'playlist_id' => $playlist->id, 'position' => 1,
    ]);

    $path = writer()->writePlaylist($playlist);
    $archive = reader()->open($path);

    expect($archive->type)->toBe('playlist');
    expect($archive->songs)->toHaveCount(1);
    expect($archive->songs[0]['id'])->toBe($song->id);
    expect($archive->container['items'])->toHaveCount(2);
    expect($archive->container['items'][1]['item_type'])->toBe('scripture');
    expect($archive->container['items'][1]['scripture']['reference'])->toBe('JHN 3:16-4:2');
});

test('songbook round-trips with all its songs', function () {
    $songbook = Songbook::factory()->create(['name' => 'Hymns']);
    Song::factory()->count(3)->create(['songbook_id' => $songbook->id]);

    $archive = reader()->open(writer()->writeSongbook($songbook));

    expect($archive->type)->toBe('songbook');
    expect($archive->songs)->toHaveCount(3);
    expect($archive->container['name'])->toBe('Hymns');
});

test('full export round-trips with songs and songbooks only', function () {
    Song::factory()->count(2)->create();

    $archive = reader()->open(writer()->writeFull());

    expect($archive->type)->toBe('full');
    expect(count($archive->songs))->toBe(2);
    expect($archive->songbooks)->not->toBeEmpty();
});

test('the stored manifest.sig is an HMAC of the exact stored manifest bytes', function () {
    $song = Song::factory()->create();
    $playlist = Playlist::factory()->create();
    PlaylistItem::factory()->create(['playlist_id' => $playlist->id, 'song_id' => $song->id, 'position' => 0]);

    $path = writer()->writePlaylist($playlist);

    $zip = new ZipArchive();
    $zip->open($path);
    $bytes = $zip->getFromName('manifest.json');
    $sig = $zip->getFromName('manifest.sig');
    $zip->close();

    expect((new StaugSigner())->verify($bytes, $sig))->toBeTrue();
});

test('reader rejects a tampered song body', function () {
    $song = Song::factory()->create();
    $sb = $song->songbook;
    $path = writer()->writeSongbook($sb);

    mutateZip($path, function (ZipArchive $zip) use ($song) {
        $zip->addFromString("songs/{$song->id}.json", '{"id":"'.$song->id.'","title":"HACKED"}');
    });

    reader()->open($path);
})->throws(StaugValidationException::class);

test('reader rejects a missing signature', function () {
    $song = Song::factory()->create();
    $path = writer()->writeSongbook($song->songbook);

    mutateZip($path, fn (ZipArchive $zip) => $zip->deleteName('manifest.sig'));

    reader()->open($path);
})->throws(StaugValidationException::class);

test('reader rejects an archive signed with a different key', function () {
    $song = Song::factory()->create();
    $path = writer()->writeSongbook($song->songbook);

    config(['staug.signing_key' => 'a-totally-different-key-also-32-characters!']);

    reader()->open($path);
})->throws(StaugValidationException::class, 'signature is invalid');

test('reader rejects an unlisted extra song file', function () {
    $song = Song::factory()->create();
    $path = writer()->writeSongbook($song->songbook);

    mutateZip($path, fn (ZipArchive $zip) => $zip->addFromString(
        'songs/00000000-0000-0000-0000-000000000000.json',
        '{"id":"x"}',
    ));

    reader()->open($path);
})->throws(StaugValidationException::class, 'unlisted');

test('reader rejects a zip-slip entry name', function () {
    $song = Song::factory()->create();
    $path = writer()->writeSongbook($song->songbook);

    mutateZip($path, fn (ZipArchive $zip) => $zip->addFromString('../evil.json', 'x'));

    reader()->open($path);
})->throws(StaugValidationException::class, 'unsafe');

test('reader rejects a manifest referencing a missing song file', function () {
    $song = Song::factory()->create();
    $path = writer()->writeSongbook($song->songbook);

    mutateZip($path, fn (ZipArchive $zip) => $zip->deleteName("songs/{$song->id}.json"));

    reader()->open($path);
})->throws(StaugValidationException::class);

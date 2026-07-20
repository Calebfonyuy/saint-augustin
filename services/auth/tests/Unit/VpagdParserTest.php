<?php

use App\Services\VideoPsalm\VpagdParser;

/*
 * Unit tests for VpagdParser. We build small synthetic .vpagd zips on the fly
 * — much faster than parsing the real 11 MB sample for every test, and lets
 * us exercise edge cases (missing songbook file, empty Verses, Tag:1) in
 * isolation. One integration test against the real sample lives in
 * VpagdParserSampleTest so the synthetic fixtures don't drift from reality.
 */

/**
 * Build a temporary .vpagd zip with the provided entries. Returns the path
 * (caller is responsible for unlinking, or just let the OS reap /tmp).
 *
 * @param array<string, string> $entries entry name => raw content
 */
function buildVpagdZip(array $entries): string
{
    $path = tempnam(sys_get_temp_dir(), 'vpagd_') . '.vpagd';
    $zip  = new ZipArchive();
    if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException("Could not create temp zip at {$path}");
    }
    foreach ($entries as $name => $content) {
        $zip->addFromString($name, $content);
    }
    $zip->close();
    return $path;
}

test('throws when the file does not exist', function () {
    expect(fn () => VpagdParser::parseFile('/nope/does/not/exist.vpagd'))
        ->toThrow(RuntimeException::class, 'VPAGD file not found');
});

test('throws when the file is not a valid zip', function () {
    $path = tempnam(sys_get_temp_dir(), 'vpagd_') . '.vpagd';
    file_put_contents($path, 'not a zip');
    expect(fn () => VpagdParser::parseFile($path))
        ->toThrow(RuntimeException::class, 'Could not open VPAGD');
    unlink($path);
});

test('parses a single song with title, guid, and verses', function () {
    $path = buildVpagdZip([
        'Song_0.json' => '{Guid:"abc-123",Text:"Test Song",Verses:[{ID:0,Text:"first line"},{ID:1,Text:"second line"}]}',
        'SongBook_0.json' => '{Guid:"sb-1",Text:"Test Songbook"}',
    ]);

    $songs = VpagdParser::parseFile($path);

    expect($songs)->toHaveCount(1);
    expect($songs[0]->title)->toBe('Test Song');
    expect($songs[0]->guid)->toBe('abc-123');
    expect($songs[0]->verses)->toHaveCount(2);
    expect($songs[0]->verses[0]->text)->toBe('first line');
    expect($songs[0]->verses[0]->isChorus)->toBeFalse();
    expect($songs[0]->songbookName)->toBe('Test Songbook');
    expect($songs[0]->songbookGuid)->toBe('sb-1');

    unlink($path);
});

test('marks Tag:1 verses as choruses', function () {
    $path = buildVpagdZip([
        'Song_0.json' => '{Text:"With Chorus",Verses:[{Text:"verse one"},{Tag:1,Text:"the chorus"},{Text:"verse two"}]}',
    ]);

    $songs = VpagdParser::parseFile($path);
    $verses = $songs[0]->verses;

    expect($verses[0]->isChorus)->toBeFalse()
        ->and($verses[1]->isChorus)->toBeTrue()
        ->and($verses[2]->isChorus)->toBeFalse();

    unlink($path);
});

test('walks Song_N indices until a missing entry stops iteration', function () {
    $path = buildVpagdZip([
        'Song_0.json' => '{Text:"Zero",Verses:[]}',
        'Song_1.json' => '{Text:"One",Verses:[]}',
        'Song_2.json' => '{Text:"Two",Verses:[]}',
        // intentionally skip Song_3 — Song_4 should NOT be picked up.
        'Song_4.json' => '{Text:"Four",Verses:[]}',
    ]);

    $songs = VpagdParser::parseFile($path);
    expect($songs)->toHaveCount(3);
    expect(array_map(fn ($s) => $s->title, $songs))->toBe(['Zero', 'One', 'Two']);

    unlink($path);
});

test('falls back to a default title and guid when the source omits them', function () {
    $path = buildVpagdZip([
        'Song_0.json' => '{Verses:[{Text:"orphan"}]}',
    ]);

    $songs = VpagdParser::parseFile($path);
    expect($songs[0]->title)->toBe('Untitled #0');
    expect($songs[0]->guid)->toBe('vp-0');

    unlink($path);
});

test('songbook fields are null when SongBook_N is missing', function () {
    $path = buildVpagdZip([
        'Song_0.json' => '{Text:"Loose Song",Verses:[]}',
    ]);

    $songs = VpagdParser::parseFile($path);
    expect($songs[0]->songbookName)->toBeNull();
    expect($songs[0]->songbookGuid)->toBeNull();

    unlink($path);
});

test('skips verses whose Text is missing or empty', function () {
    $path = buildVpagdZip([
        'Song_0.json' => '{Text:"Has Empty",Verses:[{Text:"keep"},{Text:""},{ID:99},{Text:"   "}]}',
    ]);

    $songs = VpagdParser::parseFile($path);
    expect($songs[0]->verses)->toHaveCount(1);
    expect($songs[0]->verses[0]->text)->toBe('keep');

    unlink($path);
});

test('preserves raw newlines inside verse text', function () {
    $path = buildVpagdZip([
        'Song_0.json' => "{Text:\"Multiline\",Verses:[{Text:\"line one\nline two\nline three\"}]}",
    ]);

    $songs = VpagdParser::parseFile($path);
    expect($songs[0]->verses[0]->text)->toContain("line one\nline two\nline three");

    unlink($path);
});

test('lyrics() formats verses with [Verse N] / [Chorus] headers', function () {
    $path = buildVpagdZip([
        'Song_0.json' => '{Text:"Format",Verses:[{Text:"first"},{Tag:1,Text:"chorus"},{Text:"second"}]}',
    ]);

    $lyrics = VpagdParser::parseFile($path)[0]->lyrics();

    expect($lyrics)->toContain('[Verse 1]')
        ->toContain('[Chorus]')
        ->toContain('[Verse 2]')
        ->toContain('first')
        ->toContain('chorus')
        ->toContain('second');

    unlink($path);
});

test('lyrics() strips inline HTML-ish formatting tags', function () {
    $path = buildVpagdZip([
        'Song_0.json' => '{Text:"Tagged",Verses:[{Text:"<b><i><cFFFFFF66>colored</c></i></b> plain"}]}',
    ]);

    $lyrics = VpagdParser::parseFile($path)[0]->lyrics();
    expect($lyrics)->toContain('colored plain')
        ->not->toContain('<b>')
        ->not->toContain('<cFFFFFF66>')
        ->not->toContain('</c>');

    unlink($path);
});

test('lyrics() normalises CRLF line endings to LF', function () {
    $path = buildVpagdZip([
        'Song_0.json' => '{Text:"CRLF",Verses:[{Text:"a\\r\\nb\\r\\nc"}]}',
    ]);

    $lyrics = VpagdParser::parseFile($path)[0]->lyrics();
    expect($lyrics)->not->toContain("\r");
    expect($lyrics)->toContain("a\nb\nc");

    unlink($path);
});

test('returns an empty list for an archive with no Song_*.json', function () {
    $path = buildVpagdZip([
        'Version.json' => '1',
        'README.txt'   => 'no songs here',
    ]);

    $songs = VpagdParser::parseFile($path);
    expect($songs)->toBe([]);

    unlink($path);
});

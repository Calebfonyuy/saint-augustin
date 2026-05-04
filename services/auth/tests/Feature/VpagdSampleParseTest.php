<?php

use App\Services\VideoPsalm\VpagdParser;

/*
 * Integration test that parses the real VideoPsalm sample committed under
 * storage/sample_videopsalm_file.vpagd. This exists so the synthetic-fixture
 * unit tests in VpagdParserTest don't drift from the actual format produced
 * by VideoPsalm 8.x. If the sample is removed (it's gitignored material) the
 * test self-skips so CI on a clean checkout still passes.
 */

test('parser handles the real VideoPsalm sample archive', function () {
    $path = storage_path('sample_videopsalm_file.vpagd');
    if (! is_file($path)) {
        $this->markTestSkipped('Sample VPAGD archive not present at storage/sample_videopsalm_file.vpagd');
    }

    $songs = VpagdParser::parseFile($path);

    // The sample bundle contains ~380 songs — guard with a generous lower
    // bound so future edits to the sample don't break the test.
    expect(count($songs))->toBeGreaterThan(100);

    // Every parsed song should have a title and at least one verse.
    foreach ($songs as $s) {
        expect($s->title)->not->toBe('');
        expect($s->verses)->not->toBe([]);
    }

    // Smoke-check that lyrics() produces non-empty bodies with section
    // headers — confirms ParsedSong's formatting is wired end-to-end.
    $first = $songs[0];
    expect($first->lyrics())->toContain('[Verse 1]');
});

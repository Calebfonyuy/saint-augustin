<?php

use App\Models\BibleBook;
use App\Services\Bible\ScriptureReference;
use App\Services\Bible\ScriptureResolver;
use Illuminate\Support\Facades\Http;

/*
 * ScriptureResolver — chapter fetch + slice (incl. cross-chapter) + Redis
 * cache resilience (FR-BI-4/6). HelloAO is faked.
 */

beforeEach(function () {
    BibleBook::factory()->create([
        'translation_id' => 'BSB', 'book_code' => 'JHN', 'name' => 'Jean', 'chapter_count' => 21,
    ]);

    Http::fake([
        '*/BSB/JHN/3.json' => Http::response(['chapter' => ['number' => 3, 'content' => [
            ['type' => 'heading', 'content' => ['Nicodemus']],
            ['type' => 'verse', 'number' => 16, 'content' => ['For God so loved the world', ['noteId' => 0]]],
            ['type' => 'verse', 'number' => 17, 'content' => ['For God did not send his Son']],
        ]]]),
        '*/BSB/JHN/4.json' => Http::response(['chapter' => ['number' => 4, 'content' => [
            ['type' => 'verse', 'number' => 1, 'content' => ['Now Jesus learned']],
            ['type' => 'verse', 'number' => 2, 'content' => ['although in fact it was not Jesus']],
        ]]]),
    ]);
});

function resolver(): ScriptureResolver
{
    return app(ScriptureResolver::class);
}

test('resolves a single verse with a localized label', function () {
    $ref = new ScriptureReference('JHN', 3, 16, 3, 16);

    $out = resolver()->resolve($ref, 'BSB', 'Berean Standard Bible');

    expect($out->referenceLabel)->toBe('Jean 3:16');
    expect($out->translationLabel)->toBe('Berean Standard Bible');
    expect($out->verses)->toHaveCount(1);
    expect($out->verses[0])->toMatchArray(['chapter' => 3, 'number' => 16]);
    // Footnote marker dropped, text flattened.
    expect($out->verses[0]['text'])->toBe('For God so loved the world');
});

test('resolves a cross-chapter range, trimming first and last chapters', function () {
    $ref = new ScriptureReference('JHN', 3, 16, 4, 2);

    $out = resolver()->resolve($ref, 'BSB');

    expect($out->referenceLabel)->toBe('Jean 3:16-4:2');
    expect(array_map(fn ($v) => "{$v['chapter']}:{$v['number']}", $out->verses))
        ->toBe(['3:16', '3:17', '4:1', '4:2']);
});

test('the chapter cache serves a repeat resolve without a second HTTP call', function () {
    $ref = new ScriptureReference('JHN', 3, 16, 3, 16);

    resolver()->resolve($ref, 'BSB');
    resolver()->resolve($ref, 'BSB');

    Http::assertSentCount(1); // second resolve hit the Redis cache
});

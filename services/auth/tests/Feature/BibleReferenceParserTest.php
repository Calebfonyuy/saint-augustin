<?php

use App\Services\Bible\BibleReferenceException;
use App\Services\Bible\BookMap;
use App\Services\Bible\ReferenceParser;

/*
 * BookMap + ReferenceParser — French/English name resolution and freeform
 * reference parsing (FR-BI-5).
 */

function parser(): ReferenceParser
{
    return new ReferenceParser();
}

// ── BookMap ──────────────────────────────────────────────────────────

test('book map resolves French names, English names, and abbreviations', function () {
    expect(BookMap::toUsfm('Jean'))->toBe('JHN');
    expect(BookMap::toUsfm('John'))->toBe('JHN');
    expect(BookMap::toUsfm('Jn'))->toBe('JHN');
    expect(BookMap::toUsfm('Ésaïe'))->toBe('ISA');
    expect(BookMap::toUsfm('esaie'))->toBe('ISA'); // accent- and case-insensitive
    expect(BookMap::toUsfm('Cantique des cantiques'))->toBe('SNG');
});

test('book map resolves numbered books in arabic and roman forms', function () {
    expect(BookMap::toUsfm('1 Corinthiens'))->toBe('1CO');
    expect(BookMap::toUsfm('1Co'))->toBe('1CO');
    expect(BookMap::toUsfm('I Corinthiens'))->toBe('1CO');
    expect(BookMap::toUsfm('2 Timothée'))->toBe('2TI');
    expect(BookMap::toUsfm('3 Jean'))->toBe('3JN');
});

test('book map returns null for an unknown book', function () {
    expect(BookMap::toUsfm('Hogwarts'))->toBeNull();
});

// ── ReferenceParser ──────────────────────────────────────────────────

test('parses a single verse', function () {
    $r = parser()->parse('Jean 3:16');
    expect([$r->bookCode, $r->startChapter, $r->startVerse, $r->endChapter, $r->endVerse])
        ->toBe(['JHN', 3, 16, 3, 16]);
});

test('parses a same-chapter verse range', function () {
    $r = parser()->parse('Jean 3:16-18');
    expect([$r->startChapter, $r->startVerse, $r->endChapter, $r->endVerse])
        ->toBe([3, 16, 3, 18]);
});

test('parses a cross-chapter range', function () {
    $r = parser()->parse('Jean 3:16-4:2');
    expect([$r->startChapter, $r->startVerse, $r->endChapter, $r->endVerse])
        ->toBe([3, 16, 4, 2]);
});

test('parses a whole chapter (no verse) as start verse 1 to end', function () {
    $r = parser()->parse('Psaume 23');
    expect([$r->bookCode, $r->startChapter, $r->startVerse, $r->endChapter, $r->endVerse])
        ->toBe(['PSA', 23, 1, 23, null]);
});

test('parses a numbered book with a chapter', function () {
    $r = parser()->parse('1 Corinthiens 13');
    expect([$r->bookCode, $r->startChapter, $r->endVerse])->toBe(['1CO', 13, null]);
});

test('throws on an unknown book', function () {
    parser()->parse('Hogwarts 3:16');
})->throws(BibleReferenceException::class);

test('throws on unparseable input', function () {
    parser()->parse('not a reference');
})->throws(BibleReferenceException::class);

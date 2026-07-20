<?php

namespace App\Services\Bible;

use App\Models\BibleBook;

/**
 * Resolves a ScriptureReference to its verses (SRS FR-BI-4/6), fetching each
 * spanned chapter through HelloAoClient (Redis-cached) and slicing — including
 * cross-chapter ranges, where the first and last chapters are trimmed and any
 * middle chapters are taken whole. Builds the localized reference label (e.g.
 * "Jean 3:16-4:2") from the cached book name.
 */
class ScriptureResolver
{
    public function __construct(private readonly HelloAoClient $client)
    {
    }

    public function resolve(
        ScriptureReference $ref,
        string $translationId,
        ?string $translationLabel = null,
    ): ResolvedScripture {
        $verses = [];

        foreach ($ref->chapters() as $chapter) {
            $chapterVerses = $this->client->chapter($translationId, $ref->bookCode, $chapter);

            $byNumber = [];
            $maxVerse = 0;
            foreach ($chapterVerses as $v) {
                $byNumber[$v['number']] = $v['text'];
                $maxVerse = max($maxVerse, $v['number']);
            }

            $from = $chapter === $ref->startChapter ? $ref->startVerse : 1;
            $to = $chapter === $ref->endChapter ? ($ref->endVerse ?? $maxVerse) : $maxVerse;

            for ($n = $from; $n <= $to; $n++) {
                if (! isset($byNumber[$n])) {
                    continue; // some editions omit a verse number — skip gaps
                }
                $verses[] = ['chapter' => $chapter, 'number' => $n, 'text' => $byNumber[$n]];
            }
        }

        if ($verses === []) {
            throw new BibleReferenceException('No verses found for the requested reference.');
        }

        $bookName = $this->bookName($translationId, $ref->bookCode);

        // Pin the end to the last verse actually returned, so a whole-chapter
        // or open reference is stored as a concrete, re-resolvable span.
        $last = $verses[count($verses) - 1];
        $concrete = new ScriptureReference(
            $ref->bookCode,
            $ref->startChapter,
            $ref->startVerse,
            $last['chapter'],
            $last['number'],
        );

        return new ResolvedScripture(
            referenceLabel: $this->referenceLabel($bookName, $ref),
            translationLabel: $translationLabel ?? $translationId,
            translationId: $translationId,
            verses: $verses,
            reference: $concrete,
        );
    }

    private function bookName(string $translationId, string $bookCode): string
    {
        $name = BibleBook::query()
            ->where('translation_id', $translationId)
            ->where('book_code', $bookCode)
            ->value('name');

        return is_string($name) && $name !== '' ? $name : $bookCode;
    }

    private function referenceLabel(string $book, ScriptureReference $ref): string
    {
        // Whole chapter / chapter range (no explicit end verse).
        if ($ref->endVerse === null) {
            return $ref->startChapter === $ref->endChapter
                ? "{$book} {$ref->startChapter}"
                : "{$book} {$ref->startChapter}-{$ref->endChapter}";
        }

        // Single verse.
        if ($ref->startChapter === $ref->endChapter && $ref->startVerse === $ref->endVerse) {
            return "{$book} {$ref->startChapter}:{$ref->startVerse}";
        }

        // Same-chapter verse range.
        if ($ref->startChapter === $ref->endChapter) {
            return "{$book} {$ref->startChapter}:{$ref->startVerse}-{$ref->endVerse}";
        }

        // Cross-chapter range.
        return "{$book} {$ref->startChapter}:{$ref->startVerse}-{$ref->endChapter}:{$ref->endVerse}";
    }
}

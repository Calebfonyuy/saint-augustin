<?php

namespace App\Services\Bible;

/**
 * A resolved-to-USFM scripture reference span. `endVerse === null` means
 * "to the last verse of endChapter" (a whole-chapter or chapter-range
 * reference). For a single verse, end == start.
 */
final readonly class ScriptureReference
{
    public function __construct(
        public string $bookCode,
        public int $startChapter,
        public int $startVerse,
        public int $endChapter,
        public ?int $endVerse,
    ) {
    }

    /** The distinct chapter numbers this reference spans (inclusive). */
    /** @return list<int> */
    public function chapters(): array
    {
        return range($this->startChapter, $this->endChapter);
    }

    public function isSingleVerse(): bool
    {
        return $this->startChapter === $this->endChapter
            && $this->endVerse !== null
            && $this->startVerse === $this->endVerse;
    }
}

<?php

namespace App\Services\Bible;

/**
 * A resolved reading: the verses of a reference plus display labels. Verse
 * text is transient (fetched + Redis-cached), never persisted.
 */
final readonly class ResolvedScripture
{
    /**
     * @param  list<array{chapter: int, number: int, text: string}>  $verses
     */
    public function __construct(
        public string $referenceLabel,
        public string $translationLabel,
        public string $translationId,
        public array $verses,
        public ScriptureReference $reference,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'reference_label'   => $this->referenceLabel,
            'translation_label' => $this->translationLabel,
            'translation_id'    => $this->translationId,
            'verses'            => $this->verses,
            // Concrete parsed span, so a freeform entry can be stored as a
            // playlist item (and re-resolved to the same verses).
            'reference'         => [
                'book_code'     => $this->reference->bookCode,
                'start_chapter' => $this->reference->startChapter,
                'start_verse'   => $this->reference->startVerse,
                'end_chapter'   => $this->reference->endChapter,
                'end_verse'     => $this->reference->endVerse,
            ],
        ];
    }
}

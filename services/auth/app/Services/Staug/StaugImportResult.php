<?php

namespace App\Services\Staug;

/**
 * Summary of a STAUG import (mirrors VideoPsalm's ImportResult shape, plus a
 * `conflicted` count for id-present/title-differs rows that were imported as
 * fresh records rather than overwriting an existing song).
 */
final readonly class StaugImportResult
{
    /** @param list<string> $songbooks */
    public function __construct(
        public int $created,
        public int $skipped,
        public int $conflicted,
        public int $total,
        public array $songbooks,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'created'    => $this->created,
            'skipped'    => $this->skipped,
            'conflicted' => $this->conflicted,
            'total'      => $this->total,
            'songbooks'  => $this->songbooks,
        ];
    }
}

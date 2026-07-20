<?php

namespace App\Services\VideoPsalm;

/**
 * Outcome of running a `VpagdImporter::import()`.
 *
 * `created + skipped` does *not* always equal `total` if the importer ever
 * starts rejecting malformed rows — keep both fields exposed so the UI can
 * surface a "X failed validation" line in the future without breaking the
 * existing JSON shape.
 */
final readonly class ImportResult
{
    /**
     * @param list<string> $songbooks Names of songbooks created or reused.
     */
    public function __construct(
        public int $created,
        public int $skipped,
        public int $total,
        public array $songbooks,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'created'   => $this->created,
            'skipped'   => $this->skipped,
            'total'     => $this->total,
            'songbooks' => $this->songbooks,
        ];
    }
}

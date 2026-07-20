<?php

namespace App\Services\Staug;

/**
 * A verified, decoded STAUG archive returned by StaugArchiveReader::open().
 *
 * By the time an instance of this exists, the manifest signature and every
 * per-song sha256 have been checked, so consumers (StaugImporter, the import
 * preview) can trust the contents. `songs` are the decoded song-record bodies
 * (each includes its `id`, attributes, embedded `songbook` ref, and `sheets`
 * reference metadata). `container` and `songbooks` are the manifest sections.
 */
final readonly class StaugArchive
{
    /**
     * @param  array<string, mixed>  $container
     * @param  list<array<string, mixed>>  $songbooks
     * @param  list<array<string, mixed>>  $songs
     */
    public function __construct(
        public string $type,
        public string $version,
        public array $container,
        public array $songbooks,
        public array $songs,
    ) {
    }
}

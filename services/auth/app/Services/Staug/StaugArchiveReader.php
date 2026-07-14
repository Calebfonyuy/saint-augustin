<?php

namespace App\Services\Staug;

use ZipArchive;

/**
 * Opens and verifies a STAUG archive (SRS FR-DI-1). The reader is
 * deliberately adversarial: it treats the upload as hostile and rejects on
 * the first failure, before any body is decoded for import.
 *
 * Verification order:
 *   1. manifest.json + manifest.sig both present.
 *   2. HMAC over the raw manifest bytes matches the sidecar signature
 *      (StaugSigner throws separately if the signing key is unconfigured).
 *   3. manifest is well-formed (format/version/type).
 *   4. every zip entry name is safe (no traversal) and in the allowed set;
 *      the set of physical songs/*.json equals the manifest's songs[].file
 *      list — no unlisted extras, no listed-but-missing files.
 *   5. each song file's sha256 matches the manifest.
 *   6. only then are song bodies decoded.
 */
class StaugArchiveReader
{
    private const ALLOWED_TYPES = ['playlist', 'songbook', 'full'];

    /** Reject absurd archives up front (zip-bomb / abuse guards). */
    private const MAX_ENTRIES = 100_000;

    private const MAX_ENTRY_BYTES = 50 * 1024 * 1024;

    private const SONG_ENTRY_PATTERN = '#^songs/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\.json$#';

    public function __construct(private readonly StaugSigner $signer)
    {
    }

    public function open(string $zipPath): StaugArchive
    {
        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            throw new StaugValidationException('Could not open the archive (not a valid ZIP).');
        }

        try {
            return $this->read($zip);
        } finally {
            $zip->close();
        }
    }

    private function read(ZipArchive $zip): StaugArchive
    {
        if ($zip->numFiles > self::MAX_ENTRIES) {
            throw new StaugValidationException('Archive has too many entries.');
        }

        // (1) manifest + signature present.
        $manifestBytes = $zip->getFromName('manifest.json');
        $signatureRaw = $zip->getFromName('manifest.sig');
        if ($manifestBytes === false || $signatureRaw === false) {
            throw new StaugValidationException('Archive is missing its manifest or signature.');
        }

        // (2) signature over the RAW manifest bytes (never a re-serialization).
        if (! $this->signer->verify($manifestBytes, trim($signatureRaw))) {
            throw new StaugValidationException('Archive signature is invalid.');
        }

        // (3) manifest shape. Decode a COPY for field access only.
        $manifest = json_decode($manifestBytes, true);
        if (! is_array($manifest)
            || ($manifest['format'] ?? null) !== 'STAUG'
            || ! is_string($manifest['version'] ?? null)
            || ! in_array($manifest['type'] ?? null, self::ALLOWED_TYPES, true)
            || ! is_array($manifest['songs'] ?? null)) {
            throw new StaugValidationException('Archive manifest is malformed.');
        }

        // (4) entry-name safety + set-equality of song files.
        $this->assertEntriesSafe($zip, $manifest['songs']);

        // (5) per-song sha256, then (6) decode bodies.
        $songs = [];
        foreach ($manifest['songs'] as $entry) {
            if (! is_array($entry) || ! isset($entry['file'], $entry['sha256'], $entry['id'])) {
                throw new StaugValidationException('Archive manifest has a malformed song entry.');
            }

            $body = $zip->getFromName($entry['file']);
            if ($body === false) {
                throw new StaugValidationException("Archive is missing song file {$entry['file']}.");
            }
            if (! hash_equals((string) $entry['sha256'], hash('sha256', $body))) {
                throw new StaugValidationException("Song file {$entry['file']} failed its integrity check.");
            }

            $record = json_decode($body, true);
            if (! is_array($record) || ($record['id'] ?? null) !== $entry['id']) {
                throw new StaugValidationException("Song file {$entry['file']} is malformed.");
            }
            $songs[] = $record;
        }

        return new StaugArchive(
            type: $manifest['type'],
            version: $manifest['version'],
            container: is_array($manifest['container'] ?? null) ? $manifest['container'] : [],
            songbooks: is_array($manifest['songbooks'] ?? null) ? array_values($manifest['songbooks']) : [],
            songs: $songs,
        );
    }

    /**
     * Every entry must be `manifest.json`, `manifest.sig`, or a safe
     * `songs/<uuid>.json`; the physical song files must exactly match the
     * manifest's declared list (no unlisted extras that would ride along
     * unsigned-in-spirit, no listed files that are absent).
     *
     * @param  list<mixed>  $manifestSongs
     */
    private function assertEntriesSafe(ZipArchive $zip, array $manifestSongs): void
    {
        $listed = [];
        foreach ($manifestSongs as $entry) {
            if (is_array($entry) && is_string($entry['file'] ?? null)) {
                $listed[$entry['file']] = true;
            }
        }

        $physicalSongFiles = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if ($name === false) {
                throw new StaugValidationException('Archive has an unreadable entry.');
            }

            // Path-traversal / absolute-path guard.
            if (str_contains($name, '..') || str_starts_with($name, '/') || str_contains($name, '\\')) {
                throw new StaugValidationException("Archive contains an unsafe entry name: {$name}");
            }

            $stat = $zip->statIndex($i);
            if ($stat !== false && $stat['size'] > self::MAX_ENTRY_BYTES) {
                throw new StaugValidationException("Archive entry {$name} is too large.");
            }

            if ($name === 'manifest.json' || $name === 'manifest.sig') {
                continue;
            }

            if (! preg_match(self::SONG_ENTRY_PATTERN, $name)) {
                throw new StaugValidationException("Archive contains an unexpected entry: {$name}");
            }

            if (! isset($listed[$name])) {
                throw new StaugValidationException("Archive contains an unlisted song file: {$name}");
            }
            $physicalSongFiles[$name] = true;
        }

        foreach ($listed as $file => $_) {
            if (! isset($physicalSongFiles[$file])) {
                throw new StaugValidationException("Archive manifest references a missing song file: {$file}");
            }
        }
    }
}

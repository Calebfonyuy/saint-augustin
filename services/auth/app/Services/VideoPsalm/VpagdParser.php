<?php

namespace App\Services\VideoPsalm;

use RuntimeException;
use ZipArchive;

/**
 * Reads a VideoPsalm `.vpagd` archive and produces a normalised list of
 * songs ready for import.
 *
 * Archive layout (observed from VideoPsalm 8.x exports):
 *   Version.json                — single integer, the format version
 *   Song_0.json … Song_{N-1}    — one file per song
 *   SongBook_0.json … SongBook_{N-1}
 *                               — paired index file: tells which songbook
 *                                 the same-numbered song belongs to. Songs
 *                                 in the same songbook share a Guid here.
 *   *Style.json, AgendaItemProperties.json, Images/, Videos/
 *                               — visual styling and media assets we don't
 *                                 use; ignored.
 *
 * The Song_N.json schema we care about:
 *   { Guid: "<base64 GUID>",
 *     Verses: [
 *       { Text: "raw\nlyrics" },
 *       { ID: 2, Text: "..." },
 *       { Tag: 1, ID: 0, Text: "chorus..." }
 *     ],
 *     Text: "Song title" }
 *
 * `Tag: 1` marks a chorus / refrain (per VideoPsalm's Quick Verse Editor).
 * Anything else is rendered as a numbered verse. We drop Style, Color and
 * other formatting fields — they don't survive the roundtrip into our
 * `lyrics` column anyway.
 */
final class VpagdParser
{
    /**
     * Parse a `.vpagd` file at $path and return a list of ParsedSong DTOs.
     *
     * @return list<ParsedSong>
     */
    public static function parseFile(string $path): array
    {
        if (! is_file($path)) {
            throw new RuntimeException("VPAGD file not found: {$path}");
        }

        $zip = new ZipArchive();
        $rc = $zip->open($path);
        if ($rc !== true) {
            throw new RuntimeException("Could not open VPAGD archive (zip error {$rc}).");
        }

        try {
            return self::parseZip($zip);
        } finally {
            $zip->close();
        }
    }

    /**
     * @return list<ParsedSong>
     */
    private static function parseZip(ZipArchive $zip): array
    {
        $songs = [];

        // VideoPsalm numbers its songs sequentially; we walk by index until
        // we hit a missing file rather than scanning the whole archive, so
        // the work is O(songs) regardless of how many media files are in
        // the bundle.
        for ($i = 0; ; $i++) {
            $songEntry     = "Song_{$i}.json";
            $songbookEntry = "SongBook_{$i}.json";

            $songRaw = $zip->getFromName($songEntry);
            if ($songRaw === false) {
                break;
            }
            $songbookRaw = $zip->getFromName($songbookEntry);

            try {
                $songData = Json5Decoder::decode($songRaw);
            } catch (\Throwable $e) {
                throw new RuntimeException("Failed to parse {$songEntry}: {$e->getMessage()}", previous: $e);
            }

            if (! is_array($songData)) {
                continue;
            }

            $title = self::trimToNull($songData['Text'] ?? null) ?? "Untitled #{$i}";
            $guid  = self::trimToNull($songData['Guid'] ?? null) ?? "vp-{$i}";

            $verses = [];
            foreach ((array) ($songData['Verses'] ?? []) as $v) {
                if (! is_array($v)) {
                    continue;
                }
                $text = self::trimToNull($v['Text'] ?? null);
                if ($text === null) {
                    continue;
                }
                $verses[] = new ParsedVerse(
                    text: $text,
                    isChorus: (int) ($v['Tag'] ?? 0) === 1,
                );
            }

            $songbookName = null;
            $songbookGuid = null;
            if ($songbookRaw !== false) {
                try {
                    $sb = Json5Decoder::decode($songbookRaw);
                    if (is_array($sb)) {
                        $songbookName = self::trimToNull($sb['Text'] ?? null);
                        $songbookGuid = self::trimToNull($sb['Guid'] ?? null);
                    }
                } catch (\Throwable) {
                    // Songbook file present but unparseable — fall through
                    // to the default "Imported" bucket. A songbook is
                    // metadata, not load-bearing for the song itself.
                }
            }

            $songs[] = new ParsedSong(
                index: $i,
                guid: $guid,
                title: $title,
                verses: $verses,
                songbookName: $songbookName,
                songbookGuid: $songbookGuid,
            );
        }

        return $songs;
    }

    private static function trimToNull(mixed $v): ?string
    {
        if (! is_string($v)) {
            return null;
        }
        $t = trim($v);
        return $t === '' ? null : $t;
    }
}

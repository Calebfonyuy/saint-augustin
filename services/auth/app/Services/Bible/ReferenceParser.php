<?php

namespace App\Services\Bible;

/**
 * Parses a freeform scripture reference (SRS FR-BI-5/6) into a
 * ScriptureReference. Handles French and English book names/abbreviations
 * (via BookMap) and the common range forms:
 *
 *   "Jean 3:16"        single verse            → 3:16 .. 3:16
 *   "Jean 3:16-18"     verse range             → 3:16 .. 3:18
 *   "Jean 3:16-4:2"    cross-chapter range     → 3:16 .. 4:2
 *   "Psaume 23"        whole chapter           → 23:1 .. 23:end
 *   "Jean 3-4"         chapter range           → 3:1  .. 4:end
 *   "1 Corinthiens 13" numbered book + chapter → 13:1 .. 13:end
 *
 * The book portion (which may itself begin with a number, e.g. "1 Jean") is
 * separated from the trailing chapter[:verse][-…] by anchoring the numeric
 * span to the end of the string.
 */
class ReferenceParser
{
    public function parse(string $input): ScriptureReference
    {
        $text = trim($input);

        // Split "<book> <chapter[:verse][-chapter[:verse]]>". The book is
        // non-greedy so a leading book-number (1 Jean) stays with the book.
        if (! preg_match('/^(.*?)\s+(\d+(?::\d+)?(?:\s*[-–]\s*\d+(?::\d+)?)?)\s*$/u', $text, $m)) {
            throw new BibleReferenceException("Could not parse reference: \"{$input}\".");
        }

        $bookCode = BookMap::toUsfm($m[1]);
        if ($bookCode === null) {
            throw new BibleReferenceException("Unknown book in reference: \"{$m[1]}\".");
        }

        return $this->parseSpan($bookCode, $m[2], $input);
    }

    private function parseSpan(string $bookCode, string $ref, string $original): ScriptureReference
    {
        $parts = preg_split('/\s*[-–]\s*/u', $ref);
        if ($parts === false || count($parts) > 2) {
            throw new BibleReferenceException("Malformed reference: \"{$original}\".");
        }

        [$startChapter, $startVerse, $startHadVerse] = $this->parsePoint($parts[0], $original);

        // No range end.
        if (count($parts) === 1) {
            return $startHadVerse
                ? new ScriptureReference($bookCode, $startChapter, $startVerse, $startChapter, $startVerse)
                : new ScriptureReference($bookCode, $startChapter, 1, $startChapter, null);
        }

        // Range end. "C:V" is unambiguous; a bare number continues the start's
        // shape (a verse if the start had one, otherwise a chapter).
        if (str_contains($parts[1], ':')) {
            [$endChapter, $endVerse] = $this->parsePoint($parts[1], $original);

            return new ScriptureReference(
                $bookCode,
                $startChapter,
                $startHadVerse ? $startVerse : 1,
                $endChapter,
                $endVerse,
            );
        }

        $endNumber = (int) $parts[1];

        return $startHadVerse
            ? new ScriptureReference($bookCode, $startChapter, $startVerse, $startChapter, $endNumber)
            : new ScriptureReference($bookCode, $startChapter, 1, $endNumber, null);
    }

    /**
     * Parse a "C" or "C:V" point. Returns [chapter, verse, hadVerse].
     *
     * @return array{0: int, 1: int, 2: bool}
     */
    private function parsePoint(string $point, string $original): array
    {
        if (str_contains($point, ':')) {
            [$c, $v] = explode(':', $point, 2);
            $chapter = (int) $c;
            $verse = (int) $v;
            if ($chapter < 1 || $verse < 1) {
                throw new BibleReferenceException("Malformed reference: \"{$original}\".");
            }

            return [$chapter, $verse, true];
        }

        $chapter = (int) $point;
        if ($chapter < 1) {
            throw new BibleReferenceException("Malformed reference: \"{$original}\".");
        }

        return [$chapter, 1, false];
    }
}

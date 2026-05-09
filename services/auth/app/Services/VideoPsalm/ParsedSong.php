<?php

namespace App\Services\VideoPsalm;

/**
 * Immutable DTO for a single song parsed out of a `.vpagd` archive.
 *
 * Lives between `VpagdParser` (decode) and `VpagdImporter` (persist) so the
 * importer doesn't have to re-walk the raw archive arrays. The `lyrics()`
 * helper turns the verse list into the ChordPro-flavoured text we store in
 * `songs.lyrics`.
 */
final readonly class ParsedSong
{
    /**
     * @param list<ParsedVerse> $verses
     */
    public function __construct(
        public int $index,
        public string $guid,
        public string $title,
        public array $verses,
        public ?string $songbookName,
        public ?string $songbookGuid,
    ) {}

    /**
     * Concatenate the verses into a ChordPro-ish lyrics block.
     *
     * Each section gets a `[Verse N]` or `[Chorus]` header so the musician
     * and projection views (which already key off bracket-headed sections)
     * pick up structure for free. Verse numbering is positional — we
     * deliberately ignore VideoPsalm's `ID` field because it isn't always
     * monotonic in the source files (a chorus repeat sometimes resets ID
     * back to 0, which would produce confusing labels).
     */
    public function lyrics(): string
    {
        $verseNumber = 0;
        $blocks = [];
        foreach ($this->verses as $v) {
            if ($v->isChorus) {
                $header = '[Chorus]';
            } else {
                $verseNumber++;
                $header = "[Verse {$verseNumber}]";
            }
            $body = self::cleanLyricText($v->text);
            $blocks[] = $header . "\n" . $body;
        }
        return implode("\n\n", $blocks);
    }

    /**
     * VideoPsalm embeds inline formatting using HTML-ish tags (`<b>`, `<i>`,
     * and `<cAARRGGBB>` for colored text). Our editor and projection views
     * render plain text, so those markers are pure noise. We strip them
     * while preserving the surrounding content.
     *
     * Also normalises CRLF/CR line endings to LF so the stored text is
     * consistent regardless of the OS that authored the source archive.
     */
    private static function cleanLyricText(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        // strip_tags() doesn't recognise `<cFFFFFF66>` (non-standard color
        // marker) reliably, so use an explicit regex that matches any
        // angle-bracketed open/close tag with arbitrary attributes.
        $text = preg_replace('/<\/?[a-zA-Z][^>]*>/u', '', $text) ?? $text;
        // Trim trailing whitespace per line — VideoPsalm sometimes leaves
        // single trailing spaces that show up as extra dots in monospaced
        // editors.
        $lines = array_map(static fn (string $l) => rtrim($l), explode("\n", $text));
        return implode("\n", $lines);
    }
}

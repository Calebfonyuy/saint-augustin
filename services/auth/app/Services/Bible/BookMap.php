<?php

namespace App\Services\Bible;

/**
 * Maps French and English book names / abbreviations to USFM book codes
 * (SRS FR-BI-5, Appendix A — the 66-book Protestant canon HelloAO serves).
 *
 * Lookup is accent-, space-, and case-insensitive: both the aliases and the
 * user's input are run through normalize() (lowercase, strip accents, drop
 * everything but [a-z0-9]) before comparison, so "1 Corinthiens", "1 Co",
 * "1co", and "I Corinthiens" all resolve to 1CO.
 */
class BookMap
{
    /**
     * USFM code => aliases (French primary, English primary, common abbrevs,
     * plus roman-numeral variants for numbered books).
     *
     * @var array<string, list<string>>
     */
    private const ALIASES = [
        // ── Old Testament ──────────────────────────────────────────────
        'GEN' => ['Genèse', 'Genesis', 'Gen', 'Gn', 'Ge'],
        'EXO' => ['Exode', 'Exodus', 'Exod', 'Ex'],
        'LEV' => ['Lévitique', 'Leviticus', 'Lév', 'Lv', 'Lev'],
        'NUM' => ['Nombres', 'Numbers', 'Nomb', 'Nb', 'Num'],
        'DEU' => ['Deutéronome', 'Deuteronomy', 'Deut', 'Dt', 'Deu'],
        'JOS' => ['Josué', 'Joshua', 'Jos', 'Jos'],
        'JDG' => ['Juges', 'Judges', 'Jug', 'Jg', 'Jdg'],
        'RUT' => ['Ruth', 'Rt', 'Ru'],
        '1SA' => ['1 Samuel', 'I Samuel', '1 S', '1Sa', '1Sam', '1S'],
        '2SA' => ['2 Samuel', 'II Samuel', '2 S', '2Sa', '2Sam', '2S'],
        '1KI' => ['1 Rois', 'I Rois', '1 Kings', '1 R', '1Ro', '1Rois', '1Ki', '1R'],
        '2KI' => ['2 Rois', 'II Rois', '2 Kings', '2 R', '2Ro', '2Rois', '2Ki', '2R'],
        '1CH' => ['1 Chroniques', 'I Chroniques', '1 Chronicles', '1 Ch', '1Ch', '1Chr'],
        '2CH' => ['2 Chroniques', 'II Chroniques', '2 Chronicles', '2 Ch', '2Ch', '2Chr'],
        'EZR' => ['Esdras', 'Ezra', 'Esd', 'Esdr', 'Ezr'],
        'NEH' => ['Néhémie', 'Nehemiah', 'Néh', 'Ne', 'Neh'],
        'EST' => ['Esther', 'Est', 'Esth'],
        'JOB' => ['Job', 'Jb'],
        'PSA' => ['Psaumes', 'Psaume', 'Psalms', 'Psalm', 'Ps', 'Psa'],
        'PRO' => ['Proverbes', 'Proverbs', 'Prov', 'Pr', 'Pro'],
        'ECC' => ['Ecclésiaste', 'Ecclesiastes', 'Eccl', 'Ec', 'Qo', 'Qohélet'],
        'SNG' => ['Cantique des cantiques', 'Cantique', 'Song of Songs', 'Song of Solomon', 'Ct', 'Cant', 'Sng'],
        'ISA' => ['Ésaïe', 'Esaïe', 'Isaïe', 'Isaiah', 'És', 'Es', 'Isa'],
        'JER' => ['Jérémie', 'Jeremiah', 'Jér', 'Jr', 'Jer'],
        'LAM' => ['Lamentations', 'Lam', 'Lm'],
        'EZK' => ['Ézéchiel', 'Ezekiel', 'Éz', 'Ez', 'Ezk'],
        'DAN' => ['Daniel', 'Dan', 'Dn'],
        'HOS' => ['Osée', 'Hosea', 'Os', 'Hos'],
        'JOL' => ['Joël', 'Joel', 'Jl', 'Joel'],
        'AMO' => ['Amos', 'Am', 'Amo'],
        'OBA' => ['Abdias', 'Obadiah', 'Abd', 'Ab', 'Oba'],
        'JON' => ['Jonas', 'Jonah', 'Jon', 'Jonas'],
        'MIC' => ['Michée', 'Micah', 'Mich', 'Mi', 'Mic'],
        'NAM' => ['Nahum', 'Nah', 'Na', 'Nam'],
        'HAB' => ['Habacuc', 'Habakkuk', 'Hab', 'Ha'],
        'ZEP' => ['Sophonie', 'Zephaniah', 'Soph', 'So', 'Zep'],
        'HAG' => ['Aggée', 'Haggai', 'Ag', 'Agg', 'Hag'],
        'ZEC' => ['Zacharie', 'Zechariah', 'Zach', 'Za', 'Zec'],
        'MAL' => ['Malachie', 'Malachi', 'Mal', 'Ml'],

        // ── New Testament ──────────────────────────────────────────────
        'MAT' => ['Matthieu', 'Matthew', 'Matt', 'Mt', 'Mat'],
        'MRK' => ['Marc', 'Mark', 'Mc', 'Mrk', 'Mar'],
        'LUK' => ['Luc', 'Luke', 'Lc', 'Luk', 'Lu'],
        'JHN' => ['Jean', 'John', 'Jn', 'Jhn', 'Jean'],
        'ACT' => ['Actes', 'Acts', 'Ac', 'Act', 'Actes des apôtres'],
        'ROM' => ['Romains', 'Romans', 'Rom', 'Rm', 'Ro'],
        '1CO' => ['1 Corinthiens', 'I Corinthiens', '1 Corinthians', '1 Co', '1Co', '1Cor'],
        '2CO' => ['2 Corinthiens', 'II Corinthiens', '2 Corinthians', '2 Co', '2Co', '2Cor'],
        'GAL' => ['Galates', 'Galatians', 'Gal', 'Ga'],
        'EPH' => ['Éphésiens', 'Ephesians', 'Éph', 'Ep', 'Eph'],
        'PHP' => ['Philippiens', 'Philippians', 'Phil', 'Ph', 'Php'],
        'COL' => ['Colossiens', 'Colossians', 'Col'],
        '1TH' => ['1 Thessaloniciens', 'I Thessaloniciens', '1 Thessalonians', '1 Th', '1Th', '1Thess'],
        '2TH' => ['2 Thessaloniciens', 'II Thessaloniciens', '2 Thessalonians', '2 Th', '2Th', '2Thess'],
        '1TI' => ['1 Timothée', 'I Timothée', '1 Timothy', '1 Tim', '1Ti', '1Tim'],
        '2TI' => ['2 Timothée', 'II Timothée', '2 Timothy', '2 Tim', '2Ti', '2Tim'],
        'TIT' => ['Tite', 'Titus', 'Tit', 'Tt'],
        'PHM' => ['Philémon', 'Philemon', 'Phm', 'Phlm', 'Phile'],
        'HEB' => ['Hébreux', 'Hebrews', 'Héb', 'He', 'Heb'],
        'JAS' => ['Jacques', 'James', 'Jac', 'Jc', 'Jas'],
        '1PE' => ['1 Pierre', 'I Pierre', '1 Peter', '1 P', '1Pi', '1Pe', '1P'],
        '2PE' => ['2 Pierre', 'II Pierre', '2 Peter', '2 P', '2Pi', '2Pe', '2P'],
        '1JN' => ['1 Jean', 'I Jean', '1 John', '1 Jn', '1Jn', '1Jean'],
        '2JN' => ['2 Jean', 'II Jean', '2 John', '2 Jn', '2Jn', '2Jean'],
        '3JN' => ['3 Jean', 'III Jean', '3 John', '3 Jn', '3Jn', '3Jean'],
        'JUD' => ['Jude', 'Jud', 'Jd'],
        'REV' => ['Apocalypse', 'Revelation', 'Apoc', 'Ap', 'Rev', 'Re'],
    ];

    /** @var array<string, string>|null lazy normalized-alias => USFM index */
    private static ?array $index = null;

    /**
     * Resolve a French/English book name or abbreviation to a USFM code, or
     * null if it isn't recognised.
     */
    public static function toUsfm(string $name): ?string
    {
        $key = self::normalize($name);
        if ($key === '') {
            return null;
        }

        return self::index()[$key] ?? null;
    }

    public static function isUsfm(string $code): bool
    {
        return array_key_exists(strtoupper($code), self::ALIASES);
    }

    /**
     * Fold to a comparison key: lowercase, strip accents, keep only [a-z0-9].
     */
    public static function normalize(string $s): string
    {
        $s = mb_strtolower(trim($s));
        $s = strtr($s, [
            'à' => 'a', 'â' => 'a', 'ä' => 'a', 'á' => 'a',
            'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
            'î' => 'i', 'ï' => 'i', 'í' => 'i',
            'ô' => 'o', 'ö' => 'o', 'ó' => 'o',
            'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ú' => 'u',
            'ç' => 'c', 'ñ' => 'n',
        ]);

        return preg_replace('/[^a-z0-9]/', '', $s) ?? '';
    }

    /**
     * @return array<string, string>
     */
    private static function index(): array
    {
        if (self::$index !== null) {
            return self::$index;
        }

        $index = [];
        foreach (self::ALIASES as $usfm => $aliases) {
            $index[self::normalize($usfm)] = $usfm;
            foreach ($aliases as $alias) {
                $index[self::normalize($alias)] = $usfm;
            }
        }

        return self::$index = $index;
    }
}

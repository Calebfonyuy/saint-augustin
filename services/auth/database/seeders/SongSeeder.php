<?php

namespace Database\Seeders;

use App\Models\Song;
use App\Models\Songbook;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Seeds a handful of real public-domain hymns with ChordPro notation.
 *
 * These records are useful for:
 *   - manual smoke-testing of the /api/songs CRUD + search endpoints
 *   - the frontend Song Library / Musician View UI work that follows in Phase 1
 *   - verifying transposition logic in Phase 2
 *
 * Idempotency: firstOrCreate on (title, songbook_id) so re-seeding is safe.
 * Assumes SongbookSeeder has run first (called from DatabaseSeeder).
 */
class SongSeeder extends Seeder
{
    public function run(): void
    {
        // Resolve target songbooks by name
        $default  = Songbook::where('name', 'Default')->firstOrFail();
        $hymns    = Songbook::where('name', 'Hymns & Classics')->first() ?? $default;
        $advent   = Songbook::where('name', 'Advent & Christmas')->first() ?? $default;
        $easter   = Songbook::where('name', 'Lent & Easter')->first() ?? $default;
        $glorious = Songbook::where('name', 'Glorious')->first() ?? $default;

        // Placeholder used for copyrighted contemporary worship entries. Admins
        // paste the actual licensed ChordPro content themselves (CCLI number is
        // populated so the compliance path is obvious). See SRS D-5.
        $licensedPlaceholder = <<<'TXT'
{comment: Lyrics and chords must be pasted from a licensed source before use.}
{comment: This placeholder was seeded as metadata-only because the song is under copyright.}
TXT;

        // Attribute seeded songs to the admin user if present (dev convenience)
        $createdBy = User::where('email', 'admin@saintaugustin.local')->value('id');

        $songs = [
            [
                'songbook_id'    => $hymns->id,
                'title'          => 'Amazing Grace',
                'author'         => 'John Newton',
                'original_key'   => 'G',
                'tempo'          => 72,
                'time_signature' => '3/4',
                'tags'           => ['hymn', 'grace', 'classic'],
                'ccli_number'    => '22025',
                'preview_url'    => 'https://www.youtube.com/watch?v=CDdvReNKVuk',
                'lyrics'         => <<<'CHORDPRO'
{title: Amazing Grace}
{artist: John Newton}
{key: G}

{start_of_verse}
[G]Amazing [G7]grace, how [C]sweet the [G]sound
That [G]saved a [Em]wretch like [D]me
I [G]once was [G7]lost, but [C]now am [G]found
Was [Em]blind but [D]now I [G]see
{end_of_verse}

{start_of_verse}
'Twas [G]grace that [G7]taught my [C]heart to [G]fear
And [G]grace my [Em]fears re[D]lieved
How [G]precious [G7]did that [C]grace ap[G]pear
The [Em]hour I [D]first be[G]lieved
{end_of_verse}
CHORDPRO,
            ],
            [
                'songbook_id'    => $hymns->id,
                'title'          => 'Holy, Holy, Holy',
                'author'         => 'Reginald Heber',
                'original_key'   => 'D',
                'tempo'          => 96,
                'time_signature' => '4/4',
                'tags'           => ['hymn', 'trinity', 'praise'],
                'ccli_number'    => '1156',
                'lyrics'         => <<<'CHORDPRO'
{title: Holy, Holy, Holy}
{artist: Reginald Heber}
{key: D}

{start_of_verse}
[D]Holy, holy, [A]holy! [D]Lord God Al[G]mighty!
[A]Early in the [D]morning our [A]song shall rise to [D]Thee
[D]Holy, holy, [A]holy! [D]Merciful and [G]mighty!
[D]God in three [A]persons, [G]blessed [D]Trinity!
{end_of_verse}
CHORDPRO,
            ],
            [
                'songbook_id'    => $hymns->id,
                'title'          => 'Be Thou My Vision',
                'author'         => 'Traditional Irish (attr. Dallán Forgaill)',
                'original_key'   => 'Eb',
                'tempo'          => 80,
                'time_signature' => '3/4',
                'tags'           => ['hymn', 'celtic', 'devotion'],
                'ccli_number'    => '30639',
                'lyrics'         => <<<'CHORDPRO'
{title: Be Thou My Vision}
{key: Eb}

{start_of_verse}
[Eb]Be Thou my [Bb]vision, O [Cm]Lord of my [Ab]heart
[Eb]Naught be all [Bb]else to me, [Cm]save that Thou [Ab]art
[Eb]Thou my best [Bb]thought, by [Cm]day or by [Ab]night
[Eb]Waking or [Bb]sleeping, Thy [Ab]presence my [Eb]light
{end_of_verse}
CHORDPRO,
            ],
            [
                'songbook_id'    => $hymns->id,
                'title'          => 'How Great Thou Art',
                'author'         => 'Stuart K. Hine',
                'original_key'   => 'Bb',
                'tempo'          => 84,
                'time_signature' => '4/4',
                'tags'           => ['hymn', 'praise', 'classic'],
                'ccli_number'    => '14181',
                'lyrics'         => <<<'CHORDPRO'
{title: How Great Thou Art}
{artist: Stuart K. Hine}
{key: Bb}

{start_of_verse}
O [Bb]Lord my God! When [Eb]I in awesome [Bb]wonder
Con[Bb]sider [F]all the worlds Thy [Bb]hands have made
I [Bb]see the stars, I [Eb]hear the rolling [Bb]thunder
Thy [Bb]power through[F]out the uni[Bb]verse displayed
{end_of_verse}

{start_of_chorus}
Then [Bb]sings my soul, my [Eb]Saviour God, to [Bb]Thee
How [Bb]great Thou [F]art, how great Thou [Bb]art
Then [Bb]sings my soul, my [Eb]Saviour God, to [Bb]Thee
How [Bb]great Thou [F]art, how great Thou [Bb]art
{end_of_chorus}
CHORDPRO,
            ],
            [
                'songbook_id'    => $advent->id,
                'title'          => 'O Come, O Come, Emmanuel',
                'author'         => 'Traditional (trans. John Mason Neale)',
                'original_key'   => 'Em',
                'tempo'          => 72,
                'time_signature' => '4/4',
                'tags'           => ['advent', 'hymn'],
                'lyrics'         => <<<'CHORDPRO'
{title: O Come, O Come, Emmanuel}
{key: Em}

{start_of_verse}
O [Em]come, O [G]come, Em[D]manuel
And [Em]ransom [Am]captive [B7]Isra[Em]el
That [C]mourns in [G]lonely [Am]exile [Em]here
Un[D]til the [Em]Son of [B7]God ap[Em]pear
{end_of_verse}

{start_of_chorus}
Re[G]joice! Re[D]joice! Em[Em]manuel
Shall [C]come to [G]thee, O [B7]Isra[Em]el
{end_of_chorus}
CHORDPRO,
            ],
            [
                'songbook_id'    => $advent->id,
                'title'          => 'Silent Night',
                'author'         => 'Joseph Mohr / Franz Gruber',
                'original_key'   => 'C',
                'tempo'          => 68,
                'time_signature' => '6/8',
                'tags'           => ['christmas', 'hymn', 'classic'],
                'ccli_number'    => '27862',
                'lyrics'         => <<<'CHORDPRO'
{title: Silent Night}
{artist: Joseph Mohr / Franz Gruber}
{key: C}

{start_of_verse}
[C]Silent night, holy night
[G7]All is calm, [C]all is bright
[F]Round yon virgin [C]mother and child
[F]Holy infant so [C]tender and mild
[G7]Sleep in heavenly [C]peace
[C]Sleep in heavenly [G7]pe[C]ace
{end_of_verse}
CHORDPRO,
            ],
            // ── Glorious songbook (from accords.app search "glorious") ─────
            // Metadata-only entry; lyrics intentionally left as a placeholder
            // because the song is a contemporary copyrighted work (Sinach).
            [
                'songbook_id'    => $glorious->id,
                'title'          => 'Tu frayes un chemin',
                'author'         => 'Sinach',
                'original_key'   => 'A',
                'tempo'          => 72,
                'time_signature' => '4/4',
                'tags'           => ['worship', 'contemporary', 'french', 'glorious'],
                'ccli_number'    => '7115744',
                'lyrics'         => $licensedPlaceholder,
            ],

            [
                'songbook_id'    => $easter->id,
                'title'          => 'Christ the Lord Is Risen Today',
                'author'         => 'Charles Wesley',
                'original_key'   => 'C',
                'tempo'          => 108,
                'time_signature' => '4/4',
                'tags'           => ['easter', 'hymn', 'resurrection'],
                'ccli_number'    => '27965',
                'lyrics'         => <<<'CHORDPRO'
{title: Christ the Lord Is Risen Today}
{artist: Charles Wesley}
{key: C}

{start_of_verse}
[C]Christ the Lord is [F]risen to[C]day, Alle[G]lu[C]ia!
[C]Earth and heaven in [F]chorus [C]say, Alle[G]lu[C]ia!
[C]Raise your joys and [F]triumphs [C]high, Alle[G]lu[C]ia!
[C]Sing, ye heavens, and [F]earth re[C]ply, Alle[G]lu[C]ia!
{end_of_verse}
CHORDPRO,
            ],
        ];

        foreach ($songs as $data) {
            Song::firstOrCreate(
                [
                    'title'       => $data['title'],
                    'songbook_id' => $data['songbook_id'],
                ],
                [
                    ...$data,
                    'created_by' => $createdBy,
                    'version'    => 1,
                ]
            );
        }
    }
}

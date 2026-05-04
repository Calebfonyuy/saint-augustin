<?php

namespace App\Services\VideoPsalm;

use App\Models\Song;
use App\Models\Songbook;
use Illuminate\Support\Facades\DB;

/**
 * Persists parsed VideoPsalm songs into our database.
 *
 * Two policies worth knowing about:
 *
 *   1. Songbook handling — VideoPsalm groups songs into named "song books"
 *      (e.g. "Chants de louange", "Chant d'action de grâce"). We mirror
 *      these as our own `Songbook` rows, looked up by name. Songs with no
 *      songbook in the archive land in a "VideoPsalm Import" bucket so the
 *      `songs.songbook_id` foreign key stays satisfied.
 *
 *   2. Duplicate detection — same archive imported twice should not double
 *      every song. We treat (case-insensitive title, songbook_id) as the
 *      identity tuple and skip rows that already exist. We deliberately
 *      *don't* update the existing row — overwriting an admin's hand-edits
 *      with stale source data is a worse failure mode than leaving a stale
 *      duplicate. If a re-import is genuinely wanted, the admin can delete
 *      the old songs first.
 *
 * Returns an `ImportResult` summarising what happened so the controller can
 * surface counts to the UI without a second query.
 */
final class VpagdImporter
{
    private const FALLBACK_SONGBOOK_NAME = 'VideoPsalm Import';

    /**
     * @param list<ParsedSong> $songs
     * @param list<string>|null $onlyGuids If given, restrict the import to
     *                                     songs whose Guid is in this list.
     */
    public function import(
        array $songs,
        ?string $createdBy = null,
        ?array $onlyGuids = null,
    ): ImportResult {
        $created = 0;
        $skipped = 0;
        $songbooksTouched = [];

        $selected = $onlyGuids === null
            ? $songs
            : array_values(array_filter($songs, fn (ParsedSong $s) => in_array($s->guid, $onlyGuids, true)));

        // One transaction for the whole batch keeps the DB consistent if we
        // crash midway (the admin sees zero rows rather than a half import
        // with no clear way to identify the half that landed).
        DB::transaction(function () use ($selected, $createdBy, &$created, &$skipped, &$songbooksTouched) {
            $songbookCache = [];
            foreach ($selected as $parsed) {
                $songbookName = $parsed->songbookName ?? self::FALLBACK_SONGBOOK_NAME;

                if (! isset($songbookCache[$songbookName])) {
                    $songbookCache[$songbookName] = Songbook::firstOrCreate(
                        ['name' => $songbookName],
                        [
                            'description' => 'Imported from VideoPsalm',
                            'is_default'  => false,
                            'created_by'  => $createdBy,
                        ],
                    );
                }
                $songbook = $songbookCache[$songbookName];
                $songbooksTouched[$songbook->id] = $songbook->name;

                // Case-insensitive title match within the same songbook.
                // Postgres `ilike` would be cheaper but `whereRaw('lower…')`
                // works on every backend the test suite uses.
                $exists = Song::where('songbook_id', $songbook->id)
                    ->whereRaw('lower(title) = ?', [mb_strtolower($parsed->title)])
                    ->exists();

                if ($exists) {
                    $skipped++;
                    continue;
                }

                Song::create([
                    'title'        => $parsed->title,
                    'lyrics'       => $parsed->lyrics(),
                    'songbook_id'  => $songbook->id,
                    'tags'         => ['videopsalm'],
                    'created_by'   => $createdBy,
                    'version'      => 1,
                ]);
                $created++;
            }
        });

        return new ImportResult(
            created: $created,
            skipped: $skipped,
            total: count($selected),
            songbooks: array_values($songbooksTouched),
        );
    }
}

<?php

namespace App\Services\Staug;

use App\Models\Song;
use App\Models\Songbook;
use App\Models\SongSheet;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Applies a verified STAUG archive to the database (SRS FR-DI-2). Strictly
 * non-destructive: no update, restore, or delete ever happens. Per song, the
 * decision is (matching on id AND title):
 *
 *   id not present in DB  → create, preserving the archived UUID (idempotent
 *                           re-import); or a fresh UUID when the archive
 *                           carried no id.
 *   id present, title ==  → skip (including when the existing row is
 *                           soft-deleted — it is left trashed, not restored).
 *   id present, title !=  → create a NEW record with a fresh UUID; the
 *                           existing row is never touched.
 *
 * The whole batch runs in one transaction, so a mid-batch failure lands zero
 * rows (mirrors VpagdImporter). Songbooks are resolved by unique name.
 */
class StaugImporter
{
    private const FALLBACK_SONGBOOK_NAME = 'STAUG Import';

    public function import(StaugArchive $archive, ?string $createdBy = null): StaugImportResult
    {
        $created = 0;
        $skipped = 0;
        $conflicted = 0;
        $songbooksTouched = [];
        $total = count($archive->songs);

        DB::transaction(function () use ($archive, $createdBy, &$created, &$skipped, &$conflicted, &$songbooksTouched) {
            $songbookCache = [];

            foreach ($archive->songs as $record) {
                $songbook = $this->resolveSongbook($record['songbook'] ?? null, $createdBy, $songbookCache);
                $songbooksTouched[$songbook->id] = $songbook->name;

                $importedId = is_string($record['id'] ?? null) ? $record['id'] : null;
                $title = (string) ($record['title'] ?? '');

                // withTrashed is mandatory: a preserved UUID that collides with
                // a soft-deleted row would slip past a plain find() and then
                // hit a primary-key violation on insert.
                $existing = $importedId ? Song::withTrashed()->find($importedId) : null;

                if ($existing) {
                    if (trim($existing->title) === trim($title)) {
                        $skipped++; // exact match — leave as-is (do not restore)

                        continue;
                    }

                    $this->createSong($record, $songbook->id, $createdBy, null); // fresh UUID
                    $conflicted++;

                    continue;
                }

                $song = $this->createSong($record, $songbook->id, $createdBy, $importedId);
                // Sheets only when we preserved the original song id — the
                // stored object key embeds that id, so a fresh-UUID row would
                // point at the wrong prefix (FR-DX-4).
                if ($importedId !== null) {
                    $this->maybeCreateSheets($song, $record['sheets'] ?? null);
                }
                $created++;
            }
        });

        return new StaugImportResult(
            created: $created,
            skipped: $skipped,
            conflicted: $conflicted,
            total: $total,
            songbooks: array_values($songbooksTouched),
        );
    }

    /**
     * Read-only projection of what import() would do, for the dry-run preview.
     *
     * @return array{type: string, songs: list<array{id: ?string, title: string, action: string}>, songbooks: array<string, int>}
     */
    public function preview(StaugArchive $archive): array
    {
        $rows = [];
        $songbookCounts = [];

        foreach ($archive->songs as $record) {
            $importedId = is_string($record['id'] ?? null) ? $record['id'] : null;
            $title = (string) ($record['title'] ?? '');

            $existing = $importedId ? Song::withTrashed()->find($importedId) : null;
            $action = match (true) {
                ! $existing                            => 'create',
                trim($existing->title) === trim($title) => 'skip',
                default                                 => 'conflict',
            };

            $rows[] = ['id' => $importedId, 'title' => $title, 'action' => $action];

            $sbName = $this->songbookName($record['songbook'] ?? null);
            $songbookCounts[$sbName] = ($songbookCounts[$sbName] ?? 0) + 1;
        }

        return ['type' => $archive->type, 'songs' => $rows, 'songbooks' => $songbookCounts];
    }

    // ── Internals ────────────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $record
     */
    private function createSong(array $record, string $songbookId, ?string $createdBy, ?string $id): Song
    {
        $song = new Song();
        $song->forceFill($this->attributes($record, $songbookId, $createdBy));
        if ($id !== null) {
            // Set before save so HasUuids preserves it (it only generates when empty).
            $song->id = $id;
        }
        $song->save();

        return $song;
    }

    /**
     * Whitelisted attributes only — never trust created_by/timestamps/deleted_at
     * from the archive.
     *
     * @param  array<string, mixed>  $r
     * @return array<string, mixed>
     */
    private function attributes(array $r, string $songbookId, ?string $createdBy): array
    {
        return [
            'title'          => (string) ($r['title'] ?? ''),
            'author'         => $r['author'] ?? null,
            'lyrics'         => (string) ($r['lyrics'] ?? ''),
            'original_key'   => $r['original_key'] ?? null,
            'tempo'          => $r['tempo'] ?? null,
            'time_signature' => $r['time_signature'] ?? null,
            'tags'           => is_array($r['tags'] ?? null) ? array_values($r['tags']) : [],
            'preview_url'    => $r['preview_url'] ?? null,
            'ccli_number'    => $r['ccli_number'] ?? null,
            'version'        => is_int($r['version'] ?? null) ? $r['version'] : 1,
            'songbook_id'    => $songbookId,
            'created_by'     => $createdBy,
        ];
    }

    /**
     * @param  array<string, mixed>|null  $ref
     * @param  array<string, Songbook>  $cache
     */
    private function resolveSongbook(?array $ref, ?string $createdBy, array &$cache): Songbook
    {
        $name = $this->songbookName($ref);

        if (isset($cache[$name])) {
            return $cache[$name];
        }

        return $cache[$name] = Songbook::firstOrCreate(
            ['name' => $name],
            [
                'description' => is_array($ref) ? ($ref['description'] ?? 'Imported from STAUG archive') : 'Imported from STAUG archive',
                'is_default'  => false,
                'created_by'  => $createdBy,
            ],
        );
    }

    /** @param array<string, mixed>|null $ref */
    private function songbookName(?array $ref): string
    {
        $name = is_array($ref) && is_string($ref['name'] ?? null) ? trim($ref['name']) : '';

        return $name !== '' ? $name : self::FALLBACK_SONGBOOK_NAME;
    }

    private function maybeCreateSheets(Song $song, mixed $sheets): void
    {
        if (! is_array($sheets)) {
            return;
        }

        foreach ($sheets as $s) {
            if (! is_array($s)) {
                continue;
            }

            $disk = is_string($s['storage_disk'] ?? null) ? $s['storage_disk'] : 'minio';
            $path = $s['storage_path'] ?? null;
            if (! is_string($path) || $path === '') {
                continue;
            }

            // Best-effort: only recreate the row when the binary is actually
            // present on the target disk, so we never leave a dangling row
            // whose presigned URL 404s.
            if (! Storage::disk($disk)->exists($path)) {
                continue;
            }

            SongSheet::create([
                'song_id'           => $song->id,
                'original_filename' => is_string($s['original_filename'] ?? null) ? $s['original_filename'] : basename($path),
                'storage_disk'      => $disk,
                'storage_path'      => $path,
                'file_type'         => is_string($s['file_type'] ?? null) ? $s['file_type'] : 'pdf',
                'mime_type'         => is_string($s['mime_type'] ?? null) ? $s['mime_type'] : 'application/octet-stream',
                'size_bytes'        => (int) ($s['size_bytes'] ?? 0),
                'uploaded_by'       => null,
            ]);
        }
    }
}

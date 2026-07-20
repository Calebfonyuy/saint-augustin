<?php

namespace App\Services\Staug;

use App\Models\Playlist;
use App\Models\PlaylistItem;
use App\Models\Song;
use App\Models\Songbook;
use App\Models\SongSheet;
use RuntimeException;
use ZipArchive;

/**
 * Builds a signed STAUG ZIP archive (SRS FR-DX-1..4) for a playlist, a
 * songbook, or the full library. Each writer returns a temp-file path the
 * caller streams to the client (playlist/songbook) or uploads to MinIO
 * (full export), then deletes.
 *
 * Layout: /manifest.json (raw signed bytes), /manifest.sig (hex HMAC),
 * /songs/<uuid>.json per song. The manifest records a per-song sha256 and is
 * signed as a whole, so the single signature transitively covers every body.
 * Song sheets are referenced by (disk, path) metadata only — binaries are
 * never bundled (FR-DX-4).
 *
 * Songs are streamed one at a time into the zip (ZipArchive buffers to its
 * own temp file, not RAM), so a full-library export stays bounded in memory.
 */
class StaugArchiveWriter
{
    /** JSON flags used for every file we write; the signed bytes are exactly these. */
    private const JSON_FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR;

    public function __construct(private readonly StaugSigner $signer)
    {
    }

    public function writePlaylist(Playlist $playlist): string
    {
        $playlist->loadMissing(['items.song.songbook', 'items.song.sheets']);

        [$zip, $tmp] = $this->begin();
        $manifestSongs = [];
        $songbooks = [];
        $items = [];
        $seen = [];

        foreach ($playlist->items->sortBy('position') as $item) {
            $items[] = $this->playlistItemEntry($item);

            if ($item->item_type === PlaylistItem::TYPE_SCRIPTURE) {
                continue;
            }

            $song = $item->song; // withTrashed relation — may be a soft-deleted song
            if ($song && ! isset($seen[$song->id])) {
                $seen[$song->id] = true;
                $this->addSong($zip, $manifestSongs, $songbooks, $song);
            }
        }

        $container = [
            'id'         => $playlist->id,
            'name'       => $playlist->name,
            'event_date' => $playlist->event_date?->toDateString(),
            'tags'       => $playlist->tags ?? [],
            'items'      => $items,
        ];

        return $this->finalize($zip, $tmp, 'playlist', $container, $manifestSongs, $songbooks);
    }

    public function writeSongbook(Songbook $songbook): string
    {
        [$zip, $tmp] = $this->begin();
        $manifestSongs = [];
        $songbooks = [];

        Song::query()
            ->where('songbook_id', $songbook->id)
            ->with(['songbook', 'sheets'])
            ->chunkById(500, function ($chunk) use ($zip, &$manifestSongs, &$songbooks) {
                foreach ($chunk as $song) {
                    $this->addSong($zip, $manifestSongs, $songbooks, $song);
                }
            });

        // Ensure the songbook itself is listed even when it holds no songs.
        $songbooks[$songbook->id] = $this->songbookRef($songbook);

        $container = [
            'id'          => $songbook->id,
            'name'        => $songbook->name,
            'description' => $songbook->description,
            'is_default'  => $songbook->is_default,
        ];

        return $this->finalize($zip, $tmp, 'songbook', $container, $manifestSongs, $songbooks);
    }

    /**
     * Full library: every (non-soft-deleted) song and every songbook. Users,
     * playlists, and sessions are deliberately excluded (FR-DF-1).
     */
    public function writeFull(): string
    {
        [$zip, $tmp] = $this->begin();
        $manifestSongs = [];
        $songbooks = [];

        Song::query()
            ->with(['songbook', 'sheets'])
            ->chunkById(500, function ($chunk) use ($zip, &$manifestSongs, &$songbooks) {
                foreach ($chunk as $song) {
                    $this->addSong($zip, $manifestSongs, $songbooks, $song);
                }
            });

        // Include every songbook, even empty ones with no songs to carry them.
        foreach (Songbook::all() as $songbook) {
            $songbooks[$songbook->id] = $this->songbookRef($songbook);
        }

        $container = [
            'song_count'     => count($manifestSongs),
            'songbook_count' => count($songbooks),
        ];

        return $this->finalize($zip, $tmp, 'full', $container, $manifestSongs, $songbooks);
    }

    // ── Internals ────────────────────────────────────────────────────────

    /** @return array{0: ZipArchive, 1: string} */
    private function begin(): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'staug-');
        if ($tmp === false) {
            throw new RuntimeException('Could not allocate a temp file for the STAUG archive.');
        }

        $zip = new ZipArchive();
        if ($zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            @unlink($tmp);
            throw new RuntimeException('Could not open the STAUG archive for writing.');
        }

        return [$zip, $tmp];
    }

    /**
     * @param  list<array<string, mixed>>  $manifestSongs
     * @param  array<string, array<string, mixed>>  $songbooks
     */
    private function addSong(ZipArchive $zip, array &$manifestSongs, array &$songbooks, Song $song): void
    {
        $json = $this->encode($this->songRecord($song));
        $file = "songs/{$song->id}.json";
        $zip->addFromString($file, $json);

        $manifestSongs[] = [
            'id'     => $song->id,
            'title'  => $song->title,
            'file'   => $file,
            'sha256' => hash('sha256', $json),
        ];

        if ($song->songbook) {
            $songbooks[$song->songbook->id] = $this->songbookRef($song->songbook);
        }
    }

    /**
     * @param  array<string, mixed>  $container
     * @param  list<array<string, mixed>>  $manifestSongs
     * @param  array<string, array<string, mixed>>  $songbooks
     */
    private function finalize(ZipArchive $zip, string $tmp, string $type, array $container, array $manifestSongs, array $songbooks): string
    {
        $manifest = [
            'format'       => 'STAUG',
            'version'      => (string) config('staug.format_version', '1'),
            'type'         => $type,
            'generated_at' => now()->toIso8601String(),
            'generator'    => 'SaintAugustin',
            'container'    => $container,
            'songbooks'    => array_values($songbooks),
            'songs'        => $manifestSongs,
        ];

        // Serialize once; sign and store the exact same bytes (FR-DX-3).
        $bytes = $this->encode($manifest);
        $zip->addFromString('manifest.json', $bytes);
        $zip->addFromString('manifest.sig', $this->signer->sign($bytes));

        if ($zip->close() !== true) {
            @unlink($tmp);
            throw new RuntimeException('Could not finalize the STAUG archive.');
        }

        return $tmp;
    }

    /** @return array<string, mixed> */
    private function songRecord(Song $song): array
    {
        return [
            'id'             => $song->id,
            'title'          => $song->title,
            'author'         => $song->author,
            'lyrics'         => $song->lyrics,
            'original_key'   => $song->original_key,
            'tempo'          => $song->tempo,
            'time_signature' => $song->time_signature,
            'tags'           => $song->tags ?? [],
            'preview_url'    => $song->preview_url,
            'ccli_number'    => $song->ccli_number,
            'version'        => $song->version,
            'songbook'       => $song->songbook ? $this->songbookRef($song->songbook) : null,
            'sheets'         => $song->sheets->map(fn (SongSheet $s) => [
                'original_filename' => $s->original_filename,
                'storage_disk'      => $s->storage_disk,
                'storage_path'      => $s->storage_path,
                'file_type'         => $s->file_type,
                'mime_type'         => $s->mime_type,
                'size_bytes'        => $s->size_bytes,
            ])->all(),
        ];
    }

    /** @return array<string, mixed> */
    private function songbookRef(Songbook $songbook): array
    {
        return [
            'id'          => $songbook->id,
            'name'        => $songbook->name,
            'description' => $songbook->description,
            'is_default'  => $songbook->is_default,
        ];
    }

    /** @return array<string, mixed> */
    private function playlistItemEntry(PlaylistItem $item): array
    {
        if ($item->item_type === PlaylistItem::TYPE_SCRIPTURE) {
            return [
                'item_type' => 'scripture',
                'position'  => $item->position,
                'notes'     => $item->notes,
                'scripture' => [
                    'translation_id' => $item->translation_id,
                    'book_code'      => $item->book_code,
                    'start_chapter'  => $item->start_chapter,
                    'start_verse'    => $item->start_verse,
                    'end_chapter'    => $item->end_chapter,
                    'end_verse'      => $item->end_verse,
                    'reference'      => $item->scriptureReference(),
                ],
            ];
        }

        return [
            'item_type'  => 'song',
            'position'   => $item->position,
            'song_id'    => $item->song_id,
            'target_key' => $item->target_key,
            'notes'      => $item->notes,
        ];
    }

    /** @param array<string, mixed> $data */
    private function encode(array $data): string
    {
        return json_encode($data, self::JSON_FLAGS);
    }
}

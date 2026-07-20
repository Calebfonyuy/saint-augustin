<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * PlaylistItem model (SRS 6.1, FR-PL-2).
 *
 * A playlist item is polymorphic: `item_type` is either `song` (carries a
 * `song_id`, plus an optional `target_key`/`notes`) or `scripture` (carries
 * a resolved USFM reference — `translation_id`, `book_code`, and the
 * start/end chapter+verse). Scripture *text* is never stored here; only the
 * pointer to the passage, resolved and rendered at projection time.
 *
 * `target_key` is null when a song item plays in the song's original key —
 * the controller surfaces the effective key by falling back to the related
 * song's `original_key`. `notes` is an arbitrary musician-facing note and
 * applies to either item type.
 *
 * @property string      $id
 * @property string      $playlist_id
 * @property string      $item_type
 * @property string|null $song_id
 * @property int         $position
 * @property string|null $target_key
 * @property string|null $notes
 * @property string|null $translation_id
 * @property string|null $book_code
 * @property int|null    $start_chapter
 * @property int|null    $start_verse
 * @property int|null    $end_chapter
 * @property int|null    $end_verse
 */
class PlaylistItem extends Model
{
    /** @use HasFactory<\Database\Factories\PlaylistItemFactory> */
    use HasFactory;
    use HasUuids;

    public const TYPE_SONG = 'song';

    public const TYPE_SCRIPTURE = 'scripture';

    /** All valid `item_type` values — used for validation. */
    public const TYPES = [self::TYPE_SONG, self::TYPE_SCRIPTURE];

    protected $fillable = [
        'playlist_id',
        'item_type',
        'song_id',
        'position',
        'target_key',
        'notes',
        'translation_id',
        'book_code',
        'start_chapter',
        'start_verse',
        'end_chapter',
        'end_verse',
    ];

    protected $attributes = [
        'item_type' => self::TYPE_SONG,
    ];

    protected function casts(): array
    {
        return [
            'position'      => 'integer',
            'start_chapter' => 'integer',
            'start_verse'   => 'integer',
            'end_chapter'   => 'integer',
            'end_verse'     => 'integer',
        ];
    }

    public function isScripture(): bool
    {
        return $this->item_type === self::TYPE_SCRIPTURE;
    }

    /**
     * Compose a human-readable USFM-style reference from the discrete
     * columns, e.g. "JHN 3:16", "JHN 3:16-18", or "JHN 3:16-4:2". Returns
     * null for a non-scripture item or one with no chapter set. The
     * localized book name + translation label are applied in the Bible
     * module (Stage 7); this is the language-neutral fallback.
     */
    public function scriptureReference(): ?string
    {
        if (! $this->isScripture() || $this->start_chapter === null) {
            return null;
        }

        $ref = $this->book_code.' '.$this->start_chapter;

        if ($this->start_verse !== null) {
            $ref .= ':'.$this->start_verse;
        }

        // Append the end of a range only when it differs from the start.
        $endChapter = $this->end_chapter ?? $this->start_chapter;
        $endVerse = $this->end_verse;

        if ($endVerse !== null && ($endChapter !== $this->start_chapter || $endVerse !== $this->start_verse)) {
            $ref .= '-';
            $ref .= $endChapter !== $this->start_chapter
                ? $endChapter.':'.$endVerse
                : $endVerse;
        }

        return $ref;
    }

    /** @return \Illuminate\Database\Eloquent\Relations\BelongsTo<Playlist, $this> */
    public function playlist(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Playlist::class);
    }

    /** @return \Illuminate\Database\Eloquent\Relations\BelongsTo<Song, $this> */
    public function song(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        // Use withTrashed so deleted songs still show in playlists with a
        // (deleted) badge — see SongController soft-delete behaviour. For a
        // scripture item song_id is null and this resolves to null.
        return $this->belongsTo(Song::class)->withTrashed();
    }
}

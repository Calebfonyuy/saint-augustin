<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * PlaylistItem model (SRS 6.1).
 *
 * `target_key` is null when the item plays in the song's original key —
 * the controller surfaces the effective key by falling back to the
 * related song's `original_key`. `notes` is an arbitrary musician-facing
 * note (e.g. "skip second verse").
 *
 * @property string      $id
 * @property string      $playlist_id
 * @property string      $song_id
 * @property int         $position
 * @property string|null $target_key
 * @property string|null $notes
 */
class PlaylistItem extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'playlist_id',
        'song_id',
        'position',
        'target_key',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
        ];
    }

    public function playlist(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Playlist::class);
    }

    public function song(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        // Use withTrashed so deleted songs still show in playlists with a
        // (deleted) badge — see SongController soft-delete behaviour.
        return $this->belongsTo(Song::class)->withTrashed();
    }
}

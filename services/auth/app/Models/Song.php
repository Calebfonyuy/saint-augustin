<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Song model (SRS 3.1.1, 6.1).
 *
 * Lyrics and chords live together in the `lyrics` column using ChordPro
 * notation (SRS 3.2.1). Soft-deletes give admins a 30-day recovery window
 * (NFR-8). `version` increments on every update as a lightweight audit trail.
 *
 * @property string      $id
 * @property string      $title
 * @property string|null $author
 * @property string      $lyrics
 * @property string|null $original_key
 * @property int|null    $tempo
 * @property string|null $time_signature
 * @property string      $songbook_id
 * @property string[]    $tags
 * @property string|null $preview_url
 * @property string|null $ccli_number
 * @property string|null $created_by
 * @property int         $version
 */
class Song extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    protected $fillable = [
        'title',
        'author',
        'lyrics',
        'original_key',
        'tempo',
        'time_signature',
        'songbook_id',
        'tags',
        'preview_url',
        'ccli_number',
        'created_by',
        'version',
    ];

    protected function casts(): array
    {
        return [
            'tags'    => 'array',
            'tempo'   => 'integer',
            'version' => 'integer',
        ];
    }

    // ── Relationships ─────────────────────────────────────────────────

    public function songbook(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Songbook::class);
    }

    public function creator(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function sheets(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(SongSheet::class)->orderByDesc('created_at');
    }
}

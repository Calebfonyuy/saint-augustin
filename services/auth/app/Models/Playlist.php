<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Playlist model (SRS 3.3 / 6.1).
 *
 * Items are exposed as a hasMany ordered by `position` so the controller
 * can rely on a deterministic order without re-sorting in PHP. Tags are
 * cast to a plain array and queried via whereJsonContains, mirroring the
 * Song model's tagging behaviour.
 *
 * @property string      $id
 * @property string      $name
 * @property string|null $event_date
 * @property string[]    $tags
 * @property string|null $created_by
 * @property string|null $duplicated_from_id
 */
class Playlist extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'name',
        'event_date',
        'tags',
        'created_by',
        'duplicated_from_id',
    ];

    protected function casts(): array
    {
        return [
            'tags'       => 'array',
            'event_date' => 'date:Y-m-d',
        ];
    }

    // ── Relationships ─────────────────────────────────────────────────

    public function items(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(PlaylistItem::class)->orderBy('position');
    }

    public function creator(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function shareLinks(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(ShareLink::class);
    }
}

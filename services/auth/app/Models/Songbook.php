<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Songbook — a logical collection of songs (SRS 3.1.3).
 *
 * A default songbook is seeded at install time; additional songbooks may be
 * created later. Songs belong to exactly one songbook.
 *
 * @property string      $id
 * @property string      $name
 * @property string|null $description
 * @property bool        $is_default
 * @property string|null $created_by
 */
class Songbook extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'name',
        'description',
        'is_default',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
        ];
    }

    public function songs(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Song::class);
    }

    public function creator(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}

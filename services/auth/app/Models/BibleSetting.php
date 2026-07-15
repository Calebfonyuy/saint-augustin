<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Workspace Bible settings (FR-BI-1) — a single row: which HelloAO
 * translations are enabled and which is the default. Read via current().
 *
 * @property string                        $id
 * @property list<array<string, mixed>>    $enabled_translations
 * @property string|null                   $default_translation_id
 */
class BibleSetting extends Model
{
    /** @use HasFactory<\Database\Factories\BibleSettingFactory> */
    use HasFactory;
    use HasUuids;

    protected $fillable = [
        'enabled_translations',
        'default_translation_id',
    ];

    protected function casts(): array
    {
        return [
            'enabled_translations' => 'array',
        ];
    }

    /**
     * The singleton settings row, created empty on first access.
     */
    public static function current(): self
    {
        return static::query()->firstOrCreate([], [
            'enabled_translations'   => [],
            'default_translation_id' => null,
        ]);
    }
}

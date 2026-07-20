<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Structure-only cache of a translation's books (FR-BI-2): localized name +
 * chapter count per USFM book. Populated from the translation's books.json
 * when settings are saved. Holds no verse text.
 *
 * @property string $id
 * @property string $translation_id
 * @property string $book_code
 * @property string $name
 * @property int    $chapter_count
 * @property int    $order
 */
class BibleBook extends Model
{
    /** @use HasFactory<\Database\Factories\BibleBookFactory> */
    use HasFactory;
    use HasUuids;

    protected $fillable = [
        'translation_id',
        'book_code',
        'name',
        'chapter_count',
        'order',
    ];

    protected function casts(): array
    {
        return [
            'chapter_count' => 'integer',
            'order'         => 'integer',
        ];
    }
}

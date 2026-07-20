<?php

namespace Database\Factories;

use App\Models\BibleBook;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BibleBook>
 */
class BibleBookFactory extends Factory
{
    protected $model = BibleBook::class;

    public function definition(): array
    {
        return [
            'translation_id' => 'BSB',
            'book_code'      => 'JHN',
            'name'           => 'John',
            'chapter_count'  => 21,
            'order'          => 43,
        ];
    }
}

<?php

namespace Database\Factories;

use App\Models\Song;
use App\Models\Songbook;
use Illuminate\Database\Eloquent\Factories\Factory;

class SongFactory extends Factory
{
    protected $model = Song::class;

    public function definition(): array
    {
        return [
            'title'          => fake()->sentence(3),
            'author'         => fake()->name(),
            'lyrics'         => "{start_of_verse}\n[C]Amazing [G]grace, how [Am]sweet the [F]sound\n{end_of_verse}",
            'original_key'   => fake()->randomElement(['C', 'G', 'D', 'A', 'E', 'F', 'Bb', 'Am', 'Em', 'Dm']),
            'tempo'          => fake()->numberBetween(60, 160),
            'time_signature' => fake()->randomElement(['4/4', '3/4', '6/8']),
            'songbook_id'    => Songbook::factory(),
            'tags'           => fake()->randomElements(['praise', 'communion', 'advent', 'lent', 'christmas', 'easter'], 2),
            'preview_url'    => fake()->optional()->url(),
            'ccli_number'    => fake()->optional()->numerify('#######'),
            'version'        => 1,
        ];
    }
}

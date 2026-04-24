<?php

namespace Database\Factories;

use App\Models\Songbook;
use Illuminate\Database\Eloquent\Factories\Factory;

class SongbookFactory extends Factory
{
    protected $model = Songbook::class;

    public function definition(): array
    {
        return [
            'name'        => fake()->unique()->words(2, true),
            'description' => fake()->optional()->sentence(),
            'is_default'  => false,
        ];
    }

    public function default(): static
    {
        return $this->state(fn () => [
            'name'       => 'Default',
            'is_default' => true,
        ]);
    }
}

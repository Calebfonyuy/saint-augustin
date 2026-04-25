<?php

namespace Database\Factories;

use App\Models\Playlist;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class PlaylistFactory extends Factory
{
    protected $model = Playlist::class;

    public function definition(): array
    {
        return [
            'name'       => 'Sunday Service '.fake()->date('M j'),
            'event_date' => fake()->optional()->dateTimeBetween('-1 month', '+1 month'),
            'tags'       => fake()->randomElements(['mass', 'worship', 'youth', 'advent', 'easter'], 2),
            'created_by' => User::factory(),
        ];
    }
}

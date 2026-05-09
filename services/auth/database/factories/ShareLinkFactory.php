<?php

namespace Database\Factories;

use App\Models\Playlist;
use App\Models\ShareLink;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class ShareLinkFactory extends Factory
{
    protected $model = ShareLink::class;

    public function definition(): array
    {
        return [
            'playlist_id' => Playlist::factory(),
            'token'       => ShareLink::generateToken(),
            'mode'        => fake()->randomElement(ShareLink::MODES),
            'created_by'  => User::factory(),
        ];
    }

    public function musician(): static
    {
        return $this->state(['mode' => ShareLink::MODE_MUSICIAN]);
    }

    public function projection(): static
    {
        return $this->state(['mode' => ShareLink::MODE_PROJECTION]);
    }

    public function revoked(): static
    {
        return $this->state(['revoked_at' => now()]);
    }
}

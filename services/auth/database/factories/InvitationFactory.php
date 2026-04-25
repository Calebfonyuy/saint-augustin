<?php

namespace Database\Factories;

use App\Models\Invitation;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class InvitationFactory extends Factory
{
    protected $model = Invitation::class;

    public function definition(): array
    {
        return [
            'email'       => fake()->unique()->safeEmail(),
            'token'       => Str::random(64),
            'roles'       => ['musician'],
            'invited_by'  => null,
            'expires_at'  => now()->addHours(48),
            'accepted_at' => null,
        ];
    }

    public function expired(): self
    {
        return $this->state(fn () => ['expires_at' => now()->subDay()]);
    }

    public function accepted(): self
    {
        return $this->state(fn () => ['accepted_at' => now()->subHour()]);
    }
}

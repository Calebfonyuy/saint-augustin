<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class UserFactory extends Factory
{
    protected $model = User::class;

    public function definition(): array
    {
        return [
            'email'        => fake()->unique()->safeEmail(),
            'display_name' => fake()->name(),
            'password'     => Hash::make('password'),
            'roles'        => ['musician'],
            'email_verified_at' => now(),
            'remember_token'    => Str::random(10),
        ];
    }

    public function admin(): static
    {
        return $this->state(fn () => ['roles' => ['admin', 'musician']]);
    }

    public function projectionist(): static
    {
        return $this->state(fn () => ['roles' => ['projectionist']]);
    }

    public function allRoles(): static
    {
        return $this->state(fn () => ['roles' => ['admin', 'musician', 'projectionist']]);
    }
}

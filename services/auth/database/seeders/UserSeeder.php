<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Seeds the baseline local-development user accounts — one per role.
 *
 * Passwords are all `password` (dev-only convenience). Idempotent via
 * updateOrCreate on the unique `email` column.
 *
 * Run order: must execute before SongSeeder so seeded songs can attribute
 * `created_by` to the admin account.
 */
class UserSeeder extends Seeder
{
    public function run(): void
    {
        // Default admin user for development
        User::updateOrCreate(
            ['email' => 'admin@saintaugustin.local'],
            [
                'display_name' => 'Admin',
                'password'     => Hash::make('password'),
                'roles'        => ['admin', 'musician', 'projectionist'],
            ]
        );

        // Sample musician
        User::updateOrCreate(
            ['email' => 'musician@saintaugustin.local'],
            [
                'display_name' => 'Marie',
                'password'     => Hash::make('password'),
                'roles'        => ['musician'],
            ]
        );

        // Sample projectionist
        User::updateOrCreate(
            ['email' => 'projectionist@saintaugustin.local'],
            [
                'display_name' => 'Pierre',
                'password'     => Hash::make('password'),
                'roles'        => ['projectionist'],
            ]
        );
    }
}

<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
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

<?php

namespace Database\Seeders;

use App\Models\Songbook;
use Illuminate\Database\Seeder;

/**
 * Seeds the standard set of songbooks used in local development.
 *
 * Idempotent: uses updateOrCreate on the unique `name` column, so running
 * `db:seed` repeatedly will not create duplicates or throw.
 *
 * The "Default" songbook is required by the system (SRS 3.1.3) — every song
 * belongs to exactly one songbook and this is the fallback target.
 */
class SongbookSeeder extends Seeder
{
    public function run(): void
    {
        $songbooks = [
            [
                'name'        => 'Default',
                'description' => 'Default songbook shipped with the system.',
                'is_default'  => true,
            ],
            [
                'name'        => 'Hymns & Classics',
                'description' => 'Traditional hymns and public-domain classics.',
                'is_default'  => false,
            ],
            [
                'name'        => 'Contemporary Worship',
                'description' => 'Modern worship songs used in Sunday services.',
                'is_default'  => false,
            ],
            [
                'name'        => 'Advent & Christmas',
                'description' => 'Songs for Advent, Christmas Eve, and the Christmas season.',
                'is_default'  => false,
            ],
            [
                'name'        => 'Lent & Easter',
                'description' => 'Songs for Lent, Holy Week, and Easter.',
                'is_default'  => false,
            ],
            [
                'name'        => 'Glorious',
                'description' => 'Songs sourced from the "glorious" search on accords.app — French worship repertoire.',
                'is_default'  => false,
            ],
        ];

        foreach ($songbooks as $data) {
            Songbook::updateOrCreate(
                ['name' => $data['name']],
                [
                    'description' => $data['description'],
                    'is_default'  => $data['is_default'],
                ]
            );
        }
    }
}

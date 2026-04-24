<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Order matters:
        //   - Users first, so SongSeeder can attribute `created_by` to admin.
        //   - Songbooks before Songs, since Song.songbook_id is a required FK.
        $this->call([
            UserSeeder::class,
            SongbookSeeder::class,
            SongSeeder::class,
        ]);
    }
}

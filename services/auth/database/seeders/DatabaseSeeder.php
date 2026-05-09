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
        //   - SongSheets last — they need a Song row to attach to and a User
        //     to attribute the upload to.
        $this->call([
            UserSeeder::class,
            SongbookSeeder::class,
            SongSeeder::class,
            SongSheetSeeder::class,
        ]);
    }
}

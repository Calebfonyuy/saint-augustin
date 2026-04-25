<?php

namespace Database\Factories;

use App\Models\Playlist;
use App\Models\PlaylistItem;
use App\Models\Song;
use Illuminate\Database\Eloquent\Factories\Factory;

class PlaylistItemFactory extends Factory
{
    protected $model = PlaylistItem::class;

    public function definition(): array
    {
        return [
            'playlist_id' => Playlist::factory(),
            'song_id'     => Song::factory(),
            'position'    => 0,
            'target_key'  => null,
            'notes'       => null,
        ];
    }
}

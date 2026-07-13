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
            'item_type'   => PlaylistItem::TYPE_SONG,
            'song_id'     => Song::factory(),
            'position'    => 0,
            'target_key'  => null,
            'notes'       => null,
        ];
    }

    /**
     * A scripture reading item (FR-PL-2): no song, a resolved USFM reference.
     * Defaults to a single verse; pass overrides for a range.
     */
    public function scripture(array $reference = []): static
    {
        return $this->state(fn () => [
            'item_type'      => PlaylistItem::TYPE_SCRIPTURE,
            'song_id'        => null,
            'translation_id' => 'BSB',
            'book_code'      => 'JHN',
            'start_chapter'  => 3,
            'start_verse'    => 16,
            'end_chapter'    => null,
            'end_verse'      => null,
            ...$reference,
        ]);
    }
}

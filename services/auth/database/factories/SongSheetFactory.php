<?php

namespace Database\Factories;

use App\Models\Song;
use App\Models\SongSheet;
use Illuminate\Database\Eloquent\Factories\Factory;

class SongSheetFactory extends Factory
{
    protected $model = SongSheet::class;

    public function definition(): array
    {
        return [
            'song_id'           => Song::factory(),
            'original_filename' => fake()->slug(2).'.pdf',
            'storage_disk'      => 'minio',
            'storage_path'      => 'song-sheets/'.fake()->uuid().'.pdf',
            'file_type'         => SongSheet::TYPE_PDF,
            'mime_type'         => 'application/pdf',
            'size_bytes'        => fake()->numberBetween(50_000, 2_000_000),
        ];
    }

    public function image(): self
    {
        return $this->state([
            'original_filename' => fake()->slug(2).'.png',
            'storage_path'      => 'song-sheets/'.fake()->uuid().'.png',
            'file_type'         => SongSheet::TYPE_IMAGE,
            'mime_type'         => 'image/png',
        ]);
    }
}

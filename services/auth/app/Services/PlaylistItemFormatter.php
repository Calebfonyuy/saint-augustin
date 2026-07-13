<?php

namespace App\Services;

use App\Models\PlaylistItem;

/**
 * Single source of truth for the JSON shape of a playlist item (FR-PL-2).
 *
 * Both PlaylistController and PlaylistItemController serialise items, and a
 * polymorphic item now has a song *or* a scripture block. Centralising the
 * shape here keeps the two controllers from drifting apart as the model
 * grows.
 *
 * Every item carries `item_type`. A song item has a populated `song` and a
 * null `scripture`; a scripture item is the reverse. `reference` is the
 * language-neutral USFM label (e.g. "JHN 3:16-4:2") — the localized book
 * name is applied by the Bible module (Stage 7).
 */
class PlaylistItemFormatter
{
    /** @return array<string, mixed> */
    public static function format(PlaylistItem $item): array
    {
        return [
            'id'         => $item->id,
            'item_type'  => $item->item_type,
            'song_id'    => $item->song_id,
            'position'   => $item->position,
            'target_key' => $item->target_key,
            'notes'      => $item->notes,
            'song'       => self::formatSong($item),
            'scripture'  => self::formatScripture($item),
        ];
    }

    /** @return array<string, mixed>|null */
    private static function formatSong(PlaylistItem $item): ?array
    {
        $song = $item->song;

        if (! $song) {
            return null;
        }

        return [
            'id'             => $song->id,
            'title'          => $song->title,
            'author'         => $song->author,
            'original_key'   => $song->original_key,
            'tempo'          => $song->tempo,
            'time_signature' => $song->time_signature,
            'deleted'        => $song->trashed(),
        ];
    }

    /** @return array<string, mixed>|null */
    private static function formatScripture(PlaylistItem $item): ?array
    {
        if (! $item->isScripture()) {
            return null;
        }

        return [
            'translation_id' => $item->translation_id,
            'book_code'      => $item->book_code,
            'start_chapter'  => $item->start_chapter,
            'start_verse'    => $item->start_verse,
            'end_chapter'    => $item->end_chapter,
            'end_verse'      => $item->end_verse,
            'reference'      => $item->scriptureReference(),
        ];
    }
}

<?php

use App\Models\Playlist;
use App\Models\PlaylistItem;
use App\Models\Song;
use App\Models\User;

/*
 * Phase 3 — Playlist item CRUD + reorder.
 *
 * Authorization: owner OR admin for every mutation.
 * Reorder: requires the full set of item ids in their new order.
 */

// ── store / add ──────────────────────────────────────────────────────

test('owner can add a song to their playlist', function () {
    $owner = User::factory()->create();
    $playlist = Playlist::factory()->create(['created_by' => $owner->id]);
    $song = Song::factory()->create();

    $this->actingAs($owner)->postJson("/api/playlists/{$playlist->id}/items", [
        'song_id' => $song->id,
    ])
        ->assertCreated()
        ->assertJsonPath('song_id', $song->id)
        ->assertJsonPath('position', 0);
});

test('add appends to the end by default', function () {
    $owner = User::factory()->create();
    $playlist = Playlist::factory()->create(['created_by' => $owner->id]);
    PlaylistItem::factory()->count(2)
        ->sequence(fn ($s) => ['position' => $s->index])
        ->create(['playlist_id' => $playlist->id]);
    $song = Song::factory()->create();

    $this->actingAs($owner)->postJson("/api/playlists/{$playlist->id}/items", [
        'song_id' => $song->id,
    ])
        ->assertCreated()
        ->assertJsonPath('position', 2);
});

test('add at explicit position shifts subsequent items down', function () {
    $owner = User::factory()->create();
    $playlist = Playlist::factory()->create(['created_by' => $owner->id]);
    $a = PlaylistItem::factory()->create(['playlist_id' => $playlist->id, 'position' => 0]);
    $b = PlaylistItem::factory()->create(['playlist_id' => $playlist->id, 'position' => 1]);

    $this->actingAs($owner)->postJson("/api/playlists/{$playlist->id}/items", [
        'song_id'  => Song::factory()->create()->id,
        'position' => 0,
    ])->assertCreated()->assertJsonPath('position', 0);

    expect(PlaylistItem::find($a->id)->position)->toBe(1);
    expect(PlaylistItem::find($b->id)->position)->toBe(2);
});

test('non-owner cannot add an item', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $playlist = Playlist::factory()->create(['created_by' => $owner->id]);
    $song = Song::factory()->create();

    $this->actingAs($other)->postJson("/api/playlists/{$playlist->id}/items", [
        'song_id' => $song->id,
    ])->assertStatus(403);
});

test('add validates song_id exists', function () {
    $owner = User::factory()->create();
    $playlist = Playlist::factory()->create(['created_by' => $owner->id]);

    $this->actingAs($owner)->postJson("/api/playlists/{$playlist->id}/items", [
        'song_id' => fake()->uuid(),
    ])->assertStatus(422);
});

test('re-adding a song already in the playlist is a no-op', function () {
    $owner = User::factory()->create();
    $playlist = Playlist::factory()->create(['created_by' => $owner->id]);
    $song = Song::factory()->create();

    $first = $this->actingAs($owner)->postJson("/api/playlists/{$playlist->id}/items", [
        'song_id' => $song->id,
    ])->assertCreated()->json();

    // Second add of the same song returns 200 (not 201) with the same item,
    // and does not create a duplicate row.
    $this->actingAs($owner)->postJson("/api/playlists/{$playlist->id}/items", [
        'song_id' => $song->id,
    ])
        ->assertOk()
        ->assertJsonPath('id', $first['id'])
        ->assertJsonPath('song_id', $song->id);

    expect(PlaylistItem::where('playlist_id', $playlist->id)
        ->where('song_id', $song->id)->count())->toBe(1);
});

// ── update ───────────────────────────────────────────────────────────

test('owner can update target_key and notes', function () {
    $owner = User::factory()->create();
    $playlist = Playlist::factory()->create(['created_by' => $owner->id]);
    $item = PlaylistItem::factory()->create([
        'playlist_id' => $playlist->id, 'position' => 0,
    ]);

    $this->actingAs($owner)->putJson("/api/playlists/{$playlist->id}/items/{$item->id}", [
        'target_key' => 'F#m',
        'notes'      => 'capo 2',
    ])
        ->assertOk()
        ->assertJsonPath('target_key', 'F#m')
        ->assertJsonPath('notes', 'capo 2');
});

test('update rejects invalid key', function () {
    $owner = User::factory()->create();
    $playlist = Playlist::factory()->create(['created_by' => $owner->id]);
    $item = PlaylistItem::factory()->create(['playlist_id' => $playlist->id, 'position' => 0]);

    $this->actingAs($owner)->putJson("/api/playlists/{$playlist->id}/items/{$item->id}", [
        'target_key' => 'NotAKey',
    ])->assertStatus(422);
});

// ── destroy ──────────────────────────────────────────────────────────

test('removing an item compacts subsequent positions', function () {
    $owner = User::factory()->create();
    $playlist = Playlist::factory()->create(['created_by' => $owner->id]);
    $a = PlaylistItem::factory()->create(['playlist_id' => $playlist->id, 'position' => 0]);
    $b = PlaylistItem::factory()->create(['playlist_id' => $playlist->id, 'position' => 1]);
    $c = PlaylistItem::factory()->create(['playlist_id' => $playlist->id, 'position' => 2]);

    $this->actingAs($owner)->deleteJson("/api/playlists/{$playlist->id}/items/{$b->id}")
        ->assertStatus(204);

    expect(PlaylistItem::find($a->id)->position)->toBe(0);
    expect(PlaylistItem::find($b->id))->toBeNull();
    expect(PlaylistItem::find($c->id)->position)->toBe(1);
});

// ── reorder ──────────────────────────────────────────────────────────

test('reorder applies the new ordering', function () {
    $owner = User::factory()->create();
    $playlist = Playlist::factory()->create(['created_by' => $owner->id]);
    $a = PlaylistItem::factory()->create(['playlist_id' => $playlist->id, 'position' => 0]);
    $b = PlaylistItem::factory()->create(['playlist_id' => $playlist->id, 'position' => 1]);
    $c = PlaylistItem::factory()->create(['playlist_id' => $playlist->id, 'position' => 2]);

    $this->actingAs($owner)->putJson("/api/playlists/{$playlist->id}/items/reorder", [
        'item_ids' => [$c->id, $a->id, $b->id],
    ])->assertOk();

    expect(PlaylistItem::find($c->id)->position)->toBe(0);
    expect(PlaylistItem::find($a->id)->position)->toBe(1);
    expect(PlaylistItem::find($b->id)->position)->toBe(2);
});

test('reorder rejects partial id list', function () {
    $owner = User::factory()->create();
    $playlist = Playlist::factory()->create(['created_by' => $owner->id]);
    $a = PlaylistItem::factory()->create(['playlist_id' => $playlist->id, 'position' => 0]);
    PlaylistItem::factory()->create(['playlist_id' => $playlist->id, 'position' => 1]);

    $this->actingAs($owner)->putJson("/api/playlists/{$playlist->id}/items/reorder", [
        'item_ids' => [$a->id],
    ])->assertStatus(422);
});

test('reorder rejects foreign item id', function () {
    $owner = User::factory()->create();
    $playlist = Playlist::factory()->create(['created_by' => $owner->id]);
    $other = Playlist::factory()->create();
    $foreignItem = PlaylistItem::factory()->create(['playlist_id' => $other->id, 'position' => 0]);

    $a = PlaylistItem::factory()->create(['playlist_id' => $playlist->id, 'position' => 0]);

    $this->actingAs($owner)->putJson("/api/playlists/{$playlist->id}/items/reorder", [
        'item_ids' => [$foreignItem->id, $a->id],
    ])->assertStatus(422);
});

// ── scripture items (FR-PL-2) ────────────────────────────────────────

test('owner can add a scripture reading to their playlist', function () {
    $owner = User::factory()->create();
    $playlist = Playlist::factory()->create(['created_by' => $owner->id]);

    $this->actingAs($owner)->postJson("/api/playlists/{$playlist->id}/items", [
        'item_type'      => 'scripture',
        'translation_id' => 'BSB',
        'book_code'      => 'JHN',
        'start_chapter'  => 3,
        'start_verse'    => 16,
        'end_chapter'    => 4,
        'end_verse'      => 2,
    ])
        ->assertCreated()
        ->assertJsonPath('item_type', 'scripture')
        ->assertJsonPath('song_id', null)
        ->assertJsonPath('song', null)
        ->assertJsonPath('scripture.book_code', 'JHN')
        ->assertJsonPath('scripture.reference', 'JHN 3:16-4:2');

    $this->assertDatabaseHas('playlist_items', [
        'playlist_id' => $playlist->id,
        'item_type'   => 'scripture',
        'book_code'   => 'JHN',
        'song_id'     => null,
    ]);
});

test('scripture add requires book_code, start_chapter and start_verse', function () {
    $owner = User::factory()->create();
    $playlist = Playlist::factory()->create(['created_by' => $owner->id]);

    $this->actingAs($owner)->postJson("/api/playlists/{$playlist->id}/items", [
        'item_type' => 'scripture',
    ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['book_code', 'start_chapter', 'start_verse']);
});

test('scripture add rejects a backwards verse range', function () {
    $owner = User::factory()->create();
    $playlist = Playlist::factory()->create(['created_by' => $owner->id]);

    $this->actingAs($owner)->postJson("/api/playlists/{$playlist->id}/items", [
        'item_type'     => 'scripture',
        'book_code'     => 'JHN',
        'start_chapter' => 3,
        'start_verse'   => 16,
        'end_verse'     => 10,
    ])->assertStatus(422)->assertJsonValidationErrors(['end_verse']);
});

test('a single-verse reference has no range suffix', function () {
    $owner = User::factory()->create();
    $playlist = Playlist::factory()->create(['created_by' => $owner->id]);

    $this->actingAs($owner)->postJson("/api/playlists/{$playlist->id}/items", [
        'item_type'     => 'scripture',
        'book_code'     => 'PSA',
        'start_chapter' => 23,
        'start_verse'   => 1,
    ])
        ->assertCreated()
        ->assertJsonPath('scripture.reference', 'PSA 23:1');
});

test('scripture readings are not de-duped the way songs are', function () {
    $owner = User::factory()->create();
    $playlist = Playlist::factory()->create(['created_by' => $owner->id]);
    $body = [
        'item_type'     => 'scripture',
        'book_code'     => 'JHN',
        'start_chapter' => 3,
        'start_verse'   => 16,
    ];

    $this->actingAs($owner)->postJson("/api/playlists/{$playlist->id}/items", $body)->assertCreated();
    // Same reference again → a second row (201), unlike duplicate songs.
    $this->actingAs($owner)->postJson("/api/playlists/{$playlist->id}/items", $body)->assertCreated();

    expect(PlaylistItem::where('playlist_id', $playlist->id)->count())->toBe(2);
});

test('a playlist interleaves songs and scripture, reorders, and round-trips', function () {
    $owner = User::factory()->create();
    $playlist = Playlist::factory()->create(['created_by' => $owner->id]);
    $song = PlaylistItem::factory()->create(['playlist_id' => $playlist->id, 'position' => 0]);
    $reading = PlaylistItem::factory()->scripture()->create([
        'playlist_id' => $playlist->id, 'position' => 1,
    ]);

    // Reorder puts the reading first.
    $this->actingAs($owner)->putJson("/api/playlists/{$playlist->id}/items/reorder", [
        'item_ids' => [$reading->id, $song->id],
    ])->assertOk();

    $this->actingAs($owner)->getJson("/api/playlists/{$playlist->id}")
        ->assertOk()
        ->assertJsonPath('items.0.item_type', 'scripture')
        ->assertJsonPath('items.0.scripture.reference', 'JHN 3:16')
        ->assertJsonPath('items.1.item_type', 'song')
        ->assertJsonPath('items.1.song_id', $song->song_id);
});

test('updating a scripture item edits its reference and notes', function () {
    $owner = User::factory()->create();
    $playlist = Playlist::factory()->create(['created_by' => $owner->id]);
    $item = PlaylistItem::factory()->scripture()->create([
        'playlist_id' => $playlist->id, 'position' => 0,
    ]);

    $this->actingAs($owner)->putJson("/api/playlists/{$playlist->id}/items/{$item->id}", [
        'start_verse' => 17,
        'notes'       => 'read slowly',
    ])
        ->assertOk()
        ->assertJsonPath('scripture.start_verse', 17)
        ->assertJsonPath('scripture.reference', 'JHN 3:17')
        ->assertJsonPath('notes', 'read slowly');
});

test('duplicating a playlist preserves scripture items', function () {
    $owner = User::factory()->create();
    $playlist = Playlist::factory()->create(['created_by' => $owner->id]);
    PlaylistItem::factory()->scripture()->create(['playlist_id' => $playlist->id, 'position' => 0]);

    $copyId = $this->actingAs($owner)
        ->postJson("/api/playlists/{$playlist->id}/duplicate")
        ->assertCreated()
        ->json('id');

    $this->actingAs($owner)->getJson("/api/playlists/{$copyId}")
        ->assertOk()
        ->assertJsonPath('items.0.item_type', 'scripture')
        ->assertJsonPath('items.0.scripture.book_code', 'JHN');
});

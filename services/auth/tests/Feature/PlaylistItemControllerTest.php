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

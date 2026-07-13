<?php

use App\Models\Playlist;
use App\Models\Song;
use App\Models\User;

/*
 * Stage 4 — GET /tags (FR-PL-1).
 *
 * Returns the sorted, distinct union of tags across songs and playlists.
 * Soft-deleted songs are excluded.
 */

test('tags endpoint requires authentication', function () {
    $this->getJson('/api/tags')->assertStatus(401);
});

test('tags endpoint returns the sorted distinct union of song and playlist tags', function () {
    $user = User::factory()->create();

    Song::factory()->create(['tags' => ['communion', 'advent']]);
    Playlist::factory()->create(['tags' => ['advent', 'youth']]);

    $this->actingAs($user)->getJson('/api/tags')
        ->assertOk()
        ->assertJsonPath('data', ['advent', 'communion', 'youth']);
});

test('tags endpoint excludes soft-deleted songs', function () {
    $user = User::factory()->create();

    $trashed = Song::factory()->create(['tags' => ['orphan-tag']]);
    Song::factory()->create(['tags' => ['kept-tag']]);
    $trashed->delete();

    $this->actingAs($user)->getJson('/api/tags')
        ->assertOk()
        ->assertJsonPath('data', ['kept-tag']);
});

test('tags endpoint returns an empty list when nothing is tagged', function () {
    $user = User::factory()->create();

    Song::factory()->create(['tags' => []]);
    Playlist::factory()->create(['tags' => []]);

    $this->actingAs($user)->getJson('/api/tags')
        ->assertOk()
        ->assertJsonPath('data', []);
});

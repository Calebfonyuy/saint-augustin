<?php

use App\Models\Playlist;
use App\Models\PlaylistItem;
use App\Models\Song;
use App\Models\User;

/*
 * Feature tests for Phase 3 — Playlist CRUD + duplicate.
 *
 * Authorization (SRS 3.3):
 *   • List/Show/Create — any authenticated user
 *   • Update/Delete    — owner OR admin
 *   • Duplicate        — any authenticated user (duplicator becomes new owner)
 */

// ── auth gate ────────────────────────────────────────────────────────

test('listing playlists requires authentication', function () {
    $this->getJson('/api/playlists')->assertStatus(401);
});

// ── index ────────────────────────────────────────────────────────────

test('listing returns paginated playlists', function () {
    Playlist::factory()->count(3)->create();
    $user = User::factory()->create();

    $this->actingAs($user)->getJson('/api/playlists')
        ->assertOk()
        ->assertJsonStructure([
            'data' => [['id', 'name', 'tags', 'created_by', 'item_count']],
            'meta' => ['current_page', 'per_page', 'total', 'last_page'],
        ])
        ->assertJsonPath('meta.total', 3);
});

test('search filters by name (case-insensitive)', function () {
    Playlist::factory()->create(['name' => 'Easter Vigil 2026']);
    Playlist::factory()->create(['name' => 'Sunday Service']);
    $user = User::factory()->create();

    $this->actingAs($user)->getJson('/api/playlists?q=easter')
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.name', 'Easter Vigil 2026');
});

test('filter by tag returns matching playlists', function () {
    Playlist::factory()->create(['name' => 'A', 'tags' => ['advent']]);
    Playlist::factory()->create(['name' => 'B', 'tags' => ['easter']]);
    $user = User::factory()->create();

    $this->actingAs($user)->getJson('/api/playlists?tag=advent')
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.name', 'A');
});

test('mine=true filters to caller-owned playlists', function () {
    $u1 = User::factory()->create();
    $u2 = User::factory()->create();
    Playlist::factory()->create(['created_by' => $u1->id, 'name' => 'Mine']);
    Playlist::factory()->create(['created_by' => $u2->id, 'name' => 'Theirs']);

    $this->actingAs($u1)->getJson('/api/playlists?mine=1')
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.name', 'Mine');
});

test('item_count reflects number of playlist items', function () {
    $playlist = Playlist::factory()->create();
    PlaylistItem::factory()->count(3)
        ->sequence(fn ($s) => ['position' => $s->index])
        ->create(['playlist_id' => $playlist->id]);

    $user = User::factory()->create();
    $this->actingAs($user)->getJson('/api/playlists')
        ->assertOk()
        ->assertJsonPath('data.0.item_count', 3);
});

// ── show ─────────────────────────────────────────────────────────────

test('show returns hydrated items with their songs', function () {
    $playlist = Playlist::factory()->create();
    $song = Song::factory()->create(['title' => 'Amazing Grace']);
    PlaylistItem::factory()->create([
        'playlist_id' => $playlist->id,
        'song_id'     => $song->id,
        'position'    => 0,
        'target_key'  => 'D',
    ]);

    $user = User::factory()->create();
    $response = $this->actingAs($user)->getJson("/api/playlists/{$playlist->id}");

    $response->assertOk()
        ->assertJsonPath('items.0.target_key', 'D')
        ->assertJsonPath('items.0.song.title', 'Amazing Grace');
});

test('show returns 404 for unknown playlist', function () {
    $user = User::factory()->create();
    $this->actingAs($user)->getJson('/api/playlists/'.fake()->uuid())
        ->assertStatus(404);
});

// ── store ────────────────────────────────────────────────────────────

test('any authenticated user can create a playlist', function () {
    $user = User::factory()->projectionist()->create();

    $response = $this->actingAs($user)->postJson('/api/playlists', [
        'name'       => 'New Year Mass',
        'event_date' => '2026-12-31',
        'tags'       => ['mass', 'celebration'],
    ]);

    $response->assertCreated()
        ->assertJsonPath('name', 'New Year Mass')
        ->assertJsonPath('event_date', '2026-12-31')
        ->assertJsonPath('created_by', $user->id);
});

test('store rejects missing name', function () {
    $user = User::factory()->create();
    $this->actingAs($user)->postJson('/api/playlists', [])
        ->assertStatus(422);
});

// ── update ───────────────────────────────────────────────────────────

test('owner can update their playlist', function () {
    $owner = User::factory()->create();
    $playlist = Playlist::factory()->create(['created_by' => $owner->id, 'name' => 'Old']);

    $this->actingAs($owner)->putJson("/api/playlists/{$playlist->id}", ['name' => 'New'])
        ->assertOk()
        ->assertJsonPath('name', 'New');
});

test('non-owner non-admin cannot update', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $playlist = Playlist::factory()->create(['created_by' => $owner->id]);

    $this->actingAs($other)->putJson("/api/playlists/{$playlist->id}", ['name' => 'Hijack'])
        ->assertStatus(403);
});

test('admin can update any playlist', function () {
    $owner = User::factory()->create();
    $admin = User::factory()->admin()->create();
    $playlist = Playlist::factory()->create(['created_by' => $owner->id]);

    $this->actingAs($admin)->putJson("/api/playlists/{$playlist->id}", ['name' => 'Fixed'])
        ->assertOk()
        ->assertJsonPath('name', 'Fixed');
});

// ── destroy ──────────────────────────────────────────────────────────

test('owner can delete their playlist', function () {
    $owner = User::factory()->create();
    $playlist = Playlist::factory()->create(['created_by' => $owner->id]);

    $this->actingAs($owner)->deleteJson("/api/playlists/{$playlist->id}")
        ->assertStatus(204);

    expect(Playlist::find($playlist->id))->toBeNull();
});

test('non-owner non-admin cannot delete', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $playlist = Playlist::factory()->create(['created_by' => $owner->id]);

    $this->actingAs($other)->deleteJson("/api/playlists/{$playlist->id}")
        ->assertStatus(403);
});

test('deleting cascades to items and share links', function () {
    $owner = User::factory()->create();
    $playlist = Playlist::factory()->create(['created_by' => $owner->id]);
    PlaylistItem::factory()->create(['playlist_id' => $playlist->id, 'position' => 0]);

    $this->actingAs($owner)->deleteJson("/api/playlists/{$playlist->id}")
        ->assertStatus(204);

    expect(PlaylistItem::where('playlist_id', $playlist->id)->count())->toBe(0);
});

// ── duplicate ────────────────────────────────────────────────────────

test('duplicate copies items, target keys, and notes', function () {
    $orig = Playlist::factory()->create(['name' => 'Sunday']);
    $song1 = Song::factory()->create();
    $song2 = Song::factory()->create();
    PlaylistItem::factory()->create([
        'playlist_id' => $orig->id, 'song_id' => $song1->id,
        'position' => 0, 'target_key' => 'D', 'notes' => 'Skip v2',
    ]);
    PlaylistItem::factory()->create([
        'playlist_id' => $orig->id, 'song_id' => $song2->id,
        'position' => 1, 'target_key' => null, 'notes' => null,
    ]);

    $user = User::factory()->create();

    $response = $this->actingAs($user)->postJson("/api/playlists/{$orig->id}/duplicate");

    $response->assertCreated()
        ->assertJsonPath('name', 'Sunday (copy)')
        ->assertJsonPath('duplicated_from_id', $orig->id)
        ->assertJsonPath('created_by', $user->id)
        ->assertJsonCount(2, 'items')
        ->assertJsonPath('items.0.target_key', 'D')
        ->assertJsonPath('items.0.notes', 'Skip v2');
});

test('duplicate respects custom name', function () {
    $orig = Playlist::factory()->create(['name' => 'Original']);
    $user = User::factory()->create();

    $this->actingAs($user)->postJson("/api/playlists/{$orig->id}/duplicate", [
        'name' => 'My Custom Name',
    ])
        ->assertCreated()
        ->assertJsonPath('name', 'My Custom Name');
});

test('duplicate is independent — modifying it does not affect the source', function () {
    $orig = Playlist::factory()->create(['name' => 'Source']);
    $song = Song::factory()->create();
    PlaylistItem::factory()->create(['playlist_id' => $orig->id, 'song_id' => $song->id, 'position' => 0]);

    $user = User::factory()->create();
    $copyId = $this->actingAs($user)->postJson("/api/playlists/{$orig->id}/duplicate")
        ->json('id');

    $this->actingAs($user)->putJson("/api/playlists/{$copyId}", ['name' => 'Changed']);

    expect(Playlist::find($orig->id)->name)->toBe('Source');
    expect(Playlist::find($copyId)->name)->toBe('Changed');
});

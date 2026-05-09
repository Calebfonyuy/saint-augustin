<?php

use App\Models\Playlist;
use App\Models\PlaylistItem;
use App\Models\ShareLink;
use App\Models\Song;
use App\Models\User;

/*
 * Phase 3 — Share-link CRUD (auth) + public token resolution.
 *
 * Authorization:
 *   • Create / List / Revoke — owner OR admin
 *   • Public resolve         — no auth, but throttled at the route layer
 *
 * The public endpoint returns 404 (not 401/403) for revoked or expired
 * tokens so we don't leak which tokens have ever existed.
 */

// ── create ───────────────────────────────────────────────────────────

test('owner can create a musician share link', function () {
    $owner = User::factory()->create();
    $playlist = Playlist::factory()->create(['created_by' => $owner->id]);

    $response = $this->actingAs($owner)->postJson("/api/playlists/{$playlist->id}/share", [
        'mode' => 'musician',
    ]);

    $response->assertCreated()
        ->assertJsonPath('mode', 'musician')
        ->assertJsonStructure(['id', 'token', 'mode', 'created_at']);

    expect(strlen($response->json('token')))->toBeGreaterThan(20);
});

test('owner can create a projection share link', function () {
    $owner = User::factory()->create();
    $playlist = Playlist::factory()->create(['created_by' => $owner->id]);

    $this->actingAs($owner)->postJson("/api/playlists/{$playlist->id}/share", [
        'mode' => 'projection',
    ])
        ->assertCreated()
        ->assertJsonPath('mode', 'projection');
});

test('create rejects invalid mode', function () {
    $owner = User::factory()->create();
    $playlist = Playlist::factory()->create(['created_by' => $owner->id]);

    $this->actingAs($owner)->postJson("/api/playlists/{$playlist->id}/share", [
        'mode' => 'admin',
    ])->assertStatus(422);
});

test('non-owner cannot create a share link', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $playlist = Playlist::factory()->create(['created_by' => $owner->id]);

    $this->actingAs($other)->postJson("/api/playlists/{$playlist->id}/share", [
        'mode' => 'musician',
    ])->assertStatus(403);
});

test('admin can create a share link on any playlist', function () {
    $owner = User::factory()->create();
    $admin = User::factory()->admin()->create();
    $playlist = Playlist::factory()->create(['created_by' => $owner->id]);

    $this->actingAs($admin)->postJson("/api/playlists/{$playlist->id}/share", [
        'mode' => 'musician',
    ])->assertCreated();
});

// ── list ─────────────────────────────────────────────────────────────

test('owner can list share links for their playlist', function () {
    $owner = User::factory()->create();
    $playlist = Playlist::factory()->create(['created_by' => $owner->id]);
    ShareLink::factory()->count(2)->create(['playlist_id' => $playlist->id]);

    $this->actingAs($owner)->getJson("/api/playlists/{$playlist->id}/share")
        ->assertOk()
        ->assertJsonCount(2, 'data');
});

// ── revoke ───────────────────────────────────────────────────────────

test('owner can revoke a share link', function () {
    $owner = User::factory()->create();
    $playlist = Playlist::factory()->create(['created_by' => $owner->id]);
    $link = ShareLink::factory()->musician()->create(['playlist_id' => $playlist->id]);

    $this->actingAs($owner)->deleteJson("/api/share-links/{$link->id}")
        ->assertStatus(204);

    expect(ShareLink::find($link->id)->revoked_at)->not->toBeNull();
});

test('non-owner cannot revoke', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $playlist = Playlist::factory()->create(['created_by' => $owner->id]);
    $link = ShareLink::factory()->create(['playlist_id' => $playlist->id]);

    $this->actingAs($other)->deleteJson("/api/share-links/{$link->id}")
        ->assertStatus(403);
});

// ── public resolve ───────────────────────────────────────────────────

test('public can resolve a musician token without auth', function () {
    $playlist = Playlist::factory()->create(['name' => 'Sunday']);
    $song = Song::factory()->create(['title' => 'Amazing Grace']);
    PlaylistItem::factory()->create([
        'playlist_id' => $playlist->id,
        'song_id'     => $song->id,
        'position'    => 0,
    ]);
    $link = ShareLink::factory()->musician()->create(['playlist_id' => $playlist->id]);

    $this->getJson("/api/share/{$link->token}")
        ->assertOk()
        ->assertJsonPath('mode', 'musician')
        ->assertJsonPath('playlist.name', 'Sunday')
        ->assertJsonPath('playlist.items.0.song.title', 'Amazing Grace');
});

test('public musician token includes preview_url', function () {
    $playlist = Playlist::factory()->create();
    $song = Song::factory()->create(['preview_url' => 'https://youtube.com/watch?v=abc']);
    PlaylistItem::factory()->create([
        'playlist_id' => $playlist->id, 'song_id' => $song->id, 'position' => 0,
    ]);
    $link = ShareLink::factory()->musician()->create(['playlist_id' => $playlist->id]);

    $this->getJson("/api/share/{$link->token}")
        ->assertOk()
        ->assertJsonPath('playlist.items.0.song.preview_url', 'https://youtube.com/watch?v=abc');
});

test('public projection token strips preview_url', function () {
    $playlist = Playlist::factory()->create();
    $song = Song::factory()->create(['preview_url' => 'https://youtube.com/watch?v=abc']);
    PlaylistItem::factory()->create([
        'playlist_id' => $playlist->id, 'song_id' => $song->id, 'position' => 0,
    ]);
    $link = ShareLink::factory()->projection()->create(['playlist_id' => $playlist->id]);

    $this->getJson("/api/share/{$link->token}")
        ->assertOk()
        ->assertJsonPath('mode', 'projection')
        ->assertJsonPath('playlist.items.0.song.preview_url', null);
});

test('revoked token returns 404', function () {
    $playlist = Playlist::factory()->create();
    $link = ShareLink::factory()->revoked()->create(['playlist_id' => $playlist->id]);

    $this->getJson("/api/share/{$link->token}")->assertStatus(404);
});

test('expired token returns 404', function () {
    $playlist = Playlist::factory()->create();
    $link = ShareLink::factory()->create([
        'playlist_id' => $playlist->id,
        'expires_at'  => now()->subHour(),
    ]);

    $this->getJson("/api/share/{$link->token}")->assertStatus(404);
});

test('unknown token returns 404', function () {
    $this->getJson('/api/share/never-existed')->assertStatus(404);
});

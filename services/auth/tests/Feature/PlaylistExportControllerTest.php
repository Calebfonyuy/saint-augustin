<?php

use App\Models\Playlist;
use App\Models\PlaylistItem;
use App\Models\Song;
use App\Models\User;

/*
 * Phase 3 — Playlist export (PDF + plain text).
 *
 * Both formats are generated synchronously (no queue). Any authenticated
 * user can export any playlist. The Blade-based PDF rendering is exercised
 * end-to-end with DomPDF; the txt branch is a simple deterministic string.
 */

test('export requires authentication', function () {
    $playlist = Playlist::factory()->create();
    $this->getJson("/api/playlists/{$playlist->id}/export")->assertStatus(401);
});

test('txt export contains song lines with keys', function () {
    $playlist = Playlist::factory()->create(['name' => 'Sunday Morning']);
    $song1 = Song::factory()->create(['title' => 'Amazing Grace', 'author' => 'John Newton', 'original_key' => 'G']);
    $song2 = Song::factory()->create(['title' => 'Holy, Holy, Holy', 'author' => 'Reginald Heber', 'original_key' => 'D']);
    PlaylistItem::factory()->create([
        'playlist_id' => $playlist->id, 'song_id' => $song1->id, 'position' => 0,
        'target_key' => 'D',
    ]);
    PlaylistItem::factory()->create([
        'playlist_id' => $playlist->id, 'song_id' => $song2->id, 'position' => 1,
    ]);

    $user = User::factory()->create();

    $response = $this->actingAs($user)->get("/api/playlists/{$playlist->id}/export?format=txt");

    $response->assertOk();
    $body = $response->getContent();
    expect($body)->toContain('Sunday Morning');
    expect($body)->toContain('Amazing Grace');
    expect($body)->toContain('John Newton');
    expect($body)->toContain('[D]');
    expect($body)->toContain('Holy, Holy, Holy');
});

test('txt export renders scripture readings by reference', function () {
    $playlist = Playlist::factory()->create(['name' => 'Sunday Morning']);
    PlaylistItem::factory()->create([
        'playlist_id' => $playlist->id, 'position' => 0,
        'song_id' => Song::factory()->create(['title' => 'Amazing Grace'])->id,
    ]);
    PlaylistItem::factory()->scripture(['end_chapter' => 4, 'end_verse' => 2])->create([
        'playlist_id' => $playlist->id, 'position' => 1,
    ]);

    $user = User::factory()->create();
    $response = $this->actingAs($user)->get("/api/playlists/{$playlist->id}/export?format=txt");

    $response->assertOk();
    $body = $response->getContent();
    expect($body)->toContain('Amazing Grace');
    expect($body)->toContain('JHN 3:16-4:2');
    expect($body)->toContain('[reading]');
});

test('pdf export returns a downloadable PDF', function () {
    $playlist = Playlist::factory()->create(['name' => 'Test Mass']);
    $song = Song::factory()->create();
    PlaylistItem::factory()->create([
        'playlist_id' => $playlist->id, 'song_id' => $song->id, 'position' => 0,
    ]);

    $user = User::factory()->create();
    $response = $this->actingAs($user)->get("/api/playlists/{$playlist->id}/export?format=pdf");

    $response->assertOk();
    $response->assertHeader('content-type', 'application/pdf');
    expect(substr($response->getContent(), 0, 5))->toBe('%PDF-');
});

test('export defaults to PDF', function () {
    $playlist = Playlist::factory()->create();
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get("/api/playlists/{$playlist->id}/export");

    $response->assertOk();
    expect(substr($response->getContent(), 0, 5))->toBe('%PDF-');
});

test('export rejects unsupported format', function () {
    $playlist = Playlist::factory()->create();
    $user = User::factory()->create();

    $this->actingAs($user)->getJson("/api/playlists/{$playlist->id}/export?format=docx")
        ->assertStatus(422);
});

test('export 404s for unknown playlist', function () {
    $user = User::factory()->create();
    $this->actingAs($user)->getJson('/api/playlists/'.fake()->uuid().'/export')
        ->assertStatus(404);
});

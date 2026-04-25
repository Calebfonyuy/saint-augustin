<?php

use App\Models\Song;
use App\Models\Songbook;
use App\Models\User;

/*
 * Feature tests for SongController — index (search/filter), show, store,
 * update, destroy (soft-delete), restore.
 *
 * Authorization (SRS 3.1.2):
 *   • Read           — any authenticated user
 *   • Create/Update  — admin or musician
 *   • Delete/Restore — admin only
 */

// ── index: list / search / filter ────────────────────────────────────

test('listing songs requires authentication', function () {
    $this->getJson('/api/songs')->assertStatus(401);
});

test('listing returns paginated songs', function () {
    Song::factory()->count(3)->create();
    $user = User::factory()->create();

    $this->actingAs($user)->getJson('/api/songs')
        ->assertOk()
        ->assertJsonStructure([
            'data' => [['id', 'title', 'lyrics', 'songbook_id', 'version']],
            'meta' => ['current_page', 'per_page', 'total', 'last_page'],
        ])
        ->assertJsonPath('meta.total', 3);
});

test('search matches title (case-insensitive)', function () {
    // SongFactory hardcodes "[G]grace" in its default lyrics, so we override
    // both lyrics fields to keep the search term unique to a single title.
    Song::factory()->create(['title' => 'Marker-Xyz Hymn', 'lyrics' => 'plain content']);
    Song::factory()->create(['title' => 'Other Song',     'lyrics' => 'other content']);
    $user = User::factory()->create();

    $response = $this->actingAs($user)->getJson('/api/songs?q=marker-xyz');

    $response->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.title', 'Marker-Xyz Hymn');
});

test('search matches lyrics content', function () {
    Song::factory()->create(['title' => 'Hidden', 'lyrics' => 'mysterious unique-marker phrase']);
    Song::factory()->create(['title' => 'Other', 'lyrics' => 'nothing here']);
    $user = User::factory()->create();

    $this->actingAs($user)->getJson('/api/songs?q=unique-marker')
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.title', 'Hidden');
});

test('search matches an exact tag', function () {
    Song::factory()->create(['title' => 'A', 'tags' => ['praise', 'communion']]);
    Song::factory()->create(['title' => 'B', 'tags' => ['lent']]);
    $user = User::factory()->create();

    $this->actingAs($user)->getJson('/api/songs?q=communion')
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.title', 'A');
});

test('filter by songbook', function () {
    $sb1 = Songbook::factory()->create();
    $sb2 = Songbook::factory()->create();
    Song::factory()->count(2)->create(['songbook_id' => $sb1->id]);
    Song::factory()->create(['songbook_id' => $sb2->id]);

    $user = User::factory()->create();

    $this->actingAs($user)->getJson("/api/songs?songbook={$sb1->id}")
        ->assertOk()
        ->assertJsonPath('meta.total', 2);
});

test('filter by key', function () {
    Song::factory()->create(['original_key' => 'G']);
    Song::factory()->create(['original_key' => 'C']);
    $user = User::factory()->create();

    $this->actingAs($user)->getJson('/api/songs?key=G')
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.original_key', 'G');
});

test('filter by tag', function () {
    Song::factory()->create(['tags' => ['advent']]);
    Song::factory()->create(['tags' => ['easter']]);
    $user = User::factory()->create();

    $this->actingAs($user)->getJson('/api/songs?tag=advent')
        ->assertOk()
        ->assertJsonPath('meta.total', 1);
});

test('invalid key parameter returns 422', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->getJson('/api/songs?key=H')
        ->assertStatus(422)
        ->assertJsonValidationErrors(['key']);
});

test('soft-deleted songs are excluded by default', function () {
    $song = Song::factory()->create();
    $song->delete();

    $user = User::factory()->create();

    $this->actingAs($user)->getJson('/api/songs')
        ->assertOk()
        ->assertJsonPath('meta.total', 0);
});

test('trashed=only lists only soft-deleted songs', function () {
    Song::factory()->create();          // alive
    $deleted = Song::factory()->create();
    $deleted->delete();

    $user = User::factory()->create();

    $this->actingAs($user)->getJson('/api/songs?trashed=only')
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.id', $deleted->id);
});

test('trashed=with includes soft-deleted songs alongside live ones', function () {
    Song::factory()->create();
    $deleted = Song::factory()->create();
    $deleted->delete();

    $user = User::factory()->create();

    $this->actingAs($user)->getJson('/api/songs?trashed=with')
        ->assertOk()
        ->assertJsonPath('meta.total', 2);
});

test('per_page caps at 100', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->getJson('/api/songs?per_page=999')
        ->assertStatus(422)
        ->assertJsonValidationErrors(['per_page']);
});

// ── show ─────────────────────────────────────────────────────────────

test('show returns a single song', function () {
    $song = Song::factory()->create(['title' => 'Doxology']);
    $user = User::factory()->create();

    $this->actingAs($user)->getJson("/api/songs/{$song->id}")
        ->assertOk()
        ->assertJsonPath('title', 'Doxology');
});

test('show returns 404 for a missing song', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->getJson('/api/songs/00000000-0000-0000-0000-000000000000')
        ->assertStatus(404);
});

// ── store ────────────────────────────────────────────────────────────

test('creating a song requires authentication', function () {
    $songbook = Songbook::factory()->create();

    $this->postJson('/api/songs', [
        'title'       => 'X',
        'lyrics'      => 'l',
        'songbook_id' => $songbook->id,
    ])->assertStatus(401);
});

test('projectionists cannot create songs', function () {
    $user     = User::factory()->projectionist()->create();
    $songbook = Songbook::factory()->create();

    $this->actingAs($user)
        ->postJson('/api/songs', [
            'title'       => 'X',
            'lyrics'      => 'l',
            'songbook_id' => $songbook->id,
        ])->assertStatus(403);
});

test('musicians can create songs', function () {
    $user     = User::factory()->create();
    $songbook = Songbook::factory()->create();

    $response = $this->actingAs($user)->postJson('/api/songs', [
        'title'       => 'New Song',
        'lyrics'      => '[C]Lyrics here',
        'songbook_id' => $songbook->id,
    ]);

    $response->assertCreated()
        ->assertJsonPath('title', 'New Song')
        ->assertJsonPath('created_by', $user->id)
        ->assertJsonPath('version', 1);
});

test('admins can create songs', function () {
    $admin    = User::factory()->admin()->create();
    $songbook = Songbook::factory()->create();

    $this->actingAs($admin)->postJson('/api/songs', [
        'title'       => 'Admin Song',
        'lyrics'      => 'l',
        'songbook_id' => $songbook->id,
    ])->assertCreated();
});

test('store requires title, lyrics, and songbook_id', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->postJson('/api/songs', [])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['title', 'lyrics', 'songbook_id']);
});

test('store rejects an invalid musical key', function () {
    $user     = User::factory()->create();
    $songbook = Songbook::factory()->create();

    $this->actingAs($user)
        ->postJson('/api/songs', [
            'title'        => 'X',
            'lyrics'       => 'l',
            'songbook_id'  => $songbook->id,
            'original_key' => 'Z',
        ])->assertStatus(422)
          ->assertJsonValidationErrors(['original_key']);
});

test('store rejects an invalid time signature', function () {
    $user     = User::factory()->create();
    $songbook = Songbook::factory()->create();

    $this->actingAs($user)
        ->postJson('/api/songs', [
            'title'          => 'X',
            'lyrics'         => 'l',
            'songbook_id'    => $songbook->id,
            'time_signature' => '7/4',
        ])->assertStatus(422)
          ->assertJsonValidationErrors(['time_signature']);
});

test('store rejects tempo out of range', function () {
    $user     = User::factory()->create();
    $songbook = Songbook::factory()->create();

    $this->actingAs($user)
        ->postJson('/api/songs', [
            'title'       => 'X',
            'lyrics'      => 'l',
            'songbook_id' => $songbook->id,
            'tempo'       => 1000,
        ])->assertStatus(422)
          ->assertJsonValidationErrors(['tempo']);
});

test('store requires songbook_id to reference an existing songbook', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->postJson('/api/songs', [
            'title'       => 'X',
            'lyrics'      => 'l',
            'songbook_id' => '00000000-0000-0000-0000-000000000000',
        ])->assertStatus(422)
          ->assertJsonValidationErrors(['songbook_id']);
});

// ── update ───────────────────────────────────────────────────────────

test('updating requires authentication', function () {
    $song = Song::factory()->create();

    $this->putJson("/api/songs/{$song->id}", ['title' => 'X'])->assertStatus(401);
});

test('projectionists cannot update songs', function () {
    $user = User::factory()->projectionist()->create();
    $song = Song::factory()->create();

    $this->actingAs($user)
        ->putJson("/api/songs/{$song->id}", ['title' => 'New'])
        ->assertStatus(403);
});

test('musicians can update a song and version increments', function () {
    $user = User::factory()->create();
    $song = Song::factory()->create(['title' => 'Old', 'version' => 1]);

    $response = $this->actingAs($user)
        ->putJson("/api/songs/{$song->id}", ['title' => 'New']);

    $response->assertOk()
        ->assertJsonPath('title', 'New')
        ->assertJsonPath('version', 2);
});

test('update returns 404 for missing song', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->putJson('/api/songs/00000000-0000-0000-0000-000000000000', ['title' => 'X'])
        ->assertStatus(404);
});

// ── destroy (soft-delete) ────────────────────────────────────────────

test('deleting requires authentication', function () {
    $song = Song::factory()->create();

    $this->deleteJson("/api/songs/{$song->id}")->assertStatus(401);
});

test('musicians cannot delete songs', function () {
    $user = User::factory()->create();
    $song = Song::factory()->create();

    $this->actingAs($user)
        ->deleteJson("/api/songs/{$song->id}")
        ->assertStatus(403);
});

test('admins soft-delete songs (record stays in db with deleted_at)', function () {
    $admin = User::factory()->admin()->create();
    $song  = Song::factory()->create();

    $this->actingAs($admin)
        ->deleteJson("/api/songs/{$song->id}")
        ->assertStatus(204);

    $this->assertSoftDeleted('songs', ['id' => $song->id]);
});

test('delete returns 404 for missing song', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->deleteJson('/api/songs/00000000-0000-0000-0000-000000000000')
        ->assertStatus(404);
});

// ── restore ──────────────────────────────────────────────────────────

test('restore requires authentication', function () {
    $song = Song::factory()->create();
    $song->delete();

    $this->postJson("/api/songs/{$song->id}/restore")->assertStatus(401);
});

test('musicians cannot restore songs', function () {
    $user = User::factory()->create();
    $song = Song::factory()->create();
    $song->delete();

    $this->actingAs($user)
        ->postJson("/api/songs/{$song->id}/restore")
        ->assertStatus(403);
});

test('admins can restore a soft-deleted song', function () {
    $admin = User::factory()->admin()->create();
    $song  = Song::factory()->create();
    $song->delete();

    $response = $this->actingAs($admin)
        ->postJson("/api/songs/{$song->id}/restore");

    $response->assertOk()
        ->assertJsonPath('id', $song->id)
        ->assertJsonPath('deleted_at', null);

    $this->assertDatabaseHas('songs', ['id' => $song->id, 'deleted_at' => null]);
});

test('restore returns 404 for a song that is not soft-deleted', function () {
    $admin = User::factory()->admin()->create();
    $song  = Song::factory()->create(); // not deleted

    $this->actingAs($admin)
        ->postJson("/api/songs/{$song->id}/restore")
        ->assertStatus(404);
});

<?php

use App\Models\Song;
use App\Models\Songbook;
use App\Models\User;

/*
 * Feature tests for SongbookController — index / show / store / update / destroy.
 *
 * Authorization (SRS 2.2):
 *   • Read   — any authenticated user
 *   • Create / Update / Delete — admin only
 *
 * Special delete rules:
 *   • Default songbook cannot be deleted
 *   • A songbook with songs cannot be deleted (must move songs first)
 */

// ── index ────────────────────────────────────────────────────────────

test('listing songbooks requires authentication', function () {
    $this->getJson('/api/songbooks')->assertStatus(401);
});

test('any authenticated user can list songbooks', function () {
    Songbook::factory()->default()->create();
    Songbook::factory()->count(2)->create();

    $user = User::factory()->projectionist()->create();

    $response = $this->actingAs($user)->getJson('/api/songbooks');

    $response->assertOk()
        ->assertJsonCount(3)
        ->assertJsonStructure([['id', 'name', 'is_default', 'songs_count']]);
});

test('listing returns the default songbook first', function () {
    $default = Songbook::factory()->default()->create();
    Songbook::factory()->create(['name' => 'Aaa']);

    $user = User::factory()->create();

    $response = $this->actingAs($user)->getJson('/api/songbooks');

    expect($response->json('0.id'))->toBe($default->id);
});

test('listing includes songs_count', function () {
    $songbook = Songbook::factory()->create();
    Song::factory()->count(3)->create(['songbook_id' => $songbook->id]);

    $user = User::factory()->create();

    $response = $this->actingAs($user)->getJson('/api/songbooks');

    $payload = collect($response->json())->firstWhere('id', $songbook->id);
    expect($payload['songs_count'])->toBe(3);
});

// ── show ─────────────────────────────────────────────────────────────

test('show returns a single songbook', function () {
    $songbook = Songbook::factory()->create(['name' => 'Easter 2026']);
    $user     = User::factory()->create();

    $this->actingAs($user)->getJson("/api/songbooks/{$songbook->id}")
        ->assertOk()
        ->assertJsonPath('id', $songbook->id)
        ->assertJsonPath('name', 'Easter 2026');
});

test('show returns 404 for a missing songbook', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->getJson('/api/songbooks/00000000-0000-0000-0000-000000000000')
        ->assertStatus(404);
});

// ── store ────────────────────────────────────────────────────────────

test('creating requires authentication', function () {
    $this->postJson('/api/songbooks', ['name' => 'X'])->assertStatus(401);
});

test('non-admins cannot create songbooks', function () {
    $musician = User::factory()->create();

    $this->actingAs($musician)
        ->postJson('/api/songbooks', ['name' => 'New'])
        ->assertStatus(403);
});

test('admins can create a songbook', function () {
    $admin = User::factory()->admin()->create();

    $response = $this->actingAs($admin)->postJson('/api/songbooks', [
        'name'        => 'Christmas 2026',
        'description' => 'Advent + Christmas',
    ]);

    $response->assertCreated()
        ->assertJsonPath('name', 'Christmas 2026')
        ->assertJsonPath('is_default', false)
        ->assertJsonPath('created_by', $admin->id);

    $this->assertDatabaseHas('songbooks', ['name' => 'Christmas 2026', 'is_default' => false]);
});

test('cannot create a songbook with a duplicate name', function () {
    $admin = User::factory()->admin()->create();
    Songbook::factory()->create(['name' => 'Taken']);

    $this->actingAs($admin)
        ->postJson('/api/songbooks', ['name' => 'Taken'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['name']);
});

test('store requires a name', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->postJson('/api/songbooks', [])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['name']);
});

// ── update ───────────────────────────────────────────────────────────

test('updating requires authentication', function () {
    $songbook = Songbook::factory()->create();

    $this->putJson("/api/songbooks/{$songbook->id}", ['name' => 'X'])
        ->assertStatus(401);
});

test('non-admins cannot update songbooks', function () {
    $musician = User::factory()->create();
    $songbook = Songbook::factory()->create();

    $this->actingAs($musician)
        ->putJson("/api/songbooks/{$songbook->id}", ['name' => 'X'])
        ->assertStatus(403);
});

test('admins can update name and description', function () {
    $admin    = User::factory()->admin()->create();
    $songbook = Songbook::factory()->create(['name' => 'Old', 'description' => 'old desc']);

    $this->actingAs($admin)
        ->putJson("/api/songbooks/{$songbook->id}", [
            'name'        => 'New Name',
            'description' => 'new desc',
        ])
        ->assertOk()
        ->assertJsonPath('name', 'New Name')
        ->assertJsonPath('description', 'new desc');
});

test('updating to a duplicate name is rejected', function () {
    $admin    = User::factory()->admin()->create();
    Songbook::factory()->create(['name' => 'Existing']);
    $target = Songbook::factory()->create(['name' => 'Target']);

    $this->actingAs($admin)
        ->putJson("/api/songbooks/{$target->id}", ['name' => 'Existing'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['name']);
});

test('updating with the same name is allowed (unique-rule ignores self)', function () {
    $admin    = User::factory()->admin()->create();
    $songbook = Songbook::factory()->create(['name' => 'Same']);

    $this->actingAs($admin)
        ->putJson("/api/songbooks/{$songbook->id}", ['name' => 'Same', 'description' => 'changed'])
        ->assertOk();
});

test('update returns 404 for a missing songbook', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->putJson('/api/songbooks/00000000-0000-0000-0000-000000000000', ['name' => 'X'])
        ->assertStatus(404);
});

// ── destroy ──────────────────────────────────────────────────────────

test('deleting requires authentication', function () {
    $songbook = Songbook::factory()->create();

    $this->deleteJson("/api/songbooks/{$songbook->id}")->assertStatus(401);
});

test('non-admins cannot delete songbooks', function () {
    $musician = User::factory()->create();
    $songbook = Songbook::factory()->create();

    $this->actingAs($musician)
        ->deleteJson("/api/songbooks/{$songbook->id}")
        ->assertStatus(403);
});

test('admins can delete an empty non-default songbook', function () {
    $admin    = User::factory()->admin()->create();
    $songbook = Songbook::factory()->create();

    $this->actingAs($admin)
        ->deleteJson("/api/songbooks/{$songbook->id}")
        ->assertStatus(204);

    $this->assertDatabaseMissing('songbooks', ['id' => $songbook->id]);
});

test('the default songbook cannot be deleted', function () {
    $admin   = User::factory()->admin()->create();
    $default = Songbook::factory()->default()->create();

    $this->actingAs($admin)
        ->deleteJson("/api/songbooks/{$default->id}")
        ->assertStatus(409);
});

test('a songbook with songs cannot be deleted', function () {
    $admin    = User::factory()->admin()->create();
    $songbook = Songbook::factory()->create();
    Song::factory()->create(['songbook_id' => $songbook->id]);

    $this->actingAs($admin)
        ->deleteJson("/api/songbooks/{$songbook->id}")
        ->assertStatus(409);

    $this->assertDatabaseHas('songbooks', ['id' => $songbook->id]);
});

test('delete returns 404 for a missing songbook', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->deleteJson('/api/songbooks/00000000-0000-0000-0000-000000000000')
        ->assertStatus(404);
});

<?php

use App\Models\User;

/*
 * Feature tests for UserController — index, update, destroy.
 *
 * Authorization: all three endpoints require the `admin` role
 * (services/auth/routes/api.php).
 */

test('non-admins cannot list users', function () {
    $musician = User::factory()->create(); // default role: musician

    $this->actingAs($musician)->getJson('/api/users')->assertStatus(403);
});

test('destroy soft-deletes a user', function () {
    $admin = User::factory()->admin()->create();
    $target = User::factory()->create();

    $this->actingAs($admin)->deleteJson("/api/users/{$target->id}")
        ->assertStatus(204);

    $this->assertSoftDeleted('users', ['id' => $target->id]);
});

test('index excludes soft-deleted users', function () {
    $admin = User::factory()->admin()->create();
    $target = User::factory()->create();
    $target->delete();

    $response = $this->actingAs($admin)->getJson('/api/users');

    $response->assertOk();
    expect(collect($response->json())->pluck('id'))->not->toContain($target->id);
});

test('admin cannot delete their own account', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)->deleteJson("/api/users/{$admin->id}")
        ->assertStatus(409);

    $this->assertDatabaseHas('users', ['id' => $admin->id, 'deleted_at' => null]);
});

test('admin cannot strip their own admin role', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)->putJson("/api/users/{$admin->id}", ['roles' => ['musician']])
        ->assertStatus(409);
});

test('admin can update another user\'s roles', function () {
    $admin = User::factory()->admin()->create();
    $target = User::factory()->create();

    $this->actingAs($admin)->putJson("/api/users/{$target->id}", ['roles' => ['admin', 'musician']])
        ->assertOk()
        ->assertJsonPath('roles', ['admin', 'musician']);
});

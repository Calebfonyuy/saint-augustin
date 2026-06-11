<?php

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

/*
 * Feature tests for the AuthController — login, logout, refresh.
 *
 * The controller issues opaque Sanctum tokens, so we lean on Sanctum's
 * `personal_access_tokens` table (assertDatabaseCount + actingAs).
 */

// ── login ────────────────────────────────────────────────────────────

test('login issues a Bearer token for valid credentials', function () {
    $user = User::factory()->create([
        'email'    => 'jane@example.com',
        'password' => Hash::make('Secret99!'),
    ]);

    $response = $this->postJson('/api/auth/login', [
        'email'    => 'jane@example.com',
        'password' => 'Secret99!',
    ]);

    $response->assertOk()
        ->assertJsonPath('token_type', 'Bearer')
        ->assertJsonPath('user.id', $user->id)
        ->assertJsonPath('user.email', 'jane@example.com')
        ->assertJsonStructure(['access_token', 'user' => ['id', 'email', 'display_name', 'roles']]);

    expect($response->json('access_token'))->toBeString()->not->toBeEmpty();
    $this->assertDatabaseCount('personal_access_tokens', 1);
});

test('login rejects unknown email with 401', function () {
    $this->postJson('/api/auth/login', [
        'email'    => 'nobody@example.com',
        'password' => 'whatever',
    ])->assertStatus(401)
      ->assertJsonPath('message', 'Invalid credentials.');
});

test('login rejects wrong password with 401', function () {
    User::factory()->create([
        'email'    => 'jane@example.com',
        'password' => Hash::make('Secret99!'),
    ]);

    $this->postJson('/api/auth/login', [
        'email'    => 'jane@example.com',
        'password' => 'wrong-password',
    ])->assertStatus(401);
});

test('login validates email format', function () {
    $this->postJson('/api/auth/login', [
        'email'    => 'not-an-email',
        'password' => 'whatever',
    ])->assertStatus(422)
      ->assertJsonValidationErrors(['email']);
});

test('login requires email and password', function () {
    $this->postJson('/api/auth/login', [])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['email', 'password']);
});

// ── logout ───────────────────────────────────────────────────────────

test('logout requires authentication', function () {
    $this->postJson('/api/auth/logout')->assertStatus(401);
});

test('logout revokes the current token', function () {
    $user  = User::factory()->create();
    $token = $user->createToken('api-token')->plainTextToken;

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/auth/logout');

    $response->assertOk()->assertJsonPath('message', 'Logged out successfully.');
    $this->assertDatabaseCount('personal_access_tokens', 0);
});

test('a revoked token cannot be reused', function () {
    $user  = User::factory()->create();
    $token = $user->createToken('api-token')->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$token}")->postJson('/api/auth/logout');

    // Re-using the same token should now 401. The auth guard caches the
    // resolved user within the TestCase instance, so we forget guards before
    // hitting the API again — otherwise the cached user would be served from
    // memory instead of being re-resolved from the (now-deleted) token row.
    Auth::forgetGuards();

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/auth/logout')
        ->assertStatus(401);
});

// ── refresh ──────────────────────────────────────────────────────────

test('refresh requires authentication', function () {
    $this->postJson('/api/auth/refresh')->assertStatus(401);
});

test('refresh rotates the token — old token is revoked, new one works', function () {
    $user     = User::factory()->create();
    $oldToken = $user->createToken('api-token')->plainTextToken;

    $response = $this->withHeader('Authorization', "Bearer {$oldToken}")
        ->postJson('/api/auth/refresh');

    $response->assertOk()
        ->assertJsonStructure(['access_token', 'user' => ['id', 'email', 'roles']]);

    $newToken = $response->json('access_token');
    expect($newToken)->toBeString()->not->toBe($oldToken);

    // Old token row should be gone from the DB; a fresh token row replaces it.
    $this->assertDatabaseCount('personal_access_tokens', 1);
    $this->assertDatabaseHas('personal_access_tokens', ['name' => 'api-token']);

    // Forget guards so the auth manager re-resolves the user from the DB
    // instead of returning the in-memory user from the previous request.
    Auth::forgetGuards();

    // Old token is dead.
    $this->withHeader('Authorization', "Bearer {$oldToken}")
        ->postJson('/api/auth/refresh')
        ->assertStatus(401);

    Auth::forgetGuards();

    // New token works.
    $this->withHeader('Authorization', "Bearer {$newToken}")
        ->postJson('/api/auth/logout')
        ->assertOk();
});

// ── GET /auth/me (token introspection) ───────────────────────────────

test('me requires authentication', function () {
    $this->getJson('/api/auth/me')->assertStatus(401);
});

test('me returns the authenticated user', function () {
    $user  = User::factory()->create([
        'email'        => 'leader@example.com',
        'display_name' => 'Worship Leader',
        'roles'        => ['admin', 'musician'],
    ]);
    $token = $user->createToken('api-token')->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/auth/me')
        ->assertOk()
        ->assertJsonPath('user.id', $user->id)
        ->assertJsonPath('user.email', 'leader@example.com')
        ->assertJsonPath('user.display_name', 'Worship Leader')
        ->assertJsonPath('user.roles', ['admin', 'musician']);
});

// ── PATCH /auth/me (self-service profile) ────────────────────────────

test('updateProfile requires authentication', function () {
    $this->patchJson('/api/auth/me', ['display_name' => 'Anyone'])
        ->assertStatus(401);
});

test('updateProfile updates the display_name', function () {
    $user  = User::factory()->create(['display_name' => 'Old Name']);
    $token = $user->createToken('api-token')->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$token}")
        ->patchJson('/api/auth/me', ['display_name' => 'New Name'])
        ->assertOk()
        ->assertJsonPath('user.id', $user->id)
        ->assertJsonPath('user.display_name', 'New Name');

    expect($user->fresh()->display_name)->toBe('New Name');
});

test('updateProfile rejects an empty body silently — nothing changes', function () {
    $user  = User::factory()->create(['display_name' => 'Untouched']);
    $token = $user->createToken('api-token')->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$token}")
        ->patchJson('/api/auth/me', [])
        ->assertOk()
        ->assertJsonPath('user.display_name', 'Untouched');
});

test('updateProfile changes the password when current_password matches', function () {
    $user = User::factory()->create([
        'password' => Hash::make('OldSecret99!'),
    ]);
    $token = $user->createToken('api-token')->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$token}")
        ->patchJson('/api/auth/me', [
            'current_password'      => 'OldSecret99!',
            'password'              => 'NewSecret88!',
            'password_confirmation' => 'NewSecret88!',
        ])
        ->assertOk();

    expect(Hash::check('NewSecret88!', $user->fresh()->password))->toBeTrue();
});

test('updateProfile rejects password change when current_password is wrong', function () {
    $user = User::factory()->create([
        'password' => Hash::make('OldSecret99!'),
    ]);
    $token = $user->createToken('api-token')->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$token}")
        ->patchJson('/api/auth/me', [
            'current_password'      => 'WrongPassword',
            'password'              => 'NewSecret88!',
            'password_confirmation' => 'NewSecret88!',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['current_password']);

    expect(Hash::check('OldSecret99!', $user->fresh()->password))->toBeTrue();
});

test('updateProfile requires current_password when password is provided', function () {
    $user  = User::factory()->create();
    $token = $user->createToken('api-token')->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$token}")
        ->patchJson('/api/auth/me', [
            'password'              => 'NewSecret88!',
            'password_confirmation' => 'NewSecret88!',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['current_password']);
});

test('updateProfile rejects weak passwords', function () {
    $user  = User::factory()->create(['password' => Hash::make('OldSecret99!')]);
    $token = $user->createToken('api-token')->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$token}")
        ->patchJson('/api/auth/me', [
            'current_password'      => 'OldSecret99!',
            'password'              => 'short',
            'password_confirmation' => 'short',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['password']);
});

test('updateProfile requires confirmation to match', function () {
    $user  = User::factory()->create(['password' => Hash::make('OldSecret99!')]);
    $token = $user->createToken('api-token')->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$token}")
        ->patchJson('/api/auth/me', [
            'current_password'      => 'OldSecret99!',
            'password'              => 'NewSecret88!',
            'password_confirmation' => 'Mismatch99!',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['password']);
});

test('updateProfile revokes other tokens but keeps the current one after password change', function () {
    $user = User::factory()->create(['password' => Hash::make('OldSecret99!')]);

    // Two stale tokens (e.g. other devices) plus the one we'll authenticate with.
    $user->createToken('phone')->plainTextToken;
    $user->createToken('tablet')->plainTextToken;
    $current = $user->createToken('api-token')->plainTextToken;

    $this->assertDatabaseCount('personal_access_tokens', 3);

    $this->withHeader('Authorization', "Bearer {$current}")
        ->patchJson('/api/auth/me', [
            'current_password'      => 'OldSecret99!',
            'password'              => 'NewSecret88!',
            'password_confirmation' => 'NewSecret88!',
        ])->assertOk();

    // Only the current token should remain.
    $this->assertDatabaseCount('personal_access_tokens', 1);
    $this->assertDatabaseHas('personal_access_tokens', ['name' => 'api-token']);

    // The current token still works after the change.
    Auth::forgetGuards();
    $this->withHeader('Authorization', "Bearer {$current}")
        ->postJson('/api/auth/logout')
        ->assertOk();
});

test('updateProfile does not touch tokens when only display_name changes', function () {
    $user = User::factory()->create();
    $user->createToken('phone')->plainTextToken;
    $current = $user->createToken('api-token')->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$current}")
        ->patchJson('/api/auth/me', ['display_name' => 'Renamed'])
        ->assertOk();

    $this->assertDatabaseCount('personal_access_tokens', 2);
});

<?php

use App\Models\User;
use App\Notifications\ResetPasswordNotification;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;

/*
 * Feature tests for PasswordResetController — forgot / reset.
 *
 * The reset link/token is generated through Laravel's Password broker.
 * We capture the real token via the notification spy so we can exercise
 * the reset endpoint with a valid input (rather than mocking the broker).
 */

beforeEach(function () {
    Notification::fake();
});

// ── forgot ───────────────────────────────────────────────────────────

test('forgot sends a reset notification for a known email', function () {
    $user = User::factory()->create(['email' => 'jane@example.com']);

    $response = $this->postJson('/api/auth/password/forgot', [
        'email' => 'jane@example.com',
    ]);

    $response->assertOk();
    Notification::assertSentTo($user, ResetPasswordNotification::class);
});

test('forgot returns the same generic message for an unknown email', function () {
    $response = $this->postJson('/api/auth/password/forgot', [
        'email' => 'nobody@example.com',
    ]);

    // 200 with generic message — never reveal whether the address is registered.
    $response->assertOk()
        ->assertJsonStructure(['message']);
    Notification::assertNothingSent();
});

test('forgot validates email format', function () {
    $this->postJson('/api/auth/password/forgot', ['email' => 'not-an-email'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['email']);
});

// ── reset ────────────────────────────────────────────────────────────

test('reset updates the password and revokes existing tokens', function () {
    $user = User::factory()->create([
        'email'    => 'jane@example.com',
        'password' => Hash::make('OldPass99!'),
    ]);

    // Issue a token that should be revoked when the password resets.
    $user->createToken('api-token');

    // Generate a real broker token via the same path the controller uses.
    $token = Password::broker()->createToken($user);

    $response = $this->postJson('/api/auth/password/reset', [
        'email'                 => 'jane@example.com',
        'token'                 => $token,
        'password'              => 'NewPass99!',
        'password_confirmation' => 'NewPass99!',
    ]);

    $response->assertOk();

    // Password is updated.
    expect(Hash::check('NewPass99!', $user->fresh()->password))->toBeTrue();
    // Old API tokens are nuked.
    $this->assertDatabaseCount('personal_access_tokens', 0);
});

test('reset rejects an invalid token with 422', function () {
    User::factory()->create(['email' => 'jane@example.com']);

    $this->postJson('/api/auth/password/reset', [
        'email'                 => 'jane@example.com',
        'token'                 => 'this-is-not-a-real-token',
        'password'              => 'NewPass99!',
        'password_confirmation' => 'NewPass99!',
    ])->assertStatus(422)
      ->assertJsonValidationErrors(['token']);
});

test('reset rejects mismatched password confirmation', function () {
    $user  = User::factory()->create(['email' => 'jane@example.com']);
    $token = Password::broker()->createToken($user);

    $this->postJson('/api/auth/password/reset', [
        'email'                 => 'jane@example.com',
        'token'                 => $token,
        'password'              => 'NewPass99!',
        'password_confirmation' => 'OtherPass99!',
    ])->assertStatus(422)
      ->assertJsonValidationErrors(['password']);
});

test('reset enforces password complexity', function () {
    $user  = User::factory()->create(['email' => 'jane@example.com']);
    $token = Password::broker()->createToken($user);

    $this->postJson('/api/auth/password/reset', [
        'email'                 => 'jane@example.com',
        'token'                 => $token,
        'password'              => 'weak',
        'password_confirmation' => 'weak',
    ])->assertStatus(422)
      ->assertJsonValidationErrors(['password']);
});

test('reset requires email, token, and password', function () {
    $this->postJson('/api/auth/password/reset', [])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['email', 'token', 'password']);
});

<?php

use App\Models\Invitation;
use App\Models\User;
use App\Notifications\InvitationNotification;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;

/*
 * Feature tests for the InvitationController — store / verify / register.
 *
 * Notification::fake() so we don't actually send mail; we still assert that
 * a notification *would have been* sent to the right address.
 */

beforeEach(function () {
    Notification::fake();
});

// ── store (admin creates an invite) ──────────────────────────────────

test('creating an invitation requires authentication', function () {
    $this->postJson('/api/auth/invitations', [
        'email' => 'new@example.com',
    ])->assertStatus(401);
});

test('non-admins cannot create invitations', function () {
    $musician = User::factory()->create(); // default role: musician

    $this->actingAs($musician)
        ->postJson('/api/auth/invitations', ['email' => 'new@example.com'])
        ->assertStatus(403);
});

test('admin can create an invitation and it is emailed', function () {
    $admin = User::factory()->admin()->create();

    $response = $this->actingAs($admin)
        ->postJson('/api/auth/invitations', [
            'email' => 'NEW@Example.com', // intentionally mixed-case
            'roles' => ['musician', 'projectionist'],
        ]);

    $response->assertCreated()
        ->assertJsonPath('invitation.email', 'new@example.com') // lowercased
        ->assertJsonPath('invitation.roles', ['musician', 'projectionist']);

    $invitation = Invitation::where('email', 'new@example.com')->firstOrFail();
    expect($invitation->invited_by)->toBe($admin->id);
    expect($invitation->expires_at->isFuture())->toBeTrue();

    Notification::assertSentOnDemand(InvitationNotification::class);
});

test('creating an invitation defaults roles to musician when omitted', function () {
    $admin = User::factory()->admin()->create();

    $response = $this->actingAs($admin)
        ->postJson('/api/auth/invitations', ['email' => 'plain@example.com']);

    $response->assertCreated()
        ->assertJsonPath('invitation.roles', ['musician']);
});

test('cannot invite an email that is already a registered user', function () {
    $admin = User::factory()->admin()->create();
    User::factory()->create(['email' => 'taken@example.com']);

    $this->actingAs($admin)
        ->postJson('/api/auth/invitations', ['email' => 'taken@example.com'])
        ->assertStatus(409);
});

test('cannot create a second pending invitation for the same email', function () {
    $admin = User::factory()->admin()->create();
    Invitation::factory()->create(['email' => 'pending@example.com']);

    $this->actingAs($admin)
        ->postJson('/api/auth/invitations', ['email' => 'pending@example.com'])
        ->assertStatus(409);
});

test('a stale (expired) invitation is replaced when re-invited', function () {
    $admin = User::factory()->admin()->create();
    $stale = Invitation::factory()->expired()->create(['email' => 'stale@example.com']);

    $this->actingAs($admin)
        ->postJson('/api/auth/invitations', ['email' => 'stale@example.com'])
        ->assertCreated();

    expect(Invitation::find($stale->id))->toBeNull();
    expect(Invitation::where('email', 'stale@example.com')->count())->toBe(1);
});

test('invitation rejects invalid roles', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->postJson('/api/auth/invitations', [
            'email' => 'x@example.com',
            'roles' => ['superuser'],
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['roles.0']);
});

// ── verify (public token check) ──────────────────────────────────────

test('verify returns the invitation for a valid token', function () {
    $invitation = Invitation::factory()->create(['email' => 'a@b.test']);

    $this->getJson("/api/auth/invitations/{$invitation->token}")
        ->assertOk()
        ->assertJsonPath('email', 'a@b.test')
        ->assertJsonPath('accepted', false);
});

test('verify returns 404 for unknown token', function () {
    $this->getJson('/api/auth/invitations/no-such-token')->assertStatus(404);
});

test('verify returns 410 for an expired token', function () {
    $invitation = Invitation::factory()->expired()->create();

    $this->getJson("/api/auth/invitations/{$invitation->token}")->assertStatus(410);
});

test('verify returns 410 for an already-accepted token', function () {
    $invitation = Invitation::factory()->accepted()->create();

    $this->getJson("/api/auth/invitations/{$invitation->token}")->assertStatus(410);
});

// ── register (consume token, create user) ────────────────────────────

test('register creates an account and marks the invitation accepted', function () {
    $invitation = Invitation::factory()->create([
        'email' => 'newuser@example.com',
        'roles' => ['admin', 'musician'],
    ]);

    $response = $this->postJson('/api/auth/register', [
        'token'                 => $invitation->token,
        'display_name'          => 'New User',
        'password'              => 'Secret99!',
        'password_confirmation' => 'Secret99!',
    ]);

    $response->assertCreated();

    $user = User::where('email', 'newuser@example.com')->firstOrFail();
    expect($user->roles)->toBe(['admin', 'musician']);
    expect(Hash::check('Secret99!', $user->password))->toBeTrue();
    expect($invitation->fresh()->accepted_at)->not->toBeNull();
});

test('register rejects an invalid token with 410', function () {
    $this->postJson('/api/auth/register', [
        'token'                 => 'bogus-token',
        'display_name'          => 'New User',
        'password'              => 'Secret99!',
        'password_confirmation' => 'Secret99!',
    ])->assertStatus(410);
});

test('register rejects an expired token with 410', function () {
    $invitation = Invitation::factory()->expired()->create();

    $this->postJson('/api/auth/register', [
        'token'                 => $invitation->token,
        'display_name'          => 'New User',
        'password'              => 'Secret99!',
        'password_confirmation' => 'Secret99!',
    ])->assertStatus(410);
});

test('register rejects an already-accepted token with 410', function () {
    $invitation = Invitation::factory()->accepted()->create();

    $this->postJson('/api/auth/register', [
        'token'                 => $invitation->token,
        'display_name'          => 'New User',
        'password'              => 'Secret99!',
        'password_confirmation' => 'Secret99!',
    ])->assertStatus(410);
});

test('register enforces password complexity rules', function () {
    $invitation = Invitation::factory()->create();

    // Too short / no mixed case / no numbers — all fail the same rule.
    $this->postJson('/api/auth/register', [
        'token'                 => $invitation->token,
        'display_name'          => 'New User',
        'password'              => 'short',
        'password_confirmation' => 'short',
    ])->assertStatus(422)
      ->assertJsonValidationErrors(['password']);
});

test('register requires password confirmation to match', function () {
    $invitation = Invitation::factory()->create();

    $this->postJson('/api/auth/register', [
        'token'                 => $invitation->token,
        'display_name'          => 'New User',
        'password'              => 'Secret99!',
        'password_confirmation' => 'Different99!',
    ])->assertStatus(422)
      ->assertJsonValidationErrors(['password']);
});

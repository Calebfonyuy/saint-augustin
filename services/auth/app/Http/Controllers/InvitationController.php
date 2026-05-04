<?php

namespace App\Http\Controllers;

use App\Models\Invitation;
use App\Models\User;
use App\Notifications\InvitationNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password as PasswordRule;
use OpenApi\Attributes as OA;

/**
 * Manages admin-issued invitations and the invitation-based registration flow.
 *
 * Flow:
 *   1. Admin   → POST /api/auth/invitations         create & email invite
 *   2. Frontend → GET  /api/auth/invitations/{token} verify token before showing form
 *   3. Invitee → POST /api/auth/register             accept invite, create account
 */
#[OA\Tag(
    name: 'Invitations',
    description: 'Admin-issued invitation management and invitation-based user registration.',
)]
class InvitationController
{
    private const VALID_ROLES = ['admin', 'musician', 'projectionist'];

    // ── Admin: list invitations ───────────────────────────────────────

    #[OA\Get(
        path: '/auth/invitations',
        summary: 'List invitations (Admin)',
        description: 'Returns all invitations — pending, accepted, and expired — newest first. Used by the Admin → Users screen to merge pending invites into the same list as registered users. Requires the `admin` role.',
        tags: ['Invitations'],
        security: [['sanctum' => []]],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Array of invitations',
                content: new OA\JsonContent(
                    type: 'array',
                    items: new OA\Items(ref: '#/components/schemas/InvitationResource'),
                ),
            ),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'Admin role required', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function index(): JsonResponse
    {
        $invitations = Invitation::orderByDesc('created_at')
            ->get()
            ->map(fn (Invitation $i) => $this->formatInvitation($i));

        return response()->json($invitations);
    }

    // ── Admin: create invitation ──────────────────────────────────────

    #[OA\Post(
        path: '/auth/invitations',
        summary: 'Create and send an invitation (Admin)',
        description: 'Generates a one-time invitation token and emails a registration link to the specified address. Requires the `admin` role. Returns 409 if a pending invitation already exists for that email, or if the email is already registered.',
        tags: ['Invitations'],
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['email'],
                properties: [
                    new OA\Property(property: 'email', type: 'string', format: 'email', example: 'newuser@example.com'),
                    new OA\Property(
                        property: 'roles',
                        type: 'array',
                        items: new OA\Items(type: 'string', enum: ['admin', 'musician', 'projectionist']),
                        example: ['musician'],
                        description: 'Roles pre-assigned to the user on registration. Defaults to [musician].',
                    ),
                ],
            ),
        ),
        responses: [
            new OA\Response(
                response: 201,
                description: 'Invitation created and sent',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'message', type: 'string', example: 'Invitation sent to newuser@example.com.'),
                        new OA\Property(property: 'invitation', ref: '#/components/schemas/InvitationResource'),
                    ],
                    type: 'object',
                ),
            ),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'Admin role required', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 409, description: 'Email already registered or already has a pending invitation', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ValidationErrorResponse')),
        ],
    )]
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
            'roles' => ['sometimes', 'array', 'min:1'],
            'roles.*' => [Rule::in(self::VALID_ROLES)],
        ]);

        $email = strtolower(trim($validated['email']));
        $roles = $validated['roles'] ?? ['musician'];

        // Guard: email already belongs to an active user
        if (User::where('email', $email)->exists()) {
            return response()->json([
                'message' => 'A user with that email address is already registered.',
            ], 409);
        }

        // Guard: a pending (non-expired, non-accepted) invitation already exists
        $existing = Invitation::where('email', $email)->first();

        if ($existing) {
            if (! $existing->isExpired() && ! $existing->isAccepted()) {
                return response()->json([
                    'message' => 'A pending invitation for that email already exists.',
                ], 409);
            }

            // Stale invitation (expired or accepted) — replace it
            $existing->delete();
        }

        $expireHours = (int) config('invitation.expire_hours', 48);

        $invitation = Invitation::create([
            'email'      => $email,
            'token'      => Str::random(64),
            'roles'      => $roles,
            'invited_by' => $request->user()->id,
            'expires_at' => now()->addHours($expireHours),
        ]);

        // Send via anonymous notifiable so we don't need a User model instance
        (new AnonymousNotifiable)
            ->route('mail', $email)
            ->notify(new InvitationNotification(
                token: $invitation->token,
                inviterName: $request->user()->display_name,
                expireHours: $expireHours,
            ));

        return response()->json([
            'message'    => "Invitation sent to {$email}.",
            'invitation' => $this->formatInvitation($invitation),
        ], 201);
    }

    // ── Public: verify a token before showing the registration form ───

    #[OA\Get(
        path: '/auth/invitations/{token}',
        summary: 'Verify an invitation token',
        description: 'Returns the invitation details (email, roles, expiry) for a valid, unused token. The frontend calls this on page load to pre-fill the registration form and surface a clear error if the token is invalid or expired.',
        tags: ['Invitations'],
        parameters: [
            new OA\Parameter(name: 'token', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Token is valid',
                content: new OA\JsonContent(ref: '#/components/schemas/InvitationResource'),
            ),
            new OA\Response(response: 404, description: 'Token not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 410, description: 'Token expired or already accepted', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function verify(string $token): JsonResponse
    {
        $invitation = Invitation::where('token', $token)->first();

        if (! $invitation) {
            return response()->json(['message' => 'Invitation not found.'], 404);
        }

        if ($invitation->isAccepted()) {
            return response()->json(['message' => 'This invitation has already been accepted.'], 410);
        }

        if ($invitation->isExpired()) {
            return response()->json(['message' => 'This invitation has expired.'], 410);
        }

        return response()->json($this->formatInvitation($invitation));
    }

    // ── Public: accept invitation and create account ──────────────────

    #[OA\Post(
        path: '/auth/register',
        summary: 'Register via invitation token',
        description: 'Creates a new user account by consuming a valid invitation token. The email and roles are taken from the invitation — they cannot be overridden by the caller. The token is invalidated after use.',
        tags: ['Invitations'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['token', 'display_name', 'password', 'password_confirmation'],
                properties: [
                    new OA\Property(property: 'token', type: 'string', example: 'abc123...'),
                    new OA\Property(property: 'display_name', type: 'string', example: 'Jean Dupont'),
                    new OA\Property(property: 'password', type: 'string', format: 'password', minLength: 8, example: 'Secret99!'),
                    new OA\Property(property: 'password_confirmation', type: 'string', format: 'password', example: 'Secret99!'),
                ],
            ),
        ),
        responses: [
            new OA\Response(
                response: 201,
                description: 'Account created — user must log in to obtain a token',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'message', type: 'string', example: 'Account created successfully. You can now log in.'),
                    ],
                    type: 'object',
                ),
            ),
            new OA\Response(response: 410, description: 'Token expired or already used', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ValidationErrorResponse')),
        ],
    )]
    public function register(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'token'        => ['required', 'string'],
            'display_name' => ['required', 'string', 'max:255'],
            'password'     => ['required', 'confirmed', PasswordRule::min(8)->mixedCase()->numbers()],
        ]);

        $invitation = Invitation::where('token', $validated['token'])->first();

        if (! $invitation || $invitation->isAccepted() || $invitation->isExpired()) {
            return response()->json([
                'message' => 'This invitation is invalid, expired, or has already been used.',
            ], 410);
        }

        User::create([
            'email'        => $invitation->email,
            'display_name' => $validated['display_name'],
            'password'     => Hash::make($validated['password']),
            'roles'        => $invitation->roles,
        ]);

        $invitation->update(['accepted_at' => now()]);

        return response()->json([
            'message' => 'Account created successfully. You can now log in.',
        ], 201);
    }

    // ── Admin: cancel a pending invitation ────────────────────────────

    #[OA\Delete(
        path: '/auth/invitations/{id}',
        summary: 'Cancel a pending invitation (Admin)',
        description: 'Deletes an invitation, invalidating its token immediately. Requires the `admin` role. Cancelling an already-accepted invitation is allowed (it just removes the audit row); the user account itself is unaffected.',
        tags: ['Invitations'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 204, description: 'Invitation deleted'),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'Admin role required', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 404, description: 'Invitation not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function destroy(string $id): JsonResponse
    {
        $invitation = Invitation::find($id);

        if (! $invitation) {
            return response()->json(['message' => 'Invitation not found.'], 404);
        }

        $invitation->delete();

        return response()->json(null, 204);
    }

    // ── Admin: resend a pending invitation ────────────────────────────

    #[OA\Post(
        path: '/auth/invitations/{id}/resend',
        summary: 'Resend a pending invitation (Admin)',
        description: 'Re-emails the invitation link and refreshes the expiry. Requires the `admin` role. Returns 410 if the invitation has already been accepted (the recipient already has an account).',
        tags: ['Invitations'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Invitation resent',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'message', type: 'string', example: 'Invitation re-sent.'),
                        new OA\Property(property: 'invitation', ref: '#/components/schemas/InvitationResource'),
                    ],
                    type: 'object',
                ),
            ),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'Admin role required', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 404, description: 'Invitation not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 410, description: 'Invitation already accepted', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function resend(Request $request, string $id): JsonResponse
    {
        $invitation = Invitation::find($id);

        if (! $invitation) {
            return response()->json(['message' => 'Invitation not found.'], 404);
        }

        if ($invitation->isAccepted()) {
            return response()->json([
                'message' => 'This invitation has already been accepted.',
            ], 410);
        }

        $expireHours = (int) config('invitation.expire_hours', 48);

        // Rotate the token on resend so any prior copies of the email become
        // useless — protects against an admin clicking "resend" specifically
        // because the previous link leaked.
        $invitation->update([
            'token'      => Str::random(64),
            'expires_at' => now()->addHours($expireHours),
        ]);

        (new AnonymousNotifiable)
            ->route('mail', $invitation->email)
            ->notify(new InvitationNotification(
                token: $invitation->token,
                inviterName: $request->user()->display_name,
                expireHours: $expireHours,
            ));

        return response()->json([
            'message'    => "Invitation re-sent to {$invitation->email}.",
            'invitation' => $this->formatInvitation($invitation),
        ]);
    }

    // ── Private helpers ───────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function formatInvitation(Invitation $invitation): array
    {
        return [
            'id'         => $invitation->id,
            'email'      => $invitation->email,
            'roles'      => $invitation->roles,
            'invited_by' => $invitation->invited_by,
            'expires_at' => $invitation->expires_at->toIso8601String(),
            'accepted'   => $invitation->isAccepted(),
        ];
    }
}

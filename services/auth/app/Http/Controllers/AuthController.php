<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;
use OpenApi\Attributes as OA;

/**
 * Handles stateless token-based authentication via Laravel Sanctum.
 *
 * Tokens are opaque API tokens stored in the personal_access_tokens table.
 * Clients send them as: Authorization: Bearer <token>
 *
 * Ref: https://laravel.com/docs/12.x/sanctum#api-token-authentication
 */
#[OA\Tag(
    name: 'Authentication',
    description: 'Login, logout, and token refresh endpoints.',
)]
class AuthController
{
    /**
     * Authenticate a user and issue a Sanctum API token.
     *
     * POST /api/auth/login
     */
    #[OA\Post(
        path: '/auth/login',
        summary: 'Log in and obtain a Bearer token',
        description: 'Validates email and password, then returns a Sanctum API token. Include the token in subsequent requests as `Authorization: Bearer <token>`.',
        tags: ['Authentication'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['email', 'password'],
                properties: [
                    new OA\Property(property: 'email', type: 'string', format: 'email', example: 'admin@saintaugustin.local'),
                    new OA\Property(property: 'password', type: 'string', format: 'password', example: 'password'),
                ],
            ),
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Authenticated — token issued',
                content: new OA\JsonContent(ref: '#/components/schemas/TokenResponse'),
            ),
            new OA\Response(
                response: 401,
                description: 'Invalid credentials',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse'),
            ),
            new OA\Response(
                response: 422,
                description: 'Validation error',
                content: new OA\JsonContent(ref: '#/components/schemas/ValidationErrorResponse'),
            ),
        ],
    )]
    public function login(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email'    => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::where('email', $validated['email'])->first();

        if (! $user || ! Hash::check($validated['password'], $user->password)) {
            return response()->json([
                'message' => 'Invalid credentials.',
            ], 401);
        }

        $token = $user->createToken('api-token')->plainTextToken;

        return response()->json([
            'token_type'   => 'Bearer',
            'access_token' => $token,
            'user'         => [
                'id'           => $user->id,
                'email'        => $user->email,
                'display_name' => $user->display_name,
                'roles'        => $user->roles,
            ],
        ]);
    }

    /**
     * Revoke the current token (log out).
     *
     * POST /api/auth/logout
     */
    #[OA\Post(
        path: '/auth/logout',
        summary: 'Revoke the current Bearer token',
        description: 'Deletes the token that was used to authenticate this request. The client should discard the token.',
        tags: ['Authentication'],
        security: [['sanctum' => []]],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Token revoked successfully',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'message', type: 'string', example: 'Logged out successfully.'),
                    ],
                    type: 'object',
                ),
            ),
            new OA\Response(
                response: 401,
                description: 'Unauthenticated — no valid token provided',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse'),
            ),
        ],
    )]
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'message' => 'Logged out successfully.',
        ]);
    }

    /**
     * Rotate the current token — revoke it and issue a fresh one.
     *
     * POST /api/auth/refresh
     */
    #[OA\Post(
        path: '/auth/refresh',
        summary: 'Rotate the Bearer token',
        description: 'Revokes the current token and immediately issues a new one. Useful for proactive key rotation. The old token is invalidated.',
        tags: ['Authentication'],
        security: [['sanctum' => []]],
        responses: [
            new OA\Response(
                response: 200,
                description: 'New token issued',
                content: new OA\JsonContent(ref: '#/components/schemas/TokenResponse'),
            ),
            new OA\Response(
                response: 401,
                description: 'Unauthenticated — no valid token provided',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse'),
            ),
        ],
    )]
    public function refresh(Request $request): JsonResponse
    {
        $user = $request->user();

        $user->currentAccessToken()->delete();

        $token = $user->createToken('api-token')->plainTextToken;

        return response()->json([
            'token_type'   => 'Bearer',
            'access_token' => $token,
            'user'         => [
                'id'           => $user->id,
                'email'        => $user->email,
                'display_name' => $user->display_name,
                'roles'        => $user->roles,
            ],
        ]);
    }

    /**
     * Return the authenticated user — token introspection.
     *
     * Other microservices (currently the NestJS projection service) hit this
     * endpoint to resolve a Bearer token to a user identity and role set
     * before authorising session ownership / admin actions.
     *
     * GET /api/auth/me
     */
    public function me(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'user' => [
                'id'           => $user->id,
                'email'        => $user->email,
                'display_name' => $user->display_name,
                'roles'        => $user->roles,
            ],
        ]);
    }

    /**
     * Update the authenticated user's own profile.
     *
     * Lets a signed-in user change their display name and/or password without
     * needing an admin (FR3 — self-service profile editing). Email and roles
     * are intentionally NOT settable here: email is the primary identifier
     * and role changes belong to admins via UserController::update.
     *
     * Password changes require `current_password` for re-authentication, and
     * after a successful password change every *other* Sanctum token for the
     * user is revoked so that stale sessions on other devices stop working.
     * The current token is preserved so the caller doesn't immediately get
     * 401'd on their next request.
     *
     * PATCH /api/auth/me
     */
    #[OA\Patch(
        path: '/auth/me',
        summary: 'Update your own profile',
        description: 'Authenticated self-service update of `display_name` and/or `password`. Changing the password requires `current_password` and revokes all other Sanctum tokens for the user (the current session keeps working).',
        tags: ['Authentication'],
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'display_name', type: 'string', maxLength: 255, example: 'Jane Doe'),
                    new OA\Property(property: 'current_password', type: 'string', format: 'password', description: 'Required when `password` is provided.'),
                    new OA\Property(property: 'password', type: 'string', format: 'password', minLength: 8, example: 'newSecret99!'),
                    new OA\Property(property: 'password_confirmation', type: 'string', format: 'password', example: 'newSecret99!'),
                ],
            ),
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Profile updated',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'user', ref: '#/components/schemas/UserResource'),
                    ],
                    type: 'object',
                ),
            ),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error (bad current password, weak new password, etc.)', content: new OA\JsonContent(ref: '#/components/schemas/ValidationErrorResponse')),
        ],
    )]
    public function updateProfile(Request $request): JsonResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'display_name'     => ['sometimes', 'string', 'max:255'],
            'current_password' => ['required_with:password', 'string'],
            'password'         => ['sometimes', 'confirmed', PasswordRule::min(8)->mixedCase()->numbers()],
        ]);

        $changingPassword = array_key_exists('password', $validated);

        if ($changingPassword && ! Hash::check($validated['current_password'], $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => [__('The provided password does not match your current password.')],
            ]);
        }

        if (array_key_exists('display_name', $validated)) {
            $user->display_name = $validated['display_name'];
        }

        if ($changingPassword) {
            // Use the `'hashed'` cast on the User model — assigning a plaintext
            // password gets it hashed automatically on save().
            $user->password = $validated['password'];
        }

        $user->save();

        if ($changingPassword) {
            // Revoke every *other* Sanctum token so concurrent sessions on
            // other devices are signed out, but keep the caller's current
            // token alive so they don't get 401'd on their very next request.
            $currentTokenId = $user->currentAccessToken()->id ?? null;
            $user->tokens()
                ->when($currentTokenId, fn ($q) => $q->where('id', '!=', $currentTokenId))
                ->delete();
        }

        return response()->json([
            'user' => [
                'id'           => $user->id,
                'email'        => $user->email,
                'display_name' => $user->display_name,
                'roles'        => $user->roles,
            ],
        ]);
    }
}

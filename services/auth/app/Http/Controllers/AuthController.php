<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
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
                    new OA\Property(property: 'email', type: 'string', format: 'email', example: 'musician@example.com'),
                    new OA\Property(property: 'password', type: 'string', format: 'password', example: 'secret1234'),
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
}

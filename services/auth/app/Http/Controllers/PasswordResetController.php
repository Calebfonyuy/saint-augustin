<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\Rules\Password as PasswordRule;
use OpenApi\Attributes as OA;

/**
 * Handles the two-step password reset flow:
 *   1. POST /api/auth/password/forgot  — send a reset link by email
 *   2. POST /api/auth/password/reset   — consume the token and set a new password
 *
 * Both responses are deliberately generic to prevent user enumeration.
 */
#[OA\Tag(
    name: 'Password Reset',
    description: 'Two-step email-based password reset.',
)]
class PasswordResetController
{
    /**
     * Send a password-reset link to the given email address.
     *
     * POST /api/auth/password/forgot
     */
    #[OA\Post(
        path: '/auth/password/forgot',
        summary: 'Request a password reset link',
        description: 'Sends a password reset link to the provided email address. The response is identical whether or not the email exists, to prevent user enumeration.',
        tags: ['Password Reset'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['email'],
                properties: [
                    new OA\Property(property: 'email', type: 'string', format: 'email', example: 'musician@example.com'),
                ],
            ),
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Reset link sent (or silently ignored if the email is not registered)',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'message', type: 'string', example: 'If that email is registered you will receive a reset link shortly.'),
                    ],
                    type: 'object',
                ),
            ),
            new OA\Response(
                response: 422,
                description: 'Validation error',
                content: new OA\JsonContent(ref: '#/components/schemas/ValidationErrorResponse'),
            ),
            new OA\Response(
                response: 429,
                description: 'Too many reset attempts — throttled',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse'),
            ),
        ],
    )]
    public function forgot(Request $request): JsonResponse
    {
        $request->validate([
            'email' => ['required', 'email'],
        ]);

        $status = Password::sendResetLink($request->only('email'));

        if ($status === Password::RESET_THROTTLED) {
            return response()->json([
                'message' => __('Too many password reset attempts. Please try again later.'),
            ], 429);
        }

        // Return the same message for RESET_LINK_SENT and INVALID_USER
        // to avoid leaking whether an email address is registered.
        return response()->json([
            'message' => __('If that email is registered you will receive a reset link shortly.'),
        ]);
    }

    /**
     * Validate the reset token and update the user's password.
     *
     * POST /api/auth/password/reset
     */
    #[OA\Post(
        path: '/auth/password/reset',
        summary: 'Reset password using a token',
        description: 'Consumes the one-time token from the reset email and sets a new password. The token is invalidated after use. All existing Sanctum tokens for the user are revoked.',
        tags: ['Password Reset'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['email', 'token', 'password', 'password_confirmation'],
                properties: [
                    new OA\Property(property: 'email', type: 'string', format: 'email', example: 'musician@example.com'),
                    new OA\Property(property: 'token', type: 'string', example: 'abc123def456...'),
                    new OA\Property(property: 'password', type: 'string', format: 'password', minLength: 8, example: 'newSecret99!'),
                    new OA\Property(property: 'password_confirmation', type: 'string', format: 'password', example: 'newSecret99!'),
                ],
            ),
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Password updated — user must log in again',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'message', type: 'string', example: 'Password reset successfully.'),
                    ],
                    type: 'object',
                ),
            ),
            new OA\Response(
                response: 422,
                description: 'Invalid or expired token, or validation error',
                content: new OA\JsonContent(ref: '#/components/schemas/ValidationErrorResponse'),
            ),
        ],
    )]
    public function reset(Request $request): JsonResponse
    {
        $request->validate([
            'email'    => ['required', 'email'],
            'token'    => ['required', 'string'],
            'password' => ['required', 'confirmed', PasswordRule::min(8)->mixedCase()->numbers()],
        ]);

        $status = Password::reset(
            $request->only('email', 'token', 'password', 'password_confirmation'),
            function ($user, string $password): void {
                $user->forceFill(['password' => Hash::make($password)])->save();

                // Revoke all existing API tokens so stale sessions cannot be reused.
                $user->tokens()->delete();
            },
        );

        if ($status !== Password::PASSWORD_RESET) {
            return response()->json([
                'message'=> __('validation.custom.token.invalid'),
                'errors' => ['token' => [__($status)]],
            ], 422);
        }

        return response()->json([
            'message' => __('Password reset successfully.'),
        ]);
    }
}

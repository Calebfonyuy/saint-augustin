<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use OpenApi\Attributes as OA;

/**
 * User management endpoints (admin-only) — backs the Admin → Users screen
 * (FR3 / SRS 3.2).
 *
 * The list view is intentionally non-paginated: the project is sized for
 * a single parish with at most a few dozen users, so a flat list keeps the
 * UI simple and avoids the cost of a separate pagination component for
 * something that will rarely scroll.
 */
#[OA\Tag(
    name: 'Users',
    description: 'Admin-only user management.',
)]
class UserController
{
    private const VALID_ROLES = ['admin', 'musician', 'projectionist'];

    // ── List ──────────────────────────────────────────────────────────

    #[OA\Get(
        path: '/users',
        summary: 'List all users (Admin)',
        description: 'Returns every registered user. Requires the `admin` role. Used to populate the Admin → Users table.',
        tags: ['Users'],
        security: [['sanctum' => []]],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Array of users',
                content: new OA\JsonContent(
                    type: 'array',
                    items: new OA\Items(ref: '#/components/schemas/UserResource'),
                ),
            ),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'Admin role required', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function index(): JsonResponse
    {
        $users = User::orderBy('display_name')
            ->get()
            ->map(fn (User $u) => $this->formatUser($u));

        return response()->json($users);
    }

    // ── Update ────────────────────────────────────────────────────────

    #[OA\Put(
        path: '/users/{id}',
        summary: 'Update a user (Admin)',
        description: 'Updates display name and/or roles. Email cannot be changed (it is the primary identifier). Requires the `admin` role. An admin cannot strip the `admin` role from themselves — at least one admin must always remain on the account, and self-demotion would lock the admin out of this very screen.',
        tags: ['Users'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'display_name', type: 'string', maxLength: 255),
                    new OA\Property(
                        property: 'roles',
                        type: 'array',
                        items: new OA\Items(type: 'string', enum: ['admin', 'musician', 'projectionist']),
                        minItems: 1,
                    ),
                ],
            ),
        ),
        responses: [
            new OA\Response(response: 200, description: 'User updated', content: new OA\JsonContent(ref: '#/components/schemas/UserResource')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'Admin role required', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 404, description: 'User not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 409, description: 'Cannot demote yourself from admin', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ValidationErrorResponse')),
        ],
    )]
    public function update(Request $request, string $id): JsonResponse
    {
        $user = User::find($id);

        if (! $user) {
            return response()->json(['message' => 'User not found.'], 404);
        }

        $validated = $request->validate([
            'display_name' => ['sometimes', 'string', 'max:255'],
            'roles'        => ['sometimes', 'array', 'min:1'],
            'roles.*'      => [Rule::in(self::VALID_ROLES)],
        ]);

        // Self-demotion guard: editing your own roles is fine, but you must
        // keep `admin` so you don't lock yourself out of the admin screen
        // mid-edit.
        if (
            isset($validated['roles'])
            && $request->user()->id === $user->id
            && ! in_array('admin', $validated['roles'], true)
        ) {
            return response()->json([
                'message' => 'You cannot remove your own admin role. Ask another admin to do it.',
            ], 409);
        }

        $user->fill($validated)->save();

        return response()->json($this->formatUser($user));
    }

    // ── Delete ────────────────────────────────────────────────────────

    #[OA\Delete(
        path: '/users/{id}',
        summary: 'Remove a user from the workspace (Admin)',
        description: 'Deletes a user account. Requires the `admin` role. An admin cannot delete themselves — that would lock them out of the admin screen.',
        tags: ['Users'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 204, description: 'User removed'),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'Admin role required', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 404, description: 'User not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 409, description: 'Cannot delete yourself', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function destroy(Request $request, string $id): JsonResponse
    {
        $user = User::find($id);

        if (! $user) {
            return response()->json(['message' => 'User not found.'], 404);
        }

        if ($request->user()->id === $user->id) {
            return response()->json([
                'message' => 'You cannot remove your own account. Ask another admin to do it.',
            ], 409);
        }

        $user->delete();

        return response()->json(null, 204);
    }

    // ── Private helpers ───────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function formatUser(User $user): array
    {
        return [
            'id'           => $user->id,
            'email'        => $user->email,
            'display_name' => $user->display_name,
            'roles'        => $user->roles ?? [],
            'created_at'   => $user->created_at?->toIso8601String(),
            'updated_at'   => $user->updated_at?->toIso8601String(),
        ];
    }
}

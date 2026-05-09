<?php

namespace App\Http\Controllers;

use App\Models\Songbook;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use OpenApi\Attributes as OA;

/**
 * Songbook CRUD (SRS 3.1.3, 6.1).
 *
 * Authorization (SRS 2.2 — Admin holds "full CRUD on songbooks"):
 *   - Read:   any authenticated user (needed to categorize songs)
 *   - Create/Update/Delete: Admin only (enforced via the `admin` middleware)
 *
 * Delete rules:
 *   - The default songbook cannot be deleted.
 *   - A songbook that still has songs cannot be deleted — the admin must move
 *     those songs to another songbook first. This prevents accidental bulk
 *     loss of content (the DB has a cascade as a last-resort safety net, but
 *     the application enforces "move first").
 */
#[OA\Tag(
    name: 'Songbooks',
    description: 'Songbook (song collection) management.',
)]
class SongbookController
{
    // ── List ──────────────────────────────────────────────────────────

    #[OA\Get(
        path: '/songbooks',
        summary: 'List all songbooks',
        description: 'Returns every songbook. Available to any authenticated user so songs can be grouped/filtered in the UI.',
        tags: ['Songbooks'],
        security: [['sanctum' => []]],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Array of songbooks',
                content: new OA\JsonContent(
                    type: 'array',
                    items: new OA\Items(ref: '#/components/schemas/SongbookResource'),
                ),
            ),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function index(): JsonResponse
    {
        $songbooks = Songbook::withCount('songs')
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get()
            ->map(fn (Songbook $sb) => $this->formatSongbook($sb));

        return response()->json($songbooks);
    }

    // ── Show ──────────────────────────────────────────────────────────

    #[OA\Get(
        path: '/songbooks/{id}',
        summary: 'Fetch a single songbook',
        tags: ['Songbooks'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Songbook found', content: new OA\JsonContent(ref: '#/components/schemas/SongbookResource')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 404, description: 'Songbook not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function show(string $id): JsonResponse
    {
        $songbook = Songbook::withCount('songs')->find($id);

        if (! $songbook) {
            return response()->json(['message' => 'Songbook not found.'], 404);
        }

        return response()->json($this->formatSongbook($songbook));
    }

    // ── Create ────────────────────────────────────────────────────────

    #[OA\Post(
        path: '/songbooks',
        summary: 'Create a new songbook (Admin)',
        description: 'Creates a new songbook. Requires the `admin` role. The `is_default` flag is reserved for the system-seeded default songbook and cannot be set via this endpoint.',
        tags: ['Songbooks'],
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['name'],
                properties: [
                    new OA\Property(property: 'name', type: 'string', maxLength: 255, example: 'Christmas 2026'),
                    new OA\Property(property: 'description', type: 'string', nullable: true, example: 'Songs for Advent and Christmas services.'),
                ],
            ),
        ),
        responses: [
            new OA\Response(response: 201, description: 'Songbook created', content: new OA\JsonContent(ref: '#/components/schemas/SongbookResource')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'Admin role required', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 409, description: 'Name already taken', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ValidationErrorResponse')),
        ],
    )]
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name'        => ['required', 'string', 'max:255', Rule::unique('songbooks', 'name')],
            'description' => ['nullable', 'string', 'max:255'],
        ]);

        $songbook = Songbook::create([
            ...$validated,
            'is_default' => false,
            'created_by' => $request->user()->id,
        ]);

        $songbook->loadCount('songs');

        return response()->json($this->formatSongbook($songbook), 201);
    }

    // ── Update ────────────────────────────────────────────────────────

    #[OA\Put(
        path: '/songbooks/{id}',
        summary: 'Update a songbook (Admin)',
        description: 'Updates name and/or description. Requires the `admin` role. `is_default` cannot be changed.',
        tags: ['Songbooks'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'name', type: 'string', maxLength: 255),
                    new OA\Property(property: 'description', type: 'string', nullable: true),
                ],
            ),
        ),
        responses: [
            new OA\Response(response: 200, description: 'Songbook updated', content: new OA\JsonContent(ref: '#/components/schemas/SongbookResource')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'Admin role required', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 404, description: 'Songbook not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 409, description: 'Name already taken', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ValidationErrorResponse')),
        ],
    )]
    public function update(Request $request, string $id): JsonResponse
    {
        $songbook = Songbook::find($id);

        if (! $songbook) {
            return response()->json(['message' => 'Songbook not found.'], 404);
        }

        $validated = $request->validate([
            'name'        => ['sometimes', 'string', 'max:255', Rule::unique('songbooks', 'name')->ignore($songbook->id)],
            'description' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        $songbook->fill($validated)->save();
        $songbook->loadCount('songs');

        return response()->json($this->formatSongbook($songbook));
    }

    // ── Delete ────────────────────────────────────────────────────────

    #[OA\Delete(
        path: '/songbooks/{id}',
        summary: 'Delete a songbook (Admin)',
        description: 'Deletes a songbook. Requires the `admin` role. The default songbook cannot be deleted, and a songbook that still contains songs cannot be deleted — move its songs to another songbook first.',
        tags: ['Songbooks'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 204, description: 'Songbook deleted'),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'Admin role required', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 404, description: 'Songbook not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 409, description: 'Songbook is default or still contains songs', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function destroy(string $id): JsonResponse
    {
        $songbook = Songbook::find($id);

        if (! $songbook) {
            return response()->json(['message' => 'Songbook not found.'], 404);
        }

        if ($songbook->is_default) {
            return response()->json([
                'message' => 'The default songbook cannot be deleted.',
            ], 409);
        }

        $songCount = $songbook->songs()->count();

        if ($songCount > 0) {
            return response()->json([
                'message' => "Songbook still contains {$songCount} song(s). Move them to another songbook before deleting.",
            ], 409);
        }

        $songbook->delete();

        return response()->json(null, 204);
    }

    // ── Private helpers ───────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function formatSongbook(Songbook $songbook): array
    {
        return [
            'id'          => $songbook->id,
            'name'        => $songbook->name,
            'description' => $songbook->description,
            'is_default'  => $songbook->is_default,
            'created_by'  => $songbook->created_by,
            'songs_count' => (int) ($songbook->songs_count ?? 0),
            'created_at'  => $songbook->created_at?->toIso8601String(),
            'updated_at'  => $songbook->updated_at?->toIso8601String(),
        ];
    }
}

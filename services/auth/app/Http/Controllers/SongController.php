<?php

namespace App\Http\Controllers;

use App\Models\Song;
use App\Models\Songbook;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

/**
 * Song CRUD and search (SRS 3.1 — Song Management).
 *
 * Authorization (SRS 3.1.2):
 *   - Read:   any authenticated user
 *   - Create: Admin or Musician
 *   - Update: Admin or Musician
 *   - Delete: Admin only (soft-delete; 30-day recovery per NFR-8)
 *   - Restore: Admin only
 *
 * Search uses case-insensitive LIKE matches across title, author, lyrics, and
 * tags. This is adequate for up to 10k songs (NFR-1). A Postgres tsvector
 * upgrade is noted as a future improvement but not required for Phase 1.
 */
#[OA\Tag(
    name: 'Songs',
    description: 'Song library CRUD, full-text search, and soft-delete recovery.',
)]
class SongController
{
    /**
     * Musical-key validation pattern.
     *
     * Matches notes A–G with an optional # or b and an optional trailing m
     * (minor). Examples: C, F#, Db, Am, F#m, Bbm.
     */
    private const KEY_PATTERN = '/^[A-G][#b]?m?$/';

    /** Allowed time signatures (extendable as needed). */
    private const TIME_SIGNATURES = ['2/4', '3/4', '4/4', '5/4', '6/4', '3/8', '6/8', '9/8', '12/8'];

    // ── List + search ─────────────────────────────────────────────────

    #[OA\Get(
        path: '/songs',
        summary: 'List and search songs',
        description: 'Returns a paginated list of songs. Supports full-text search across title, author, lyrics, and tags, plus filtering by songbook, key, and tag. Soft-deleted songs are excluded by default; pass `trashed=only` to list only soft-deleted songs (admin view) or `trashed=with` to include them.',
        tags: ['Songs'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'q', in: 'query', required: false, description: 'Search term (title, author, lyrics, tags)', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'songbook', in: 'query', required: false, description: 'Filter by songbook UUID', schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'key', in: 'query', required: false, description: 'Filter by original key (e.g. C, Am, F#)', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'tag', in: 'query', required: false, description: 'Filter by tag', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'trashed', in: 'query', required: false, description: 'One of: with, only', schema: new OA\Schema(type: 'string', enum: ['with', 'only'])),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 25, maximum: 100)),
            new OA\Parameter(name: 'page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 1)),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Paginated list of songs',
                content: new OA\JsonContent(ref: '#/components/schemas/SongCollection'),
            ),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function index(Request $request): JsonResponse
    {
        /**TODO: Update this implementation and possibly the song model to use postgres tsvectors */
        $validated = $request->validate([
            'q'        => ['sometimes', 'string', 'max:255'],
            'songbook' => ['sometimes', 'uuid'],
            'key'      => ['sometimes', 'string', 'regex:'.self::KEY_PATTERN],
            'tag'      => ['sometimes', 'string', 'max:64'],
            'trashed'  => ['sometimes', 'in:with,only'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page'     => ['sometimes', 'integer', 'min:1'],
        ]);

        $query = Song::query();

        match ($validated['trashed'] ?? null) {
            'with' => $query->withTrashed(),
            'only' => $query->onlyTrashed(),
            default => null,
        };

        if ($term = $validated['q'] ?? null) {
            $like = '%'.$term.'%';
            $query->where(function (Builder $q) use ($like, $term) {
                $q->where('title', 'ILIKE', $like)
                  ->orWhere('author', 'ILIKE', $like)
                  ->orWhere('lyrics', 'ILIKE', $like)
                  ->orWhereJsonContains('tags', $term);
            });
        }

        if (! empty($validated['songbook'])) {
            $query->where('songbook_id', $validated['songbook']);
        }

        if (! empty($validated['key'])) {
            $query->where('original_key', $validated['key']);
        }

        if (! empty($validated['tag'])) {
            $query->whereJsonContains('tags', $validated['tag']);
        }

        $perPage = (int) ($validated['per_page'] ?? 25);
        $paginator = $query->orderBy('title')->paginate($perPage);

        return response()->json([
            'data' => $paginator->getCollection()->map(fn (Song $s) => $this->formatSong($s))->all(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page'     => $paginator->perPage(),
                'total'        => $paginator->total(),
                'last_page'    => $paginator->lastPage(),
            ],
        ]);
    }

    // ── Show a single song ────────────────────────────────────────────

    #[OA\Get(
        path: '/songs/{id}',
        summary: 'Fetch a single song',
        tags: ['Songs'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Song found', content: new OA\JsonContent(ref: '#/components/schemas/SongResource')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 404, description: 'Song not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function show(string $id): JsonResponse
    {
        $song = Song::find($id);

        if (! $song) {
            return response()->json(['message' => 'Song not found.'], 404);
        }

        return response()->json($this->formatSong($song));
    }

    // ── Create ────────────────────────────────────────────────────────

    #[OA\Post(
        path: '/songs',
        summary: 'Create a new song',
        description: 'Creates a new song. Requires the `admin` or `musician` role. The `songbook_id` must reference an existing songbook.',
        tags: ['Songs'],
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: '#/components/schemas/SongInput'),
        ),
        responses: [
            new OA\Response(response: 201, description: 'Song created', content: new OA\JsonContent(ref: '#/components/schemas/SongResource')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'Role not permitted', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ValidationErrorResponse')),
        ],
    )]
    public function store(Request $request): JsonResponse
    {
        if ($forbidden = $this->requireCreatorRole($request)) {
            return $forbidden;
        }

        $validated = $this->validateSongPayload($request, creating: true);

        $song = Song::create([
            ...$validated,
            'created_by' => $request->user()->id,
            'version'    => 1,
        ]);

        return response()->json($this->formatSong($song), 201);
    }

    // ── Update ────────────────────────────────────────────────────────

    #[OA\Put(
        path: '/songs/{id}',
        summary: 'Update a song',
        description: 'Updates an existing song. Requires the `admin` or `musician` role. The `version` field increments automatically on every update (SRS NFR-8 audit trail).',
        tags: ['Songs'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: '#/components/schemas/SongInput'),
        ),
        responses: [
            new OA\Response(response: 200, description: 'Song updated', content: new OA\JsonContent(ref: '#/components/schemas/SongResource')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'Role not permitted', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 404, description: 'Song not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ValidationErrorResponse')),
        ],
    )]
    public function update(Request $request, string $id): JsonResponse
    {
        if ($forbidden = $this->requireCreatorRole($request)) {
            return $forbidden;
        }

        $song = Song::find($id);

        if (! $song) {
            return response()->json(['message' => 'Song not found.'], 404);
        }

        $validated = $this->validateSongPayload($request, creating: false);

        $song->fill($validated);
        $song->version = $song->version + 1;
        $song->save();

        return response()->json($this->formatSong($song));
    }

    // ── Soft-delete ───────────────────────────────────────────────────

    #[OA\Delete(
        path: '/songs/{id}',
        summary: 'Soft-delete a song (Admin)',
        description: 'Soft-deletes a song. The record is retained for 30 days and can be restored via `POST /songs/{id}/restore`. Requires the `admin` role.',
        tags: ['Songs'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 204, description: 'Song soft-deleted'),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'Admin role required', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 404, description: 'Song not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function destroy(string $id): JsonResponse
    {
        $song = Song::find($id);

        if (! $song) {
            return response()->json(['message' => 'Song not found.'], 404);
        }

        $song->delete();

        return response()->json(null, 204);
    }

    // ── Restore a soft-deleted song ───────────────────────────────────

    #[OA\Post(
        path: '/songs/{id}/restore',
        summary: 'Restore a soft-deleted song (Admin)',
        description: 'Restores a song that was previously soft-deleted. Requires the `admin` role.',
        tags: ['Songs'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Song restored', content: new OA\JsonContent(ref: '#/components/schemas/SongResource')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'Admin role required', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 404, description: 'Song not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function restore(string $id): JsonResponse
    {
        $song = Song::onlyTrashed()->find($id);

        if (! $song) {
            return response()->json(['message' => 'Song not found.'], 404);
        }

        $song->restore();

        return response()->json($this->formatSong($song));
    }

    // ── Private helpers ───────────────────────────────────────────────

    /**
     * @return array<string, mixed>
     */
    private function validateSongPayload(Request $request, bool $creating): array
    {
        $required = $creating ? 'required' : 'sometimes';

        return $request->validate([
            'title'          => [$required, 'string', 'max:255'],
            'author'         => ['nullable', 'string', 'max:255'],
            'lyrics'         => [$required, 'string'],
            'original_key'   => ['nullable', 'string', 'regex:'.self::KEY_PATTERN],
            'tempo'          => ['nullable', 'integer', 'min:20', 'max:300'],
            'time_signature' => ['nullable', 'string', 'in:'.implode(',', self::TIME_SIGNATURES)],
            'songbook_id'    => [$required, 'uuid', 'exists:songbooks,id'],
            'tags'           => ['nullable', 'array'],
            'tags.*'         => ['string', 'max:64'],
            'preview_url'    => ['nullable', 'url', 'max:500'],
            'ccli_number'    => ['nullable', 'string', 'max:32'],
        ]);
    }

    /** Guard: only Admin or Musician may create/update. Returns 403 response or null. */
    private function requireCreatorRole(Request $request): ?JsonResponse
    {
        $user = $request->user();

        if (! $user || (! $user->isAdmin() && ! $user->isMusician())) {
            return response()->json([
                'message' => 'Forbidden. Admin or Musician role required.',
            ], 403);
        }

        return null;
    }

    /** @return array<string, mixed> */
    private function formatSong(Song $song): array
    {
        return [
            'id'             => $song->id,
            'title'          => $song->title,
            'author'         => $song->author,
            'lyrics'         => $song->lyrics,
            'original_key'   => $song->original_key,
            'tempo'          => $song->tempo,
            'time_signature' => $song->time_signature,
            'songbook_id'    => $song->songbook_id,
            'tags'           => $song->tags ?? [],
            'preview_url'    => $song->preview_url,
            'ccli_number'    => $song->ccli_number,
            'created_by'     => $song->created_by,
            'version'        => $song->version,
            'created_at'     => $song->created_at?->toIso8601String(),
            'updated_at'     => $song->updated_at?->toIso8601String(),
            'deleted_at'     => $song->deleted_at?->toIso8601String(),
        ];
    }
}

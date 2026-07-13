<?php

namespace App\Http\Controllers;

use App\Models\Playlist;
use App\Models\PlaylistItem;
use App\Models\Song;
use App\Services\PlaylistItemFormatter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use OpenApi\Attributes as OA;

/**
 * Playlist CRUD + duplication (SRS 3.3 — Playlist Management).
 *
 * Authorization (SRS 2.2 — any role can build playlists):
 *   - List/Show: any authenticated user
 *   - Create: any authenticated user
 *   - Update: creator OR admin (admins can fix anyone's playlist)
 *   - Delete: creator OR admin
 *   - Duplicate: any authenticated user can clone any playlist they can see
 *
 * The duplicate endpoint copies items, target keys, and notes — but not
 * share links (the duplicate gets fresh ones via `POST /share`) and not
 * `created_by` (the duplicator becomes the new owner).
 *
 * Tag filtering uses whereJsonContains, mirroring the Song service.
 */
#[OA\Tag(
    name: 'Playlists',
    description: 'Playlist CRUD, item management, share links, and export.',
)]
class PlaylistController
{
    // ── List + filter ─────────────────────────────────────────────────

    #[OA\Get(
        path: '/playlists',
        summary: 'List playlists',
        description: 'Returns a paginated list of playlist summaries. Items are not hydrated — fetch a single playlist by ID to get its items. Supports filtering by name search, tag, and ownership.',
        tags: ['Playlists'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'q', in: 'query', required: false, description: 'Search term matched against playlist name (case-insensitive)', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'tag', in: 'query', required: false, description: 'Filter by tag', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'mine', in: 'query', required: false, description: 'When true, return only playlists owned by the authenticated user', schema: new OA\Schema(type: 'boolean')),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 25, maximum: 100)),
            new OA\Parameter(name: 'page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 1)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Paginated playlist list', content: new OA\JsonContent(ref: '#/components/schemas/PlaylistCollection')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'q'        => ['sometimes', 'string', 'max:255'],
            'tag'      => ['sometimes', 'string', 'max:64'],
            'mine'     => ['sometimes', 'boolean'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page'     => ['sometimes', 'integer', 'min:1'],
        ]);

        $query = Playlist::query()->withCount('items');

        if ($term = $validated['q'] ?? null) {
            $query->where('name', 'ILIKE', '%'.$term.'%');
        }

        if (! empty($validated['tag'])) {
            $query->whereJsonContains('tags', $validated['tag']);
        }

        if (! empty($validated['mine']) && $request->user()) {
            $query->where('created_by', $request->user()->id);
        }

        $perPage = (int) ($validated['per_page'] ?? 25);
        $paginator = $query->orderByDesc('created_at')->paginate($perPage);

        return response()->json([
            'data' => $paginator->getCollection()
                ->map(fn (Playlist $p) => $this->formatPlaylistSummary($p))
                ->all(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page'     => $paginator->perPage(),
                'total'        => $paginator->total(),
                'last_page'    => $paginator->lastPage(),
            ],
        ]);
    }

    // ── Show one (with items hydrated) ────────────────────────────────

    #[OA\Get(
        path: '/playlists/{id}',
        summary: 'Fetch a single playlist',
        description: 'Returns the full playlist including all items with their referenced songs.',
        tags: ['Playlists'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Playlist found', content: new OA\JsonContent(ref: '#/components/schemas/PlaylistResource')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 404, description: 'Playlist not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function show(string $id): JsonResponse
    {
        $playlist = Playlist::with(['items.song'])->find($id);

        if (! $playlist) {
            return response()->json(['message' => 'Playlist not found.'], 404);
        }

        return response()->json($this->formatPlaylist($playlist));
    }

    // ── Create ────────────────────────────────────────────────────────

    #[OA\Post(
        path: '/playlists',
        summary: 'Create a playlist',
        description: 'Creates a new playlist owned by the authenticated user. Any authenticated role may create playlists.',
        tags: ['Playlists'],
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/PlaylistInput')),
        responses: [
            new OA\Response(response: 201, description: 'Playlist created', content: new OA\JsonContent(ref: '#/components/schemas/PlaylistResource')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ValidationErrorResponse')),
        ],
    )]
    public function store(Request $request): JsonResponse
    {
        $validated = $this->validatePlaylistPayload($request, creating: true);

        $playlist = Playlist::create([
            ...$validated,
            'created_by' => $request->user()->id,
        ]);

        return response()->json($this->formatPlaylist($playlist->load('items.song')), 201);
    }

    // ── Update ────────────────────────────────────────────────────────

    #[OA\Put(
        path: '/playlists/{id}',
        summary: 'Update a playlist',
        description: 'Updates the metadata (name, event_date, tags) of an existing playlist. Requires ownership or the `admin` role.',
        tags: ['Playlists'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/PlaylistInput')),
        responses: [
            new OA\Response(response: 200, description: 'Playlist updated', content: new OA\JsonContent(ref: '#/components/schemas/PlaylistResource')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'Not the playlist owner or admin', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 404, description: 'Playlist not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ValidationErrorResponse')),
        ],
    )]
    public function update(Request $request, string $id): JsonResponse
    {
        $playlist = Playlist::find($id);

        if (! $playlist) {
            return response()->json(['message' => 'Playlist not found.'], 404);
        }

        if ($forbidden = $this->requireOwnerOrAdmin($request, $playlist)) {
            return $forbidden;
        }

        $validated = $this->validatePlaylistPayload($request, creating: false);

        $playlist->fill($validated)->save();

        return response()->json($this->formatPlaylist($playlist->load('items.song')));
    }

    // ── Delete ────────────────────────────────────────────────────────

    #[OA\Delete(
        path: '/playlists/{id}',
        summary: 'Delete a playlist',
        description: 'Permanently deletes a playlist along with its items and share links (cascade). Requires ownership or the `admin` role.',
        tags: ['Playlists'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 204, description: 'Playlist deleted'),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'Not the playlist owner or admin', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 404, description: 'Playlist not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function destroy(Request $request, string $id): JsonResponse
    {
        $playlist = Playlist::find($id);

        if (! $playlist) {
            return response()->json(['message' => 'Playlist not found.'], 404);
        }

        if ($forbidden = $this->requireOwnerOrAdmin($request, $playlist)) {
            return $forbidden;
        }

        // Cascade is wired at the DB layer (items + share links).
        $playlist->delete();

        return response()->json(null, 204);
    }

    // ── Duplicate ─────────────────────────────────────────────────────
    //
    // Creates an independent copy. Items are re-inserted with their
    // (target_key, notes, position) preserved. Share links are NOT
    // copied — duplicates start with no public visibility.

    #[OA\Post(
        path: '/playlists/{id}/duplicate',
        summary: 'Duplicate a playlist',
        description: 'Creates an independent copy of the playlist. Items (including target keys and notes) are cloned; share links are not. The caller becomes the owner of the copy. Any authenticated user may duplicate any visible playlist.',
        tags: ['Playlists'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(
            required: false,
            content: new OA\JsonContent(ref: '#/components/schemas/DuplicatePlaylistInput'),
        ),
        responses: [
            new OA\Response(response: 201, description: 'Duplicate created', content: new OA\JsonContent(ref: '#/components/schemas/PlaylistResource')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 404, description: 'Source playlist not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function duplicate(Request $request, string $id): JsonResponse
    {
        $source = Playlist::with('items')->find($id);

        if (! $source) {
            return response()->json(['message' => 'Playlist not found.'], 404);
        }

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
        ]);

        $newName = $validated['name'] ?? "{$source->name} (copy)";

        $copy = DB::transaction(function () use ($source, $newName, $request) {
            $copy = Playlist::create([
                'name'               => $newName,
                'event_date'         => $source->event_date,
                'tags'               => $source->tags ?? [],
                'created_by'         => $request->user()->id,
                'duplicated_from_id' => $source->id,
            ]);

            foreach ($source->items as $item) {
                // replicate() copies every attribute (song + scripture fields
                // alike) except the primary key, so a duplicated playlist
                // preserves polymorphic items without enumerating columns.
                $copyItem = $item->replicate();
                $copyItem->playlist_id = $copy->id;
                $copyItem->save();
            }

            return $copy;
        });

        return response()->json($this->formatPlaylist($copy->load('items.song')), 201);
    }

    // ── Private helpers ───────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function validatePlaylistPayload(Request $request, bool $creating): array
    {
        $required = $creating ? 'required' : 'sometimes';

        return $request->validate([
            'name'       => [$required, 'string', 'max:255'],
            'event_date' => ['nullable', 'date'],
            'tags'       => ['nullable', 'array'],
            'tags.*'     => ['string', 'max:64'],
        ]);
    }

    /**
     * Owner or admin gate. Most operations on an existing playlist go
     * through this. Returns a 403 response or null.
     */
    private function requireOwnerOrAdmin(Request $request, Playlist $playlist): ?JsonResponse
    {
        $user = $request->user();

        if (! $user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        if ($playlist->created_by === $user->id || $user->isAdmin()) {
            return null;
        }

        return response()->json([
            'message' => 'Forbidden. You do not own this playlist.',
        ], 403);
    }

    /**
     * Lightweight summary used by the listing endpoint. Items are NOT
     * hydrated — clients hit /playlists/:id when they actually need them.
     *
     * @return array<string, mixed>
     */
    private function formatPlaylistSummary(Playlist $playlist): array
    {
        return [
            'id'          => $playlist->id,
            'name'        => $playlist->name,
            'event_date'  => $playlist->event_date?->toDateString(),
            'tags'        => $playlist->tags ?? [],
            'created_by'  => $playlist->created_by,
            'item_count'  => $playlist->items_count ?? $playlist->items()->count(),
            'created_at'  => $playlist->created_at?->toIso8601String(),
            'updated_at'  => $playlist->updated_at?->toIso8601String(),
        ];
    }

    /**
     * Full payload used by show/store/update/duplicate. Includes hydrated
     * items with their referenced songs.
     *
     * @return array<string, mixed>
     */
    private function formatPlaylist(Playlist $playlist): array
    {
        return [
            'id'                 => $playlist->id,
            'name'               => $playlist->name,
            'event_date'         => $playlist->event_date?->toDateString(),
            'tags'               => $playlist->tags ?? [],
            'created_by'         => $playlist->created_by,
            'duplicated_from_id' => $playlist->duplicated_from_id,
            'created_at'         => $playlist->created_at?->toIso8601String(),
            'updated_at'         => $playlist->updated_at?->toIso8601String(),
            'items'              => $playlist->items->map(fn (PlaylistItem $i) => $this->formatItem($i))->all(),
        ];
    }

    /** @return array<string, mixed> */
    private function formatItem(PlaylistItem $item): array
    {
        return PlaylistItemFormatter::format($item);
    }
}

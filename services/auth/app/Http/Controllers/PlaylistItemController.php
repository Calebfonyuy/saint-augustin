<?php

namespace App\Http\Controllers;

use App\Models\Playlist;
use App\Models\PlaylistItem;
use App\Models\Song;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use OpenApi\Attributes as OA;

/**
 * Playlist item management — add/remove/reorder/update items on a playlist.
 *
 * Authorization: same rule as PlaylistController.update — owner or admin.
 *
 * Reordering: the frontend sends a full ordered list of item IDs. We
 * apply it in a transaction with a two-pass position update so the
 * (playlist_id, position) unique constraint is never violated mid-flight.
 *
 * Adding: position defaults to "end of list" when not specified; otherwise
 * the requested slot is reserved by shifting subsequent items down.
 */
#[OA\Tag(
    name: 'Playlist Items',
    description: 'Add, update, remove, and reorder items within a playlist.',
)]
class PlaylistItemController
{
    private const KEY_PATTERN = '/^[A-G][#b]?m?$/';

    // ── Add an item to a playlist ─────────────────────────────────────

    #[OA\Post(
        path: '/playlists/{playlistId}/items',
        summary: 'Add a song to a playlist',
        description: 'Inserts a song into the playlist at the given position (defaults to end). Subsequent items shift down by one. Requires ownership or the `admin` role.',
        tags: ['Playlist Items'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'playlistId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/PlaylistItemInput')),
        responses: [
            new OA\Response(response: 201, description: 'Item added', content: new OA\JsonContent(ref: '#/components/schemas/PlaylistItem')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'Not the playlist owner or admin', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 404, description: 'Playlist not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ValidationErrorResponse')),
        ],
    )]
    public function store(Request $request, string $playlistId): JsonResponse
    {
        $playlist = Playlist::find($playlistId);

        if (! $playlist) {
            return response()->json(['message' => 'Playlist not found.'], 404);
        }

        if ($forbidden = $this->requireOwnerOrAdmin($request, $playlist)) {
            return $forbidden;
        }

        $validated = $request->validate([
            'song_id'    => ['required', 'uuid', 'exists:songs,id'],
            'position'   => ['sometimes', 'integer', 'min:0'],
            'target_key' => ['nullable', 'string', 'regex:'.self::KEY_PATTERN],
            'notes'      => ['nullable', 'string', 'max:2000'],
        ]);

        $item = DB::transaction(function () use ($playlist, $validated) {
            $count = $playlist->items()->count();
            $position = $validated['position'] ?? $count;
            $position = min($position, $count); // clamp; can't insert past end

            // Make room: shift subsequent items down by one. The two-step
            // (positions go negative, then back to positive) avoids hitting
            // the unique constraint mid-update.
            $playlist->items()
                ->where('position', '>=', $position)
                ->orderByDesc('position')
                ->get()
                ->each(function (PlaylistItem $i) {
                    $i->update(['position' => -($i->position + 1)]);
                });

            $playlist->items()
                ->where('position', '<', 0)
                ->orderBy('position')
                ->get()
                ->each(function (PlaylistItem $i) {
                    $i->update(['position' => -$i->position]);
                });

            return PlaylistItem::create([
                'playlist_id' => $playlist->id,
                'song_id'     => $validated['song_id'],
                'position'    => $position,
                'target_key'  => $validated['target_key'] ?? null,
                'notes'       => $validated['notes'] ?? null,
            ]);
        });

        return response()->json($this->formatItem($item->load('song')), 201);
    }

    // ── Update an item (target_key / notes) ───────────────────────────

    #[OA\Put(
        path: '/playlists/{playlistId}/items/{itemId}',
        summary: 'Update a playlist item',
        description: 'Updates the `target_key` and/or `notes` on an existing playlist item. Sending `null` for either field clears it. Requires ownership or the `admin` role.',
        tags: ['Playlist Items'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'playlistId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'itemId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/PlaylistItemUpdateInput')),
        responses: [
            new OA\Response(response: 200, description: 'Item updated', content: new OA\JsonContent(ref: '#/components/schemas/PlaylistItem')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'Not the playlist owner or admin', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 404, description: 'Playlist or item not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ValidationErrorResponse')),
        ],
    )]
    public function update(Request $request, string $playlistId, string $itemId): JsonResponse
    {
        [$playlist, $item, $error] = $this->resolvePair($playlistId, $itemId);

        if ($error) {
            return $error;
        }

        if ($forbidden = $this->requireOwnerOrAdmin($request, $playlist)) {
            return $forbidden;
        }

        $validated = $request->validate([
            'target_key' => ['nullable', 'string', 'regex:'.self::KEY_PATTERN],
            'notes'      => ['nullable', 'string', 'max:2000'],
        ]);

        // Allow explicit clearing — only fill keys that were sent.
        $item->fill(array_intersect_key($validated, $request->all()))->save();

        return response()->json($this->formatItem($item->load('song')));
    }

    // ── Remove an item ────────────────────────────────────────────────

    #[OA\Delete(
        path: '/playlists/{playlistId}/items/{itemId}',
        summary: 'Remove a song from a playlist',
        description: 'Deletes the item and compacts the position sequence so there are no gaps. Requires ownership or the `admin` role.',
        tags: ['Playlist Items'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'playlistId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'itemId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 204, description: 'Item removed'),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'Not the playlist owner or admin', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 404, description: 'Playlist or item not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function destroy(Request $request, string $playlistId, string $itemId): JsonResponse
    {
        [$playlist, $item, $error] = $this->resolvePair($playlistId, $itemId);

        if ($error) {
            return $error;
        }

        if ($forbidden = $this->requireOwnerOrAdmin($request, $playlist)) {
            return $forbidden;
        }

        $removedPosition = $item->position;

        DB::transaction(function () use ($item, $playlist, $removedPosition) {
            $item->delete();

            // Compact: shift everything after the removed slot up by one.
            $playlist->items()
                ->where('position', '>', $removedPosition)
                ->orderBy('position')
                ->get()
                ->each(fn (PlaylistItem $i) => $i->update(['position' => $i->position - 1]));
        });

        return response()->json(null, 204);
    }

    // ── Reorder all items in one shot ─────────────────────────────────
    //
    // Body: { "item_ids": ["uuid", "uuid", ...] }
    // The order of the array becomes the new (0-based) positions.

    #[OA\Put(
        path: '/playlists/{playlistId}/items/reorder',
        summary: 'Reorder all items in a playlist',
        description: 'Accepts an ordered array of every item UUID in the playlist. The array index becomes the new zero-based position. The submitted set must exactly match the current item set — no missing IDs, no foreign IDs. Requires ownership or the `admin` role.',
        tags: ['Playlist Items'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'playlistId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/PlaylistReorderInput')),
        responses: [
            new OA\Response(response: 200, description: 'Items reordered', content: new OA\JsonContent(ref: '#/components/schemas/PlaylistItemCollection')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'Not the playlist owner or admin', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 404, description: 'Playlist not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'Item set mismatch or validation error', content: new OA\JsonContent(ref: '#/components/schemas/ValidationErrorResponse')),
        ],
    )]
    public function reorder(Request $request, string $playlistId): JsonResponse
    {
        $playlist = Playlist::find($playlistId);

        if (! $playlist) {
            return response()->json(['message' => 'Playlist not found.'], 404);
        }

        if ($forbidden = $this->requireOwnerOrAdmin($request, $playlist)) {
            return $forbidden;
        }

        $validated = $request->validate([
            'item_ids'   => ['required', 'array'],
            'item_ids.*' => ['uuid'],
        ]);

        $existing = $playlist->items()->pluck('id')->all();
        $requested = $validated['item_ids'];

        // The submitted list must match (as a set) the current items —
        // no missing IDs, no foreign IDs.
        if (count($existing) !== count($requested) || array_diff($existing, $requested)) {
            return response()->json([
                'message' => 'Reorder list must contain every item ID in this playlist exactly once.',
            ], 422);
        }

        DB::transaction(function () use ($playlist, $requested) {
            // Two-pass: park positions in a negative range, then settle.
            // This avoids tripping the (playlist_id, position) unique key
            // while moves are mid-flight.
            $playlist->items()
                ->orderByDesc('position')
                ->get()
                ->each(fn (PlaylistItem $i) => $i->update(['position' => -($i->position + 1)]));

            foreach ($requested as $newPos => $itemId) {
                PlaylistItem::where('id', $itemId)
                    ->where('playlist_id', $playlist->id)
                    ->update(['position' => $newPos]);
            }
        });

        return response()->json([
            'data' => $playlist->items()->with('song')->get()
                ->map(fn (PlaylistItem $i) => $this->formatItem($i))->all(),
        ]);
    }

    // ── Helpers ───────────────────────────────────────────────────────

    /**
     * @return array{0: ?Playlist, 1: ?PlaylistItem, 2: ?JsonResponse}
     */
    private function resolvePair(string $playlistId, string $itemId): array
    {
        $playlist = Playlist::find($playlistId);

        if (! $playlist) {
            return [null, null, response()->json(['message' => 'Playlist not found.'], 404)];
        }

        $item = $playlist->items()->where('id', $itemId)->first();

        if (! $item) {
            return [$playlist, null, response()->json(['message' => 'Playlist item not found.'], 404)];
        }

        return [$playlist, $item, null];
    }

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

    /** @return array<string, mixed> */
    private function formatItem(PlaylistItem $item): array
    {
        $song = $item->song;

        return [
            'id'         => $item->id,
            'song_id'    => $item->song_id,
            'position'   => $item->position,
            'target_key' => $item->target_key,
            'notes'      => $item->notes,
            'song'       => $song ? [
                'id'             => $song->id,
                'title'          => $song->title,
                'author'         => $song->author,
                'original_key'   => $song->original_key,
                'tempo'          => $song->tempo,
                'time_signature' => $song->time_signature,
                'deleted'        => $song->trashed(),
            ] : null,
        ];
    }
}

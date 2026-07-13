<?php

namespace App\Http\Controllers;

use App\Models\Playlist;
use App\Models\PlaylistItem;
use App\Services\PlaylistItemFormatter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
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
 * Re-adding a song already in the playlist is a no-op — the existing
 * item comes back with 200 instead of a duplicate row (FR-SL-4).
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
        description: 'Inserts a song into the playlist at the given position (defaults to end). Subsequent items shift down by one. If the song is already in the playlist, nothing is inserted and the existing item is returned with 200. Requires ownership or the `admin` role.',
        tags: ['Playlist Items'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'playlistId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/PlaylistItemInput')),
        responses: [
            new OA\Response(response: 200, description: 'Song already in the playlist — the existing item is returned unchanged (no-op)', content: new OA\JsonContent(ref: '#/components/schemas/PlaylistItem')),
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

        $type = $request->input('item_type', PlaylistItem::TYPE_SONG);

        if (! in_array($type, PlaylistItem::TYPES, true)) {
            // Validate the discriminator first so the branch below is safe.
            $request->validate(['item_type' => [Rule::in(PlaylistItem::TYPES)]]);
        }

        $validated = $request->validate($this->addRules($type));

        if ($type === PlaylistItem::TYPE_SCRIPTURE) {
            if ($error = $this->assertValidRange($validated)) {
                return $error;
            }
        } else {
            // Re-adding a song already in the playlist is a no-op (FR-SL-4):
            // return the existing item so the client can tell "already there"
            // (200) from "added" (201). Scripture readings have no natural
            // dedupe key and may legitimately repeat, so this only applies to
            // song items.
            $existing = $playlist->items()
                ->where('item_type', PlaylistItem::TYPE_SONG)
                ->where('song_id', $validated['song_id'])
                ->first();

            if ($existing) {
                return response()->json($this->formatItem($existing->load('song')), 200);
            }
        }

        $item = DB::transaction(function () use ($playlist, $validated, $type) {
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

            return PlaylistItem::create($this->buildAttributes($playlist, $validated, $type, $position));
        });

        return response()->json($this->formatItem($item->load('song')), 201);
    }

    /**
     * Validation rules for adding an item, keyed by discriminator. Exactly
     * one payload shape is accepted per `item_type`.
     *
     * @return array<string, array<int, mixed>>
     */
    private function addRules(string $type): array
    {
        $common = [
            'item_type' => ['sometimes', Rule::in(PlaylistItem::TYPES)],
            'position'  => ['sometimes', 'integer', 'min:0'],
            'notes'     => ['nullable', 'string', 'max:2000'],
        ];

        if ($type === PlaylistItem::TYPE_SCRIPTURE) {
            return $common + [
                'translation_id' => ['nullable', 'string', 'max:32'],
                'book_code'      => ['required', 'string', 'max:8'],
                'start_chapter'  => ['required', 'integer', 'min:1'],
                'start_verse'    => ['required', 'integer', 'min:1'],
                'end_chapter'    => ['nullable', 'integer', 'min:1'],
                'end_verse'      => ['nullable', 'integer', 'min:1', 'required_with:end_chapter'],
            ];
        }

        return $common + [
            'song_id'    => ['required', 'uuid', 'exists:songs,id'],
            'target_key' => ['nullable', 'string', 'regex:'.self::KEY_PATTERN],
        ];
    }

    /**
     * Build the create payload for a new item, forcing the fields that don't
     * belong to the item's type to null so a stray song_id never rides along
     * on a scripture row (and vice versa).
     *
     * @param  array<string, mixed>  $v
     * @return array<string, mixed>
     */
    private function buildAttributes(Playlist $playlist, array $v, string $type, int $position): array
    {
        $base = [
            'playlist_id' => $playlist->id,
            'item_type'   => $type,
            'position'    => $position,
            'notes'       => $v['notes'] ?? null,
        ];

        if ($type === PlaylistItem::TYPE_SCRIPTURE) {
            return $base + [
                'translation_id' => $v['translation_id'] ?? null,
                'book_code'      => $v['book_code'],
                'start_chapter'  => $v['start_chapter'],
                'start_verse'    => $v['start_verse'],
                'end_chapter'    => $v['end_chapter'] ?? null,
                'end_verse'      => $v['end_verse'] ?? null,
            ];
        }

        return $base + [
            'song_id'    => $v['song_id'],
            'target_key' => $v['target_key'] ?? null,
        ];
    }

    /**
     * Reject a scripture range whose end falls before its start. Returns a
     * 422 response or null. Operates on already-validated integer fields.
     *
     * @param  array<string, mixed>  $v
     */
    private function assertValidRange(array $v): ?JsonResponse
    {
        if (! isset($v['end_verse'])) {
            return null; // single-verse or open reference
        }

        $endChapter = $v['end_chapter'] ?? $v['start_chapter'];

        $backwards = $endChapter < $v['start_chapter']
            || ($endChapter === $v['start_chapter'] && $v['end_verse'] < $v['start_verse']);

        if ($backwards) {
            return response()->json([
                'message' => 'The scripture range end must not come before its start.',
                'errors'  => ['end_verse' => ['The reference range end is before its start.']],
            ], 422);
        }

        return null;
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

        // The item's type is fixed at creation; update only edits the fields
        // that belong to it (scripture reference for a reading, key for a
        // song). `notes` applies to both.
        if ($item->isScripture()) {
            $validated = $request->validate([
                'notes'          => ['nullable', 'string', 'max:2000'],
                'translation_id' => ['sometimes', 'nullable', 'string', 'max:32'],
                'book_code'      => ['sometimes', 'string', 'max:8'],
                'start_chapter'  => ['sometimes', 'integer', 'min:1'],
                'start_verse'    => ['sometimes', 'integer', 'min:1'],
                'end_chapter'    => ['sometimes', 'nullable', 'integer', 'min:1'],
                'end_verse'      => ['sometimes', 'nullable', 'integer', 'min:1'],
            ]);
        } else {
            $validated = $request->validate([
                'target_key' => ['nullable', 'string', 'regex:'.self::KEY_PATTERN],
                'notes'      => ['nullable', 'string', 'max:2000'],
            ]);
        }

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
        return PlaylistItemFormatter::format($item);
    }
}

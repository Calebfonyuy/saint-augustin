<?php

namespace App\Http\Controllers;

use App\Models\Playlist;
use App\Models\PlaylistItem;
use App\Models\Song;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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
class PlaylistController
{
    /** Re-used by both Playlist and PlaylistItem validation. */
    private const KEY_PATTERN = '/^[A-G][#b]?m?$/';

    // ── List + filter ─────────────────────────────────────────────────

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

    public function show(string $id): JsonResponse
    {
        $playlist = Playlist::with(['items.song'])->find($id);

        if (! $playlist) {
            return response()->json(['message' => 'Playlist not found.'], 404);
        }

        return response()->json($this->formatPlaylist($playlist));
    }

    // ── Create ────────────────────────────────────────────────────────

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
                PlaylistItem::create([
                    'playlist_id' => $copy->id,
                    'song_id'     => $item->song_id,
                    'position'    => $item->position,
                    'target_key'  => $item->target_key,
                    'notes'       => $item->notes,
                ]);
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
        $song = $item->song;

        return [
            'id'          => $item->id,
            'song_id'     => $item->song_id,
            'position'    => $item->position,
            'target_key'  => $item->target_key,
            'notes'       => $item->notes,
            'song'        => $song ? [
                'id'           => $song->id,
                'title'        => $song->title,
                'author'       => $song->author,
                'original_key' => $song->original_key,
                'tempo'        => $song->tempo,
                'time_signature' => $song->time_signature,
                'deleted'      => $song->trashed(),
            ] : null,
        ];
    }
}

<?php

namespace App\Http\Controllers;

use App\Models\Playlist;
use App\Models\PlaylistItem;
use App\Models\ShareLink;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Share-link CRUD + public token resolution (SRS 3.3 / 7.1).
 *
 * Two distinct surfaces:
 *
 *   1. Authenticated (owner/admin only):
 *      - POST   /playlists/:id/share          create a share link
 *      - GET    /playlists/:id/share          list this playlist's share links
 *      - DELETE /share-links/:id              revoke (sets revoked_at)
 *
 *   2. Public (no auth):
 *      - GET    /share/:token                 resolve token → playlist payload
 *
 * The public endpoint deliberately returns a flat 404 for any unusable
 * token (revoked, expired, unknown) — same response, no information leak.
 *
 * The shape returned to the public endpoint includes the playlist's items
 * with their referenced songs (lyrics + ChordPro). This lets the
 * frontend render either Musician View or Projection View entirely
 * client-side based on `mode`.
 */
class ShareLinkController
{
    // ── Authenticated: list this playlist's share links ──────────────

    public function index(Request $request, string $playlistId): JsonResponse
    {
        $playlist = Playlist::find($playlistId);

        if (! $playlist) {
            return response()->json(['message' => 'Playlist not found.'], 404);
        }

        if ($forbidden = $this->requireOwnerOrAdmin($request, $playlist)) {
            return $forbidden;
        }

        $links = $playlist->shareLinks()->orderByDesc('created_at')->get();

        return response()->json([
            'data' => $links->map(fn (ShareLink $l) => $this->formatLink($l))->all(),
        ]);
    }

    // ── Authenticated: create a share link ────────────────────────────

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
            'mode'       => ['required', 'string', 'in:'.implode(',', ShareLink::MODES)],
            'expires_at' => ['nullable', 'date', 'after:now'],
        ]);

        $link = ShareLink::create([
            'playlist_id' => $playlist->id,
            'token'       => ShareLink::generateToken(),
            'mode'        => $validated['mode'],
            'created_by'  => $request->user()->id,
            'expires_at'  => $validated['expires_at'] ?? null,
        ]);

        return response()->json($this->formatLink($link), 201);
    }

    // ── Authenticated: revoke ─────────────────────────────────────────

    public function destroy(Request $request, string $shareLinkId): JsonResponse
    {
        $link = ShareLink::with('playlist')->find($shareLinkId);

        if (! $link) {
            return response()->json(['message' => 'Share link not found.'], 404);
        }

        if ($forbidden = $this->requireOwnerOrAdmin($request, $link->playlist)) {
            return $forbidden;
        }

        // Soft-revoke — keep the row for audit, just kill the token's usability.
        $link->update(['revoked_at' => now()]);

        return response()->json(null, 204);
    }

    // ── Public: resolve a token → playlist payload ────────────────────
    //
    // No auth. Bots & link-sharers can hit this freely. Rate-limiting is
    // applied at the route definition (throttle:share-public).

    public function resolve(string $token): JsonResponse
    {
        $link = ShareLink::where('token', $token)->first();

        if (! $link || ! $link->isUsable()) {
            return response()->json(['message' => 'Share link not found or no longer active.'], 404);
        }

        $playlist = Playlist::with(['items.song'])->find($link->playlist_id);

        if (! $playlist) {
            // Underlying playlist was deleted — auto-revoke for cleanliness.
            $link->update(['revoked_at' => now()]);

            return response()->json(['message' => 'Share link no longer points to a playlist.'], 404);
        }

        return response()->json([
            'mode'     => $link->mode,
            'playlist' => $this->formatPublicPlaylist($playlist, $link->mode),
        ]);
    }

    // ── Helpers ───────────────────────────────────────────────────────

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
    private function formatLink(ShareLink $link): array
    {
        return [
            'id'          => $link->id,
            'playlist_id' => $link->playlist_id,
            'token'       => $link->token,
            'mode'        => $link->mode,
            'expires_at'  => $link->expires_at?->toIso8601String(),
            'revoked_at'  => $link->revoked_at?->toIso8601String(),
            'created_at'  => $link->created_at?->toIso8601String(),
        ];
    }

    /**
     * Public-facing playlist payload. Items always carry song lyrics and
     * ChordPro source; the client decides how to display based on `mode`
     * (musician view → render chords; projection view → strip chords).
     *
     * @return array<string, mixed>
     */
    private function formatPublicPlaylist(Playlist $playlist, string $mode): array
    {
        return [
            'id'         => $playlist->id,
            'name'       => $playlist->name,
            'event_date' => $playlist->event_date?->toDateString(),
            'tags'       => $playlist->tags ?? [],
            'items'      => $playlist->items->map(function (PlaylistItem $item) use ($mode) {
                $song = $item->song;

                return [
                    'id'         => $item->id,
                    'position'   => $item->position,
                    'target_key' => $item->target_key,
                    'notes'      => $item->notes,
                    'song'       => $song ? [
                        'id'             => $song->id,
                        'title'          => $song->title,
                        'author'         => $song->author,
                        'lyrics'         => $song->lyrics,
                        'original_key'   => $song->original_key,
                        'tempo'          => $song->tempo,
                        'time_signature' => $song->time_signature,
                        // Deliberately NOT exposing preview_url / sheets in
                        // projection mode — those are musician-only assets.
                        'preview_url'    => $mode === ShareLink::MODE_MUSICIAN ? $song->preview_url : null,
                    ] : null,
                ];
            })->all(),
        ];
    }
}

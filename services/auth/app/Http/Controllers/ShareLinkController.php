<?php

namespace App\Http\Controllers;

use App\Models\Playlist;
use App\Models\PlaylistItem;
use App\Models\ShareLink;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

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
#[OA\Tag(
    name: 'Share Links',
    description: 'Create, list, and revoke share links for playlists, plus the public token-resolution endpoint.',
)]
class ShareLinkController
{
    // ── Authenticated: list this playlist's share links ──────────────

    #[OA\Get(
        path: '/playlists/{playlistId}/share',
        summary: "List a playlist's share links",
        description: 'Returns all share links (including revoked and expired) for the given playlist, ordered newest first. Requires ownership or the `admin` role.',
        tags: ['Share Links'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'playlistId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Share link list', content: new OA\JsonContent(ref: '#/components/schemas/ShareLinkCollection')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'Not the playlist owner or admin', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 404, description: 'Playlist not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
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

    #[OA\Post(
        path: '/playlists/{playlistId}/share',
        summary: 'Create a share link',
        description: 'Generates a new URL-safe token that grants public read access to the playlist. `mode` controls the payload shape: `musician` includes full song lyrics and preview URLs; `projection` includes lyrics but omits preview URLs. Requires ownership or the `admin` role.',
        tags: ['Share Links'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'playlistId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/ShareLinkInput')),
        responses: [
            new OA\Response(response: 201, description: 'Share link created', content: new OA\JsonContent(ref: '#/components/schemas/ShareLinkResource')),
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

    #[OA\Delete(
        path: '/share-links/{id}',
        summary: 'Revoke a share link',
        description: 'Soft-revokes the share link by setting `revoked_at`. The token becomes immediately unusable but the row is retained for audit purposes. Requires ownership of the associated playlist or the `admin` role.',
        tags: ['Share Links'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, description: 'Share link UUID', schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 204, description: 'Share link revoked'),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'Not the playlist owner or admin', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 404, description: 'Share link not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
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

    #[OA\Get(
        path: '/share/{token}',
        summary: 'Resolve a public share link',
        description: 'No authentication required. Returns the playlist payload shaped for the link\'s `mode`. A flat 404 is returned for any token that is invalid, revoked, or expired — the response gives no indication of which condition applies. Rate-limited to 60 requests per minute.',
        tags: ['Share Links'],
        parameters: [
            new OA\Parameter(name: 'token', in: 'path', required: true, description: 'URL-safe share token', schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Playlist payload', content: new OA\JsonContent(ref: '#/components/schemas/SharedPlaylistResponse')),
            new OA\Response(response: 404, description: 'Token invalid, revoked, or expired', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 429, description: 'Rate limit exceeded', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
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
                    'item_type'  => $item->item_type,
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
                    // Scripture readings carry a reference instead of a song;
                    // public rendering of readings arrives in Stage 7.
                    'scripture'  => $item->isScripture() ? [
                        'translation_id' => $item->translation_id,
                        'book_code'      => $item->book_code,
                        'start_chapter'  => $item->start_chapter,
                        'start_verse'    => $item->start_verse,
                        'end_chapter'    => $item->end_chapter,
                        'end_verse'      => $item->end_verse,
                        'reference'      => $item->scriptureReference(),
                    ] : null,
                ];
            })->all(),
        ];
    }
}

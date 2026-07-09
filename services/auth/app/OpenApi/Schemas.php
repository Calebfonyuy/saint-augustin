<?php

namespace App\OpenApi;

use OpenApi\Attributes as OA;

/**
 * Reusable OpenAPI schema definitions.
 *
 * These are referenced via $ref across controller annotations so they are
 * defined once and stay consistent.
 */

#[OA\Schema(
    schema: 'UserResource',
    description: 'A user record returned by the API. Does not include sensitive fields like `password` or `remember_token`.',
    required: ['id', 'email', 'display_name', 'roles'],
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'email', type: 'string', format: 'email', example: 'musician@example.com'),
        new OA\Property(property: 'display_name', type: 'string', example: 'Jean Dupont'),
        new OA\Property(
            property: 'roles',
            type: 'array',
            items: new OA\Items(type: 'string', enum: ['admin', 'musician', 'projectionist']),
            example: ['musician'],
        ),
    ],
    type: 'object',
)]

#[OA\Schema(
    schema: 'TokenResponse',
    description: 'Successful authentication response carrying a Sanctum Bearer token.',
    required: ['token_type', 'access_token', 'user'],
    properties: [
        new OA\Property(property: 'token_type', type: 'string', example: 'Bearer'),
        new OA\Property(property: 'access_token', type: 'string', example: '1|abc123...'),
        new OA\Property(
            property: 'user',
            type: 'object',
            required: ['id', 'email', 'display_name', 'roles'],
            properties: [
                new OA\Property(property: 'id', type: 'string', format: 'uuid', example: 'a1b2c3d4-...'),
                new OA\Property(property: 'email', type: 'string', format: 'email', example: 'musician@example.com'),
                new OA\Property(property: 'display_name', type: 'string', example: 'Jean Dupont'),
                new OA\Property(
                    property: 'roles',
                    type: 'array',
                    items: new OA\Items(type: 'string', enum: ['admin', 'musician', 'projectionist']),
                    example: ['musician'],
                ),
            ],
        ),
    ],
    type: 'object',
)]
#[OA\Schema(
    schema: 'ErrorResponse',
    description: 'Generic error response.',
    required: ['message'],
    properties: [
        new OA\Property(property: 'message', type: 'string', example: 'Invalid credentials.'),
    ],
    type: 'object',
)]
#[OA\Schema(
    schema: 'ValidationErrorResponse',
    description: 'Laravel validation failure (HTTP 422).',
    required: ['message', 'errors'],
    properties: [
        new OA\Property(property: 'message', type: 'string', example: 'The email field is required.'),
        new OA\Property(
            property: 'errors',
            type: 'object',
            additionalProperties: new OA\AdditionalProperties(
                type: 'array',
                items: new OA\Items(type: 'string'),
            ),
            example: ['email' => ['The email field is required.']],
        ),
    ],
    type: 'object',
)]
#[OA\Schema(
    schema: 'InvitationResource',
    description: 'An admin-issued invitation record.',
    required: ['id', 'email', 'roles', 'expires_at', 'accepted'],
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'email', type: 'string', format: 'email', example: 'newuser@example.com'),
        new OA\Property(
            property: 'roles',
            type: 'array',
            items: new OA\Items(type: 'string', enum: ['admin', 'musician', 'projectionist']),
            example: ['musician'],
        ),
        new OA\Property(property: 'invited_by', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'expires_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'accepted', type: 'boolean', example: false),
    ],
    type: 'object',
)]
#[OA\Schema(
    schema: 'SongbookResource',
    description: 'A songbook — a named collection of songs.',
    required: ['id', 'name', 'is_default', 'songs_count'],
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'name', type: 'string', example: 'Christmas 2026'),
        new OA\Property(property: 'description', type: 'string', nullable: true, example: 'Songs for Advent and Christmas services.'),
        new OA\Property(property: 'is_default', type: 'boolean', example: false),
        new OA\Property(property: 'created_by', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'songs_count', type: 'integer', example: 42, description: 'Number of (non-soft-deleted) songs in this songbook.'),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
    ],
    type: 'object',
)]
#[OA\Schema(
    schema: 'SongInput',
    description: 'Payload for creating or updating a song. On create, `title`, `lyrics`, and `songbook_id` are required; on update, any field may be omitted.',
    properties: [
        new OA\Property(property: 'title', type: 'string', example: 'Amazing Grace'),
        new OA\Property(property: 'author', type: 'string', nullable: true, example: 'John Newton'),
        new OA\Property(
            property: 'lyrics',
            type: 'string',
            description: 'ChordPro content — chord symbols live inline in brackets.',
            example: "{start_of_verse}\n[C]Amazing [G]grace, how [Am]sweet the [F]sound\n{end_of_verse}",
        ),
        new OA\Property(property: 'original_key', type: 'string', nullable: true, example: 'G', description: 'Musical key (e.g. C, F#, Am, Bbm)'),
        new OA\Property(property: 'tempo', type: 'integer', nullable: true, minimum: 20, maximum: 300, example: 72),
        new OA\Property(property: 'time_signature', type: 'string', nullable: true, enum: ['2/4', '3/4', '4/4', '5/4', '6/4', '3/8', '6/8', '9/8', '12/8'], example: '3/4'),
        new OA\Property(property: 'songbook_id', type: 'string', format: 'uuid'),
        new OA\Property(
            property: 'tags',
            type: 'array',
            items: new OA\Items(type: 'string'),
            nullable: true,
            example: ['praise', 'communion'],
        ),
        new OA\Property(property: 'preview_url', type: 'string', format: 'uri', nullable: true, example: 'https://www.youtube.com/watch?v=CDdvReNKVuk'),
        new OA\Property(property: 'ccli_number', type: 'string', nullable: true, example: '22025'),
    ],
    type: 'object',
)]
#[OA\Schema(
    schema: 'SongResource',
    description: 'A song record.',
    required: ['id', 'title', 'lyrics', 'songbook_id', 'tags', 'version'],
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'title', type: 'string', example: 'Amazing Grace'),
        new OA\Property(property: 'author', type: 'string', nullable: true, example: 'John Newton'),
        new OA\Property(property: 'lyrics', type: 'string', description: 'ChordPro content with chords inline.'),
        new OA\Property(property: 'original_key', type: 'string', nullable: true, example: 'G'),
        new OA\Property(property: 'tempo', type: 'integer', nullable: true, example: 72),
        new OA\Property(property: 'time_signature', type: 'string', nullable: true, example: '3/4'),
        new OA\Property(property: 'songbook_id', type: 'string', format: 'uuid'),
        new OA\Property(
            property: 'tags',
            type: 'array',
            items: new OA\Items(type: 'string'),
            example: ['praise', 'communion'],
        ),
        new OA\Property(property: 'preview_url', type: 'string', format: 'uri', nullable: true),
        new OA\Property(property: 'ccli_number', type: 'string', nullable: true),
        new OA\Property(property: 'created_by', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'version', type: 'integer', example: 1, description: 'Increments on every update (audit trail).'),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'deleted_at', type: 'string', format: 'date-time', nullable: true),
    ],
    type: 'object',
)]
#[OA\Schema(
    schema: 'SongSheetResource',
    description: 'Metadata for a file attached to a song, plus a short-lived presigned download URL.',
    required: ['id', 'song_id', 'original_filename', 'file_type', 'mime_type', 'size_bytes', 'url'],
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'song_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'original_filename', type: 'string', example: 'amazing-grace-lead-sheet.pdf'),
        new OA\Property(property: 'file_type', type: 'string', enum: ['pdf', 'image'], example: 'pdf'),
        new OA\Property(property: 'mime_type', type: 'string', example: 'application/pdf'),
        new OA\Property(property: 'size_bytes', type: 'integer', example: 184320),
        new OA\Property(property: 'uploaded_by', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'url', type: 'string', format: 'uri', description: 'Presigned download URL — expires after SONG_SHEET_URL_TTL minutes.'),
        new OA\Property(property: 'url_expires_at', type: 'string', format: 'date-time', nullable: true, description: 'Null when the disk does not support presigned URLs (e.g. local disk in tests).'),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
    ],
    type: 'object',
)]
#[OA\Schema(
    schema: 'SongCollection',
    description: 'Paginated collection of songs.',
    required: ['data', 'meta'],
    properties: [
        new OA\Property(
            property: 'data',
            type: 'array',
            items: new OA\Items(ref: '#/components/schemas/SongResource'),
        ),
        new OA\Property(
            property: 'meta',
            type: 'object',
            required: ['current_page', 'per_page', 'total', 'last_page'],
            properties: [
                new OA\Property(property: 'current_page', type: 'integer', example: 1),
                new OA\Property(property: 'per_page', type: 'integer', example: 25),
                new OA\Property(property: 'total', type: 'integer', example: 142),
                new OA\Property(property: 'last_page', type: 'integer', example: 6),
            ],
        ),
    ],
    type: 'object',
)]

// ── Playlist schemas (Phase 3) ────────────────────────────────────────────────

#[OA\Schema(
    schema: 'PlaylistItemSong',
    description: 'Slim song shape embedded inside a playlist item.',
    required: ['id', 'title'],
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'title', type: 'string', example: 'Amazing Grace'),
        new OA\Property(property: 'author', type: 'string', nullable: true, example: 'John Newton'),
        new OA\Property(property: 'original_key', type: 'string', nullable: true, example: 'G'),
        new OA\Property(property: 'tempo', type: 'integer', nullable: true, example: 72),
        new OA\Property(property: 'time_signature', type: 'string', nullable: true, example: '4/4'),
        new OA\Property(property: 'deleted', type: 'boolean', example: false, description: 'True when the underlying song has been soft-deleted.'),
    ],
    type: 'object',
)]
#[OA\Schema(
    schema: 'PlaylistItem',
    description: 'A single song slot within a playlist.',
    required: ['id', 'song_id', 'position'],
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'song_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'position', type: 'integer', example: 0, description: 'Zero-based position within the playlist.'),
        new OA\Property(property: 'target_key', type: 'string', nullable: true, example: 'D', description: "Transpose target key for this performance. Overrides the song's original_key."),
        new OA\Property(property: 'notes', type: 'string', nullable: true, example: 'Start with just piano.'),
        new OA\Property(property: 'song', nullable: true, ref: '#/components/schemas/PlaylistItemSong'),
    ],
    type: 'object',
)]
#[OA\Schema(
    schema: 'PlaylistSummary',
    description: 'Lightweight playlist row returned by the list endpoint. Items are not hydrated.',
    required: ['id', 'name', 'tags', 'item_count', 'created_at', 'updated_at'],
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'name', type: 'string', example: 'Sunday Service — 27 April'),
        new OA\Property(property: 'event_date', type: 'string', format: 'date', nullable: true, example: '2026-04-27'),
        new OA\Property(property: 'tags', type: 'array', items: new OA\Items(type: 'string'), example: ['easter', 'morning']),
        new OA\Property(property: 'created_by', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'item_count', type: 'integer', example: 6),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
    ],
    type: 'object',
)]
#[OA\Schema(
    schema: 'PlaylistCollection',
    description: 'Paginated collection of playlist summaries.',
    required: ['data', 'meta'],
    properties: [
        new OA\Property(
            property: 'data',
            type: 'array',
            items: new OA\Items(ref: '#/components/schemas/PlaylistSummary'),
        ),
        new OA\Property(
            property: 'meta',
            type: 'object',
            required: ['current_page', 'per_page', 'total', 'last_page'],
            properties: [
                new OA\Property(property: 'current_page', type: 'integer', example: 1),
                new OA\Property(property: 'per_page', type: 'integer', example: 25),
                new OA\Property(property: 'total', type: 'integer', example: 12),
                new OA\Property(property: 'last_page', type: 'integer', example: 1),
            ],
        ),
    ],
    type: 'object',
)]
#[OA\Schema(
    schema: 'PlaylistResource',
    description: 'Full playlist with hydrated items. Returned by show, store, update, and duplicate.',
    required: ['id', 'name', 'tags', 'items', 'created_at', 'updated_at'],
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'name', type: 'string', example: 'Sunday Service — 27 April'),
        new OA\Property(property: 'event_date', type: 'string', format: 'date', nullable: true, example: '2026-04-27'),
        new OA\Property(property: 'tags', type: 'array', items: new OA\Items(type: 'string'), example: ['easter']),
        new OA\Property(property: 'created_by', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'duplicated_from_id', type: 'string', format: 'uuid', nullable: true, description: 'Source playlist UUID when this is a duplicate; null otherwise.'),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
        new OA\Property(
            property: 'items',
            type: 'array',
            items: new OA\Items(ref: '#/components/schemas/PlaylistItem'),
        ),
    ],
    type: 'object',
)]
#[OA\Schema(
    schema: 'PlaylistInput',
    description: 'Payload for creating or updating a playlist. `name` is required on create; on update any field may be omitted.',
    properties: [
        new OA\Property(property: 'name', type: 'string', example: 'Sunday Service — 27 April'),
        new OA\Property(property: 'event_date', type: 'string', format: 'date', nullable: true, example: '2026-04-27'),
        new OA\Property(property: 'tags', type: 'array', items: new OA\Items(type: 'string'), nullable: true, example: ['easter', 'morning']),
    ],
    type: 'object',
)]
#[OA\Schema(
    schema: 'DuplicatePlaylistInput',
    description: 'Optional body for the duplicate endpoint. Omit `name` to accept the default "{original} (copy)".',
    properties: [
        new OA\Property(property: 'name', type: 'string', example: 'Evening Service — 27 April'),
    ],
    type: 'object',
)]
#[OA\Schema(
    schema: 'PlaylistItemInput',
    description: 'Payload for adding a song to a playlist.',
    required: ['song_id'],
    properties: [
        new OA\Property(property: 'song_id', type: 'string', format: 'uuid', description: 'ID of the song to add.'),
        new OA\Property(property: 'position', type: 'integer', nullable: true, example: 2, description: 'Zero-based insertion index. Defaults to end of list; subsequent items shift down.'),
        new OA\Property(property: 'target_key', type: 'string', nullable: true, example: 'D', description: "Transpose target key (e.g. C, Am, F#). Overrides the song's original_key for this slot."),
        new OA\Property(property: 'notes', type: 'string', nullable: true, example: 'Start with just piano.'),
    ],
    type: 'object',
)]
#[OA\Schema(
    schema: 'PlaylistItemUpdateInput',
    description: 'Partial update for a playlist item. Only `target_key` and `notes` are editable after insertion.',
    properties: [
        new OA\Property(property: 'target_key', type: 'string', nullable: true, example: 'Am'),
        new OA\Property(property: 'notes', type: 'string', nullable: true, example: 'Capo 2.'),
    ],
    type: 'object',
)]
#[OA\Schema(
    schema: 'PlaylistReorderInput',
    description: 'Ordered array of every item ID in the playlist. The array index becomes the new zero-based position.',
    required: ['item_ids'],
    properties: [
        new OA\Property(
            property: 'item_ids',
            type: 'array',
            items: new OA\Items(type: 'string', format: 'uuid'),
            description: 'Every current item UUID exactly once, in the desired order.',
            example: ['uuid-a', 'uuid-b', 'uuid-c'],
        ),
    ],
    type: 'object',
)]
#[OA\Schema(
    schema: 'PlaylistItemCollection',
    description: 'List of playlist items returned after a reorder.',
    required: ['data'],
    properties: [
        new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/PlaylistItem')),
    ],
    type: 'object',
)]

// ── Share-link schemas (Phase 3) ──────────────────────────────────────────────

#[OA\Schema(
    schema: 'ShareLinkResource',
    description: 'A share link granting public read access to a playlist.',
    required: ['id', 'playlist_id', 'token', 'mode', 'created_at'],
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'playlist_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'token', type: 'string', example: 'AbCdEfGhIjKl', description: 'URL-safe token used in GET /share/{token}.'),
        new OA\Property(property: 'mode', type: 'string', enum: ['musician', 'projection'], example: 'musician'),
        new OA\Property(property: 'expires_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'revoked_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
    ],
    type: 'object',
)]
#[OA\Schema(
    schema: 'ShareLinkCollection',
    description: "List of a playlist's share links.",
    required: ['data'],
    properties: [
        new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/ShareLinkResource')),
    ],
    type: 'object',
)]
#[OA\Schema(
    schema: 'ShareLinkInput',
    required: ['mode'],
    properties: [
        new OA\Property(property: 'mode', type: 'string', enum: ['musician', 'projection'], example: 'musician', description: '`musician` — full lyrics included in the resolved payload. `projection` — lyrics included but `preview_url` is omitted.'),
        new OA\Property(property: 'expires_at', type: 'string', format: 'date-time', nullable: true, example: '2026-05-01T23:59:59Z', description: 'UTC expiry. Omit for a non-expiring link.'),
    ],
    type: 'object',
)]
#[OA\Schema(
    schema: 'SharedPlaylistItemSong',
    description: 'Song data in the public share-link payload. Always includes full ChordPro lyrics. `preview_url` is only present in `musician` mode.',
    required: ['id', 'title', 'lyrics'],
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'title', type: 'string', example: 'Amazing Grace'),
        new OA\Property(property: 'author', type: 'string', nullable: true, example: 'John Newton'),
        new OA\Property(property: 'lyrics', type: 'string', description: 'Full ChordPro source.'),
        new OA\Property(property: 'original_key', type: 'string', nullable: true, example: 'G'),
        new OA\Property(property: 'tempo', type: 'integer', nullable: true, example: 72),
        new OA\Property(property: 'time_signature', type: 'string', nullable: true, example: '3/4'),
        new OA\Property(property: 'preview_url', type: 'string', format: 'uri', nullable: true, description: 'Only present in `musician` mode.'),
    ],
    type: 'object',
)]
#[OA\Schema(
    schema: 'SharedPlaylistItem',
    required: ['id', 'position'],
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'position', type: 'integer', example: 0),
        new OA\Property(property: 'target_key', type: 'string', nullable: true, example: 'D'),
        new OA\Property(property: 'notes', type: 'string', nullable: true),
        new OA\Property(property: 'song', nullable: true, ref: '#/components/schemas/SharedPlaylistItemSong'),
    ],
    type: 'object',
)]
#[OA\Schema(
    schema: 'SharedPlaylistResponse',
    description: 'Payload returned by the public share-link resolution endpoint.',
    required: ['mode', 'playlist'],
    properties: [
        new OA\Property(property: 'mode', type: 'string', enum: ['musician', 'projection'], example: 'musician'),
        new OA\Property(
            property: 'playlist',
            type: 'object',
            required: ['id', 'name', 'tags', 'items'],
            properties: [
                new OA\Property(property: 'id', type: 'string', format: 'uuid'),
                new OA\Property(property: 'name', type: 'string', example: 'Sunday Service — 27 April'),
                new OA\Property(property: 'event_date', type: 'string', format: 'date', nullable: true, example: '2026-04-27'),
                new OA\Property(property: 'tags', type: 'array', items: new OA\Items(type: 'string')),
                new OA\Property(property: 'items', type: 'array', items: new OA\Items(ref: '#/components/schemas/SharedPlaylistItem')),
            ],
        ),
    ],
    type: 'object',
)]
class Schemas
{
}

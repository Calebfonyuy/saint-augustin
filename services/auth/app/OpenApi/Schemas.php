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
class Schemas {}

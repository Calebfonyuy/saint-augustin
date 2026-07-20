<?php

namespace App\Http\Controllers;

use App\Services\Staug\StaugArchiveReader;
use App\Services\Staug\StaugImporter;
use App\Services\Staug\StaugValidationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

/**
 * STAUG archive import (SRS FR-DI-1/2). Mirrors the VideoPsalm two-stage flow:
 *   1. POST /imports/staug with dry_run=true → preview (per-song create/skip/
 *      conflict actions), nothing written.
 *   2. POST /imports/staug (same file) → commit, non-destructive merge.
 *
 * The archive's HMAC signature is verified by the reader BEFORE anything else
 * happens; a forged, unsigned, or tampered archive is a 422 with no writes.
 */
#[OA\Tag(name: 'Imports', description: 'Bulk song imports from third-party worship software.')]
class StaugImportController
{
    public function __construct(
        private readonly StaugArchiveReader $reader,
        private readonly StaugImporter $importer,
    ) {
    }

    private function maxUploadBytes(): int
    {
        return (int) config('uploads.import_max_size_bytes', 50 * 1024 * 1024);
    }

    #[OA\Post(
        path: '/imports/staug',
        summary: 'Import (or preview) a signed STAUG archive',
        description: 'Accepts a multipart upload of a `.staug`/`.zip` archive. The manifest signature is verified first — an invalid or unsigned archive returns 422 without writing anything. With `dry_run=true` the response lists the per-song action (create/skip/conflict). Committing is strictly non-destructive: existing songs are matched by id + title and never overwritten. Requires the `admin` role.',
        tags: ['Imports'],
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType(
                mediaType: 'multipart/form-data',
                schema: new OA\Schema(
                    required: ['file'],
                    properties: [
                        new OA\Property(property: 'file', type: 'string', format: 'binary', description: 'The signed STAUG ZIP archive.'),
                        new OA\Property(property: 'dry_run', type: 'boolean', description: 'If true, only preview the per-song actions; nothing is persisted.', default: false),
                    ],
                ),
            ),
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Preview (dry run) — per-song action and per-songbook counts.',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'type', type: 'string', example: 'playlist'),
                        new OA\Property(property: 'songs', type: 'array', items: new OA\Items(type: 'object', properties: [
                            new OA\Property(property: 'id', type: 'string', nullable: true),
                            new OA\Property(property: 'title', type: 'string'),
                            new OA\Property(property: 'action', type: 'string', enum: ['create', 'skip', 'conflict']),
                        ])),
                        new OA\Property(property: 'songbooks', type: 'object', additionalProperties: new OA\AdditionalProperties(type: 'integer')),
                    ],
                    type: 'object',
                ),
            ),
            new OA\Response(
                response: 201,
                description: 'Import committed — created/skipped/conflicted counts.',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'created', type: 'integer'),
                        new OA\Property(property: 'skipped', type: 'integer'),
                        new OA\Property(property: 'conflicted', type: 'integer'),
                        new OA\Property(property: 'total', type: 'integer'),
                        new OA\Property(property: 'songbooks', type: 'array', items: new OA\Items(type: 'string')),
                    ],
                    type: 'object',
                ),
            ),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'Admin role required', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'File missing/too large, or the archive is invalid or unsigned', content: new OA\JsonContent(ref: '#/components/schemas/ValidationErrorResponse')),
        ],
    )]
    public function staug(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'file'    => ['required', 'file', 'max:'.intdiv($this->maxUploadBytes(), 1024)],
            'dry_run' => ['sometimes', 'boolean'],
        ]);

        $path = $request->file('file')->getRealPath();

        try {
            $archive = $this->reader->open($path);
        } catch (StaugValidationException $e) {
            return response()->json([
                'message' => 'Invalid or unsigned STAUG archive.',
                'errors'  => ['file' => [$e->getMessage()]],
            ], 422);
        }

        if ((bool) ($validated['dry_run'] ?? false)) {
            return response()->json($this->importer->preview($archive));
        }

        $result = $this->importer->import($archive, $request->user()?->id);

        return response()->json($result->toArray(), 201);
    }
}

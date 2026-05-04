<?php

namespace App\Http\Controllers;

use App\Services\VideoPsalm\VpagdImporter;
use App\Services\VideoPsalm\VpagdParser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

/**
 * Bulk song import endpoints.
 *
 * Currently only the VideoPsalm `.vpagd` format is wired up — that's the one
 * format the parish has bulk libraries in. The controller stays format-aware
 * (a `format` field, dispatching to the right parser) so OpenLP/ProPresenter
 * can be plugged in later without restructuring the URL space.
 *
 * Two-stage flow:
 *   1. POST /imports/videopsalm with `dry_run=true`  → preview list
 *   2. POST /imports/videopsalm with the same file
 *      and optional `guids[]` filter                  → real import
 *
 * Re-uploading the file on commit is deliberate. The alternative — caching
 * the parsed payload server-side keyed by upload id — adds storage and a
 * cleanup story for what is at most an ~11 MB blob used by an admin once or
 * twice a year.
 */
#[OA\Tag(
    name: 'Imports',
    description: 'Bulk song imports from third-party worship software.',
)]
class SongImportController
{
    /**
     * Cap on upload size in bytes. Sample VideoPsalm bundle is ~11 MB; the
     * default of 50 MB leaves headroom for larger libraries. The value
     * lives in config/uploads.php so it stays aligned with the PHP-level
     * `upload_max_filesize` directive (docker/php/uploads.ini). Bumping
     * one without the other will produce confusing 413/422 errors.
     */
    private function maxUploadBytes(): int
    {
        return (int) config('uploads.import_max_size_bytes', 50 * 1024 * 1024);
    }

    #[OA\Post(
        path: '/imports/videopsalm',
        summary: 'Import (or preview) songs from a VideoPsalm `.vpagd` archive',
        description: 'Accepts a multipart upload of a `.vpagd` file. With `dry_run=true` the parser returns the song list without writing anything; without it (or with `dry_run=false`), the songs are persisted and the response reports `created` / `skipped` counts. Songs are de-duplicated by case-insensitive (title, songbook) — re-running a previous import skips existing rows rather than overwriting hand-edits. Requires the `admin` role.',
        tags: ['Imports'],
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType(
                mediaType: 'multipart/form-data',
                schema: new OA\Schema(
                    required: ['file'],
                    properties: [
                        new OA\Property(property: 'file', type: 'string', format: 'binary', description: 'The `.vpagd` archive (a ZIP of Song_*.json files).'),
                        new OA\Property(property: 'dry_run', type: 'boolean', description: 'If true, only parse and return preview rows; nothing is persisted.', default: false),
                        new OA\Property(property: 'guids', type: 'array', items: new OA\Items(type: 'string'), description: 'Optional Guid filter — only songs whose Guid is in the list are imported.'),
                    ],
                ),
            ),
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Preview (dry run) — list of songs that would be imported, plus a per-songbook count.',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'songs', type: 'array', items: new OA\Items(type: 'object')),
                        new OA\Property(property: 'songbooks', type: 'object', additionalProperties: new OA\AdditionalProperties(type: 'integer')),
                    ],
                    type: 'object',
                ),
            ),
            new OA\Response(
                response: 201,
                description: 'Import committed — counts of created/skipped songs.',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'created',   type: 'integer'),
                        new OA\Property(property: 'skipped',   type: 'integer'),
                        new OA\Property(property: 'total',     type: 'integer'),
                        new OA\Property(property: 'songbooks', type: 'array', items: new OA\Items(type: 'string')),
                    ],
                    type: 'object',
                ),
            ),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'Admin role required', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'File missing, too large, or unparseable', content: new OA\JsonContent(ref: '#/components/schemas/ValidationErrorResponse')),
        ],
    )]
    public function videopsalm(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'file'     => ['required', 'file', 'max:' . intdiv($this->maxUploadBytes(), 1024)],
            'dry_run'  => ['sometimes', 'boolean'],
            'guids'    => ['sometimes', 'array'],
            'guids.*'  => ['string'],
        ]);

        $upload = $request->file('file');
        $path   = $upload->getRealPath();

        try {
            $parsed = VpagdParser::parseFile($path);
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Could not parse VideoPsalm archive.',
                'errors'  => ['file' => [$e->getMessage()]],
            ], 422);
        }

        $dryRun = (bool) ($validated['dry_run'] ?? false);

        if ($dryRun) {
            // Preview shape — keep it small (no full lyrics) so the admin
            // can scan a few hundred rows in the UI without the JSON
            // ballooning. The frontend re-uploads to commit, so we don't
            // need to send the full body.
            $songbookCounts = [];
            $rows = [];
            foreach ($parsed as $p) {
                $sb = $p->songbookName ?? 'VideoPsalm Import';
                $songbookCounts[$sb] = ($songbookCounts[$sb] ?? 0) + 1;
                $rows[] = [
                    'guid'        => $p->guid,
                    'title'       => $p->title,
                    'songbook'    => $sb,
                    'verse_count' => count($p->verses),
                ];
            }
            return response()->json([
                'songs'     => $rows,
                'songbooks' => $songbookCounts,
            ]);
        }

        $importer = new VpagdImporter();
        $result = $importer->import(
            songs: $parsed,
            createdBy: $request->user()?->id,
            onlyGuids: isset($validated['guids']) ? array_values($validated['guids']) : null,
        );

        return response()->json($result->toArray(), 201);
    }
}

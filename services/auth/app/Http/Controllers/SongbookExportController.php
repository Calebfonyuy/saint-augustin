<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\SanitizesFilename;
use App\Models\Songbook;
use App\Services\Staug\StaugArchiveWriter;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Songbook export (SRS FR-AB-1). Produces a signed STAUG archive of a
 * songbook and all its songs. STAUG is the only format for now (a
 * human-readable pdf/txt mode can mirror playlists later).
 */
#[OA\Tag(name: 'Songbooks', description: 'Songbook CRUD and export.')]
class SongbookExportController
{
    use SanitizesFilename;

    public function __construct(private readonly StaugArchiveWriter $writer)
    {
    }

    #[OA\Get(
        path: '/songbooks/{id}/export',
        summary: 'Export a songbook as a STAUG archive',
        description: 'Downloads a signed STAUG ZIP containing the songbook and every song in it. Any authenticated user may export a songbook they can see.',
        tags: ['Songbooks'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'format', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['staug'], default: 'staug')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'STAUG archive download', content: new OA\MediaType(mediaType: 'application/zip', schema: new OA\Schema(type: 'string', format: 'binary'))),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 404, description: 'Songbook not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'Unsupported format', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function __invoke(Request $request, string $id): HttpResponse
    {
        $songbook = Songbook::find($id);

        if (! $songbook) {
            return response()->json(['message' => 'Songbook not found.'], 404);
        }

        $format = strtolower((string) $request->query('format', 'staug'));
        if ($format !== 'staug') {
            return response()->json(['message' => 'Unsupported format. Use staug.'], 422);
        }

        $path = $this->writer->writeSongbook($songbook);
        $filename = $this->safeFilename($songbook->name).'.staug.zip';

        return response()->download($path, $filename, ['Content-Type' => 'application/zip'])
            ->deleteFileAfterSend();
    }
}

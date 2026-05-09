<?php

namespace App\Http\Controllers;

use App\Models\Song;
use App\Models\SongSheet;
use Illuminate\Contracts\Filesystem\Cloud;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use OpenApi\Attributes as OA;

/**
 * Song sheet attachments — File Service endpoints (SRS FR5, Phase 2).
 *
 * Files (PDF + images) are stored on the `minio` disk via Flysystem's
 * S3 driver. The download URL is generated on demand as a short-lived
 * presigned S3 URL so we never hand out the bucket credentials, and the
 * URL stops working after SONG_SHEET_URL_TTL minutes.
 *
 * Authorization (FR5 + SRS 2.2):
 *   - List/View    — any authenticated user (musicians need to read sheets)
 *   - Upload       — Admin or Musician (same rule as song create/update)
 *   - Delete       — Admin only
 */
#[OA\Tag(
    name: 'SongSheets',
    description: 'PDF and image attachments on songs (lead sheets, choir parts).',
)]
class SongSheetController
{
    /**
     * Hard cap on upload size (SRS implies a reasonable limit; PDFs of full
     * songs rarely exceed this). The actual value lives in config/uploads.php
     * so it stays in sync with the PHP-level `upload_max_filesize` directive
     * (docker/php/uploads.ini). The default below is the safety net for
     * tests / config:clear scenarios where the config isn't bound yet.
     */
    private function maxUploadBytes(): int
    {
        return (int) config('uploads.sheet_max_size_bytes', 10 * 1024 * 1024);
    }

    /** Mime allow-list. Anything outside this is rejected at validation time. */
    private const ALLOWED_MIME_TYPES = [
        'application/pdf' => SongSheet::TYPE_PDF,
        'image/png'       => SongSheet::TYPE_IMAGE,
        'image/jpeg'      => SongSheet::TYPE_IMAGE,
        'image/webp'      => SongSheet::TYPE_IMAGE,
    ];

    // ── List sheets for a song ────────────────────────────────────────

    #[OA\Get(
        path: '/songs/{songId}/sheets',
        summary: "List a song's sheets",
        description: 'Returns metadata for every sheet attached to the given song. Each entry includes a short-lived presigned `url` for inline viewing/download.',
        tags: ['SongSheets'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'songId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'List of sheets',
                content: new OA\JsonContent(
                    type: 'array',
                    items: new OA\Items(ref: '#/components/schemas/SongSheetResource'),
                ),
            ),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 404, description: 'Song not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function index(string $songId): JsonResponse
    {
        if (! Song::where('id', $songId)->exists()) {
            return response()->json(['message' => 'Song not found.'], 404);
        }

        $sheets = SongSheet::where('song_id', $songId)
            ->orderByDesc('created_at')
            ->get();

        return response()->json([
            'data' => $sheets->map(fn (SongSheet $s) => $this->formatSheet($s))->all(),
        ]);
    }

    // ── Upload a sheet ────────────────────────────────────────────────

    #[OA\Post(
        path: '/songs/{songId}/sheets',
        summary: 'Upload a song sheet',
        description: 'Uploads a PDF or image (PNG/JPEG/WebP) and attaches it to the song. Multipart form-data; the file goes in the `file` field. Max 10 MiB.',
        tags: ['SongSheets'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'songId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType(
                mediaType: 'multipart/form-data',
                schema: new OA\Schema(
                    required: ['file'],
                    properties: [
                        new OA\Property(property: 'file', type: 'string', format: 'binary'),
                    ],
                ),
            ),
        ),
        responses: [
            new OA\Response(response: 201, description: 'Sheet uploaded', content: new OA\JsonContent(ref: '#/components/schemas/SongSheetResource')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'Role not permitted', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 404, description: 'Song not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ValidationErrorResponse')),
        ],
    )]
    public function store(Request $request, string $songId): JsonResponse
    {
        if ($forbidden = $this->requireUploaderRole($request)) {
            return $forbidden;
        }

        $song = Song::find($songId);

        if (! $song) {
            return response()->json(['message' => 'Song not found.'], 404);
        }

        $allowedMimes = array_keys(self::ALLOWED_MIME_TYPES);

        $request->validate([
            'file' => [
                'required',
                'file',
                'max:'.intdiv($this->maxUploadBytes(), 1024), // Laravel "max" is in KiB
                'mimetypes:'.implode(',', $allowedMimes),
            ],
        ]);

        $upload = $request->file('file');
        $mime = $upload->getClientMimeType();
        $type = self::ALLOWED_MIME_TYPES[$mime] ?? null;

        if ($type === null) {
            // Belt-and-braces: should be caught by `mimetypes` rule above.
            return response()->json(['message' => 'Unsupported file type.'], 422);
        }

        $extension = strtolower($upload->getClientOriginalExtension() ?: $upload->extension());
        $key = sprintf('song-sheets/%s/%s.%s', $song->id, (string) Str::uuid(), $extension);

        // Stream the upload into MinIO. Storage::putFileAs returns the path on success.
        Storage::disk('minio')->putFileAs(
            dirname($key),
            $upload,
            basename($key),
            ['ContentType' => $mime],
        );

        $sheet = SongSheet::create([
            'song_id'           => $song->id,
            'original_filename' => $upload->getClientOriginalName(),
            'storage_disk'      => 'minio',
            'storage_path'      => $key,
            'file_type'         => $type,
            'mime_type'         => $mime,
            'size_bytes'        => $upload->getSize() ?: 0,
            'uploaded_by'       => $request->user()->id,
        ]);

        return response()->json($this->formatSheet($sheet), 201);
    }

    // ── Show a single sheet (with a fresh presigned URL) ──────────────

    #[OA\Get(
        path: '/sheets/{id}',
        summary: 'Fetch sheet metadata + presigned URL',
        description: 'Returns metadata for a single sheet plus a fresh presigned `url`. Use this when the URL on a previously fetched record has expired.',
        tags: ['SongSheets'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Sheet metadata', content: new OA\JsonContent(ref: '#/components/schemas/SongSheetResource')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 404, description: 'Sheet not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function show(string $id): JsonResponse
    {
        $sheet = SongSheet::find($id);

        if (! $sheet) {
            return response()->json(['message' => 'Sheet not found.'], 404);
        }

        return response()->json($this->formatSheet($sheet));
    }

    // ── Delete a sheet (admin only) ───────────────────────────────────

    #[OA\Delete(
        path: '/sheets/{id}',
        summary: 'Delete a sheet (Admin)',
        description: 'Hard-deletes the sheet record and removes the underlying file from object storage. Requires the `admin` role.',
        tags: ['SongSheets'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 204, description: 'Sheet deleted'),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'Admin role required', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 404, description: 'Sheet not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function destroy(string $id): JsonResponse
    {
        $sheet = SongSheet::find($id);

        if (! $sheet) {
            return response()->json(['message' => 'Sheet not found.'], 404);
        }

        // Best-effort delete on the storage backend. We don't fail the API
        // call if the object is already gone — the row should still be
        // removed.
        try {
            Storage::disk($sheet->storage_disk)->delete($sheet->storage_path);
        } catch (\Throwable $e) {
            report($e);
        }

        $sheet->delete();

        return response()->json(null, 204);
    }

    // ── Private helpers ───────────────────────────────────────────────

    private function requireUploaderRole(Request $request): ?JsonResponse
    {
        $user = $request->user();

        if (! $user || (! $user->isAdmin() && ! $user->isMusician())) {
            return response()->json([
                'message' => 'Forbidden. Admin or Musician role required.',
            ], 403);
        }

        return null;
    }

    /**
     * Build the response payload for a sheet, including a freshly minted
     * presigned URL that's valid for SONG_SHEET_URL_TTL minutes.
     *
     * @return array<string, mixed>
     */
    private function formatSheet(SongSheet $sheet): array
    {
        $disk = Storage::disk($sheet->storage_disk);
        $ttlMinutes = (int) config('filesystems.song_sheet_url_ttl', env('SONG_SHEET_URL_TTL', 15));

        // Only S3-style cloud disks support temporaryUrl. The `local` disk
        // (used in feature tests via Storage::fake()) returns a regular
        // public URL via ->url(), which is fine for assertions.
        $url = $disk instanceof Cloud
            ? $disk->temporaryUrl($sheet->storage_path, now()->addMinutes($ttlMinutes))
            : $disk->url($sheet->storage_path);

        return [
            'id'                => $sheet->id,
            'song_id'           => $sheet->song_id,
            'original_filename' => $sheet->original_filename,
            'file_type'         => $sheet->file_type,
            'mime_type'         => $sheet->mime_type,
            'size_bytes'        => $sheet->size_bytes,
            'uploaded_by'       => $sheet->uploaded_by,
            'url'               => $url,
            'url_expires_at'    => $disk instanceof Cloud
                ? now()->addMinutes($ttlMinutes)->toIso8601String()
                : null,
            'created_at'        => $sheet->created_at?->toIso8601String(),
            'updated_at'        => $sheet->updated_at?->toIso8601String(),
        ];
    }
}

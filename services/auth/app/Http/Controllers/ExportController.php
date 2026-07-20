<?php

namespace App\Http\Controllers;

use App\Jobs\FullExportJob;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use OpenApi\Attributes as OA;

/**
 * Full-library export (SRS FR-DF-1). Admin-only. Enforces one export at a
 * time via an atomic cache marker: the first request sets it and queues the
 * job; concurrent requests get a 409 until the job clears it (or the marker's
 * TTL lapses). The heavy lifting happens in FullExportJob on the queue.
 */
#[OA\Tag(name: 'Exports', description: 'Bulk library exports.')]
class ExportController
{
    #[OA\Post(
        path: '/exports/full',
        summary: 'Queue a full-library STAUG export',
        description: 'Queues a background job that builds a signed STAUG archive of every song and songbook and emails the requesting admin a 48h presigned download link. Only one full export may run at a time — a concurrent request returns 409. Requires the `admin` role.',
        tags: ['Exports'],
        security: [['sanctum' => []]],
        responses: [
            new OA\Response(response: 202, description: 'Export queued', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'message', type: 'string', example: 'Export queued.'),
                new OA\Property(property: 'status', type: 'string', example: 'queued'),
            ], type: 'object')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'Admin role required', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 409, description: 'An export is already in progress', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function full(Request $request): JsonResponse
    {
        $lockKey = (string) config('staug.full_export_lock_key');
        $ttl = now()->addMinutes((int) config('staug.full_export_lock_ttl_minutes', 30));

        $acquired = Cache::add($lockKey, [
            'by' => $request->user()?->id,
            'at' => now()->toIso8601String(),
        ], $ttl);

        if (! $acquired) {
            return response()->json(['message' => 'An export is already in progress.'], 409);
        }

        FullExportJob::dispatch((string) $request->user()->id);

        return response()->json(['message' => 'Export queued.', 'status' => 'queued'], 202);
    }
}

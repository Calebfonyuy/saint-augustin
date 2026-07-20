<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use OpenApi\Attributes as OA;

/**
 * Tag suggestions (FR-PL-1).
 *
 * Returns the distinct union of every tag used across songs and playlists,
 * so the shared create-playlist modal and the song editor can offer one
 * autocomplete list drawn from the whole library.
 *
 * Because songs and playlists share the single API database, this is one
 * query — `jsonb_array_elements_text` unnests each JSONB `tags` array and a
 * UNION deduplicates across the two tables. Soft-deleted songs are excluded
 * so tags that only survive on a trashed song don't linger in suggestions.
 */
#[OA\Tag(
    name: 'Tags',
    description: 'Cross-entity tag suggestions for autocomplete.',
)]
class TagController
{
    #[OA\Get(
        path: '/tags',
        summary: 'List all tags in use',
        description: 'Returns the sorted, distinct union of tags used on songs and playlists. Soft-deleted songs are excluded. Any authenticated user may call this; it backs the tag autocomplete on playlist and song forms.',
        tags: ['Tags'],
        security: [['sanctum' => []]],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Distinct tag list',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(
                            property: 'data',
                            type: 'array',
                            items: new OA\Items(type: 'string'),
                            example: ['advent', 'communion', 'easter'],
                        ),
                    ],
                    type: 'object',
                ),
            ),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function index(): JsonResponse
    {
        $songTags = DB::table('songs')
            ->whereNull('deleted_at')
            ->selectRaw('jsonb_array_elements_text(tags) as tag');

        $tags = DB::table('playlists')
            ->selectRaw('jsonb_array_elements_text(tags) as tag')
            ->union($songTags)
            ->distinct()
            ->orderBy('tag')
            ->pluck('tag');

        return response()->json(['data' => $tags->all()]);
    }
}

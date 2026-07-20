<?php

namespace App\Http\Controllers;

use App\Models\BibleBook;
use App\Models\BibleSetting;
use App\Services\Bible\BibleApiException;
use App\Services\Bible\BibleReferenceException;
use App\Services\Bible\ReferenceParser;
use App\Services\Bible\ScriptureReference;
use App\Services\Bible\ScriptureResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

/**
 * Reader-facing Bible endpoints (SRS FR-BI-3/4/6). Any authenticated user may
 * read settings, list a translation's books (for the picker), and resolve a
 * reference to verses. Resolving doubles as the projection pre-fetch — it
 * warms the Redis chapter cache. Scripture text is never persisted.
 */
#[OA\Tag(name: 'Bible', description: 'Bible translations, books, and reference resolution.')]
class BibleController
{
    public function __construct(
        private readonly ReferenceParser $parser,
        private readonly ScriptureResolver $resolver,
    ) {
    }

    #[OA\Get(
        path: '/bible/settings',
        summary: 'Get the workspace Bible settings',
        description: 'Returns the enabled translations and the default translation id. Any authenticated user.',
        tags: ['Bible'],
        security: [['sanctum' => []]],
        responses: [new OA\Response(response: 200, description: 'Settings')],
    )]
    public function settings(): JsonResponse
    {
        $settings = BibleSetting::current();

        return response()->json([
            'enabled_translations'   => $settings->enabled_translations,
            'default_translation_id' => $settings->default_translation_id,
        ]);
    }

    #[OA\Get(
        path: '/bible/books',
        summary: "List a translation's books (from the structure cache)",
        description: 'Returns the localized book names + chapter counts for the picker. `translation` defaults to the workspace default.',
        tags: ['Bible'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'translation', in: 'query', required: false, schema: new OA\Schema(type: 'string'))],
        responses: [new OA\Response(response: 200, description: 'Book list'), new OA\Response(response: 422, description: 'No translation')],
    )]
    public function books(Request $request): JsonResponse
    {
        $translationId = $request->query('translation')
            ?: BibleSetting::current()->default_translation_id;

        if (! $translationId) {
            return response()->json(['message' => 'No Bible translation is configured.'], 422);
        }

        $books = BibleBook::query()
            ->where('translation_id', $translationId)
            ->orderBy('order')
            ->orderBy('name')
            ->get(['book_code', 'name', 'chapter_count', 'order'])
            ->map(fn (BibleBook $b) => [
                'book_code'     => $b->book_code,
                'name'          => $b->name,
                'chapter_count' => $b->chapter_count,
            ])
            ->all();

        return response()->json(['translation_id' => $translationId, 'books' => $books]);
    }

    #[OA\Get(
        path: '/bible/resolve',
        summary: 'Resolve a scripture reference to its verses',
        description: 'Accepts either a freeform reference (`q`, e.g. "Jean 3:16-4:2") or discrete params (`book_code`, `start_chapter`, `start_verse`, and optional `end_chapter`/`end_verse`). `translation_id` defaults to the workspace default. Returns the localized label, translation label, and the verse list. Warms the Redis chapter cache (projection pre-fetch).',
        tags: ['Bible'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'q', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'translation_id', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'book_code', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'start_chapter', in: 'query', required: false, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'start_verse', in: 'query', required: false, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Resolved verses'),
            new OA\Response(response: 422, description: 'Unparseable/invalid reference or no translation'),
            new OA\Response(response: 502, description: 'Bible API unavailable'),
        ],
    )]
    public function resolve(Request $request): JsonResponse
    {
        $v = $request->validate([
            'q'              => ['sometimes', 'string', 'max:120'],
            'translation_id' => ['sometimes', 'nullable', 'string', 'max:32'],
            'book_code'      => ['sometimes', 'string', 'max:8'],
            'start_chapter'  => ['sometimes', 'integer', 'min:1'],
            'start_verse'    => ['sometimes', 'integer', 'min:1'],
            'end_chapter'    => ['sometimes', 'nullable', 'integer', 'min:1'],
            'end_verse'      => ['sometimes', 'nullable', 'integer', 'min:1'],
        ]);

        $settings = BibleSetting::current();
        $translationId = ($v['translation_id'] ?? null) ?: $settings->default_translation_id;

        if (! $translationId) {
            return response()->json(['message' => 'No Bible translation is configured.'], 422);
        }

        try {
            $ref = $this->buildReference($v);
        } catch (BibleReferenceException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        try {
            $resolved = $this->resolver->resolve($ref, $translationId, $this->translationLabel($settings, $translationId));
        } catch (BibleReferenceException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (BibleApiException $e) {
            return response()->json(['message' => 'The Bible service is currently unavailable.'], 502);
        }

        return response()->json($resolved->toArray());
    }

    /**
     * @param  array<string, mixed>  $v
     */
    private function buildReference(array $v): ScriptureReference
    {
        if (! empty($v['q'])) {
            return $this->parser->parse($v['q']);
        }

        if (empty($v['book_code']) || ! isset($v['start_chapter'], $v['start_verse'])) {
            throw new BibleReferenceException('Provide either a freeform reference (q) or book_code + start_chapter + start_verse.');
        }

        $startChapter = (int) $v['start_chapter'];
        $startVerse = (int) $v['start_verse'];
        $endChapter = isset($v['end_chapter']) ? (int) $v['end_chapter'] : $startChapter;
        $endVerse = isset($v['end_verse']) ? (int) $v['end_verse'] : $startVerse;

        return new ScriptureReference($v['book_code'], $startChapter, $startVerse, $endChapter, $endVerse);
    }

    private function translationLabel(BibleSetting $settings, string $translationId): ?string
    {
        foreach ($settings->enabled_translations as $t) {
            if (($t['id'] ?? null) === $translationId && is_string($t['name'] ?? null)) {
                return $t['name'];
            }
        }

        return null;
    }
}

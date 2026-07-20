<?php

namespace App\Http\Controllers;

use App\Models\BibleBook;
use App\Models\BibleSetting;
use App\Services\Bible\BibleApiException;
use App\Services\Bible\HelloAoClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

/**
 * Admin Bible settings (SRS FR-BI-1). Lists the HelloAO translations offered
 * in the configured languages and persists which ones the workspace has
 * enabled + the default. Saving also (re)populates the structure-only
 * `bible_books` cache (FR-BI-2) from each enabled translation's books.json.
 */
#[OA\Tag(name: 'Bible', description: 'Bible translations, books, and reference resolution.')]
class BibleSettingsController
{
    public function __construct(private readonly HelloAoClient $client)
    {
    }

    #[OA\Get(
        path: '/bible/translations/available',
        summary: 'List available Bible translations (admin)',
        description: 'Proxies HelloAO available_translations, filtered to the configured languages (incl. French). Requires the `admin` role.',
        tags: ['Bible'],
        security: [['sanctum' => []]],
        responses: [new OA\Response(response: 200, description: 'Available translations'), new OA\Response(response: 502, description: 'Bible API unavailable')],
    )]
    public function available(): JsonResponse
    {
        /** @var list<string> $languages */
        $languages = config('bible.languages', ['fra', 'eng']);

        try {
            $all = $this->client->availableTranslations();
        } catch (BibleApiException $e) {
            return response()->json(['message' => 'The Bible service is currently unavailable.'], 502);
        }

        $translations = [];
        foreach ($all as $t) {
            if (! in_array($t['language'] ?? null, $languages, true)) {
                continue;
            }
            $translations[] = [
                'id'            => $t['id'] ?? null,
                'name'          => $t['name'] ?? ($t['englishName'] ?? ($t['id'] ?? '')),
                'english_name'  => $t['englishName'] ?? null,
                'language'      => $t['language'],
                'language_name' => $t['languageEnglishName'] ?? ($t['languageName'] ?? null),
            ];
        }

        return response()->json(['translations' => $translations]);
    }

    #[OA\Put(
        path: '/bible/settings',
        summary: 'Save the workspace Bible settings (admin)',
        description: 'Persists the enabled translations + default and repopulates the book-structure cache. Requires the `admin` role.',
        tags: ['Bible'],
        security: [['sanctum' => []]],
        responses: [
            new OA\Response(response: 200, description: 'Saved'),
            new OA\Response(response: 422, description: 'Validation error'),
            new OA\Response(response: 502, description: 'Bible API unavailable'),
        ],
    )]
    public function update(Request $request): JsonResponse
    {
        $v = $request->validate([
            'enabled_translations'            => ['present', 'array'],
            'enabled_translations.*.id'       => ['required', 'string', 'max:32'],
            'enabled_translations.*.name'     => ['required', 'string', 'max:255'],
            'enabled_translations.*.language' => ['sometimes', 'nullable', 'string', 'max:16'],
            'default_translation_id'          => ['nullable', 'string', 'max:32'],
        ]);

        $enabled = array_map(fn ($t) => [
            'id'       => $t['id'],
            'name'     => $t['name'],
            'language' => $t['language'] ?? null,
        ], $v['enabled_translations']);
        $enabledIds = array_column($enabled, 'id');

        $default = $v['default_translation_id'] ?? null;
        if ($default !== null && ! in_array($default, $enabledIds, true)) {
            return response()->json([
                'message' => 'The default translation must be one of the enabled translations.',
                'errors'  => ['default_translation_id' => ['Not among the enabled translations.']],
            ], 422);
        }
        if ($default === null && $enabledIds !== []) {
            $default = $enabledIds[0];
        }

        // Populate the structure cache before persisting so a mid-way API
        // failure leaves the saved settings untouched.
        try {
            foreach ($enabledIds as $translationId) {
                $this->syncBooks($translationId);
            }
        } catch (BibleApiException $e) {
            return response()->json(['message' => 'The Bible service is currently unavailable.'], 502);
        }

        // Drop cached books for translations no longer enabled.
        BibleBook::query()
            ->whereNotIn('translation_id', $enabledIds === [] ? ['__none__'] : $enabledIds)
            ->delete();

        $settings = BibleSetting::current();
        $settings->fill([
            'enabled_translations'   => $enabled,
            'default_translation_id' => $default,
        ])->save();

        return response()->json([
            'enabled_translations'   => $settings->enabled_translations,
            'default_translation_id' => $settings->default_translation_id,
        ]);
    }

    private function syncBooks(string $translationId): void
    {
        foreach ($this->client->books($translationId) as $book) {
            $code = $book['id'] ?? null;
            if (! is_string($code) || $code === '') {
                continue;
            }

            BibleBook::query()->updateOrCreate(
                ['translation_id' => $translationId, 'book_code' => $code],
                [
                    'name'          => (string) ($book['name'] ?? ($book['commonName'] ?? $code)),
                    'chapter_count' => (int) ($book['numberOfChapters'] ?? 0),
                    'order'         => (int) ($book['order'] ?? 0),
                ],
            );
        }
    }
}

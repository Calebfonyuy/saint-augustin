<?php

namespace App\Services\Bible;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Thin wrapper over the HelloAO Free Use Bible API (SRS FR-BI, external
 * dependency). Fetched chapters are cached in Redis — this is the resilience
 * layer (FR-BI-6): a pre-fetched chapter keeps projecting even if the network
 * drops mid-service. Verse *text* lives only in the cache, never in Postgres.
 *
 * Endpoints:
 *   /available_translations.json
 *   /{translation}/books.json
 *   /{translation}/{BOOK}/{chapter}.json
 */
class HelloAoClient
{
    /**
     * @return list<array<string, mixed>> the raw translation records
     */
    public function availableTranslations(): array
    {
        $data = $this->get('/available_translations.json');
        $translations = $data['translations'] ?? null;

        return is_array($translations) ? array_values($translations) : [];
    }

    /**
     * @return list<array<string, mixed>> the raw book records (id=USFM, name, numberOfChapters, order)
     */
    public function books(string $translationId): array
    {
        $data = $this->get("/{$translationId}/books.json");
        $books = $data['books'] ?? null;

        return is_array($books) ? array_values($books) : [];
    }

    /**
     * Verses of one chapter, normalized to `[['number'=>int,'text'=>string], …]`
     * and cached in Redis. Returns them sorted by verse number.
     *
     * @return list<array{number: int, text: string}>
     */
    public function chapter(string $translationId, string $bookCode, int $chapter): array
    {
        $ttl = now()->addMinutes((int) config('bible.chapter_cache_ttl_minutes', 1440));

        return Cache::remember(
            "bible:ch:{$translationId}:{$bookCode}:{$chapter}",
            $ttl,
            fn () => $this->extractVerses($this->get("/{$translationId}/{$bookCode}/{$chapter}.json")),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function get(string $path): array
    {
        try {
            $response = Http::baseUrl($this->baseUrl())
                ->timeout((int) config('bible.http_timeout_seconds', 8))
                ->retry((int) config('bible.http_retries', 2), 200, throw: false)
                ->acceptJson()
                ->get($path);
        } catch (Throwable $e) {
            throw new BibleApiException("Bible API request failed for {$path}.", 0, $e);
        }

        if (! $response->successful()) {
            throw new BibleApiException("Bible API returned HTTP {$response->status()} for {$path}.");
        }

        $data = $response->json();
        if (! is_array($data)) {
            throw new BibleApiException("Bible API returned a non-JSON body for {$path}.");
        }

        return $data;
    }

    private function baseUrl(): string
    {
        return rtrim((string) config('bible.api_base'), '/');
    }

    /**
     * Pull verse text out of HelloAO's chapter content. `chapter.content` is a
     * flat list of blocks; we keep only `type:"verse"` blocks and flatten each
     * verse's own content array (strings + word objects), dropping footnotes.
     *
     * @param  array<string, mixed>  $chapterData
     * @return list<array{number: int, text: string}>
     */
    private function extractVerses(array $chapterData): array
    {
        $content = $chapterData['chapter']['content'] ?? [];
        if (! is_array($content)) {
            return [];
        }

        $verses = [];
        foreach ($content as $node) {
            if (! is_array($node) || ($node['type'] ?? null) !== 'verse') {
                continue;
            }
            $number = (int) ($node['number'] ?? 0);
            if ($number < 1) {
                continue;
            }
            $verses[] = [
                'number' => $number,
                'text'   => $this->flatten($node['content'] ?? []),
            ];
        }

        return $verses;
    }

    /**
     * Recursively concatenate the string fragments of a content array,
     * skipping footnote/note markers. Collapses whitespace.
     */
    private function flatten(mixed $content): string
    {
        if (is_string($content)) {
            return $content;
        }
        if (! is_array($content)) {
            return '';
        }

        $parts = [];
        foreach ($content as $item) {
            if (is_string($item)) {
                $parts[] = $item;
            } elseif (is_array($item)) {
                if (isset($item['text']) && is_string($item['text'])) {
                    $parts[] = $item['text'];
                } elseif (isset($item['content'])) {
                    $parts[] = $this->flatten($item['content']);
                }
                // Objects carrying only a noteId/footnote reference are skipped.
            }
        }

        return trim(preg_replace('/\s+/', ' ', implode(' ', $parts)) ?? '');
    }
}

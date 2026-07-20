<?php

/*
 * SaintAugustin — Bible module configuration (SRS FR-BI).
 *
 * Scripture text is fetched on demand from the HelloAO Free Use Bible API
 * (public domain, no keys, no rate limits) and cached in Redis; it is never
 * persisted in Postgres. Values are read via config('bible.*') so
 * `php artisan config:cache` snapshots them (mirrors config/staug.php).
 */

return [

    /*
     * Base URL of the HelloAO Free Use Bible API. Endpoints used:
     *   {base}/available_translations.json
     *   {base}/{translation}/books.json
     *   {base}/{translation}/{BOOK}/{chapter}.json
     */
    'api_base' => env('BIBLE_API_BASE', 'https://bible.helloao.org/api'),

    /*
     * How long a fetched chapter stays in the Redis cache. Public-domain
     * scripture is immutable, so a long TTL is safe; the point of the cache
     * is resilience — a pre-fetched chapter keeps projecting even if the
     * network drops mid-service (FR-BI-6).
     */
    'chapter_cache_ttl_minutes' => (int) env('BIBLE_CHAPTER_CACHE_TTL_MINUTES', 1440),

    /*
     * Languages offered in the admin translation picker (HelloAO uses
     * ISO-639-3 codes). French is required by the SRS.
     */
    'languages' => ['fra', 'eng'],

    /*
     * Outbound HTTP timeout (seconds) and retry count for HelloAO calls.
     */
    'http_timeout_seconds' => (int) env('BIBLE_HTTP_TIMEOUT_SECONDS', 8),
    'http_retries'         => (int) env('BIBLE_HTTP_RETRIES', 2),

];

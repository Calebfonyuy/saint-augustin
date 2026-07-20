# Bible module

The Bible module (SRS FR-BI, Stage 7) lets a worship team enable Bible **translations**, add **scripture readings** to playlists by reference, and project them. It lives inside `services/auth` (per ADR-0001, no new deployable) with a small frontend and projection-rendering slice.

## External dependency: HelloAO

Scripture text comes from the [HelloAO Free Use Bible API](https://bible.helloao.org/docs/) — public domain, no keys, no rate limits. Three endpoints are used (base = `config('bible.api_base')`, env `BIBLE_API_BASE`):

- `GET /available_translations.json` — the translation catalogue.
- `GET /{translation}/books.json` — a translation's books (USFM id, localized name, chapter count).
- `GET /{translation}/{BOOK}/{chapter}.json` — a chapter's verses.

Everything is wrapped by `App\Services\Bible\HelloAoClient`.

## What is stored vs. fetched

- **Persisted (Postgres):** `bible_settings` (a single row — enabled translations + default) and `bible_books` (a **structure-only cache**: localized book names + chapter counts per enabled translation, FR-BI-2). No verse text.
- **Never persisted:** scripture **text**. Chapters are fetched on demand and cached in **Redis** (`Cache::remember`, TTL `config('bible.chapter_cache_ttl_minutes')`, default 24h). Playlist scripture items store only the reference (`translation_id`, `book_code`, chapter/verse span — Stage 5), not the words.

## Resilience (FR-BI-6)

The Redis chapter cache is the resilience layer. When a playlist is built for projection, the frontend resolves every distinct reading up front (`GET /bible/resolve`), which warms the cache. If the network then drops mid-service, the already-fetched chapters keep projecting. A resolution that fails with a cold cache degrades to a single placeholder slide showing the reference — it never breaks projection.

## Reference resolution

`App\Services\Bible\ReferenceParser` + `BookMap` turn a freeform reference into a USFM span:

- `BookMap` maps French and English names/abbreviations to USFM codes (66-book canon), accent/space/case-insensitively (`Jean`, `John`, `Jn`, `1 Corinthiens`, `1Co`, `I Corinthiens` → …).
- `ReferenceParser` handles single verses, same-chapter ranges, **cross-chapter ranges**, whole chapters, and chapter ranges: `Jean 3:16`, `Jean 3:16-18`, `Jean 3:16-4:2`, `Psaume 23`, `1 Corinthiens 13`.

`App\Services\Bible\ScriptureResolver` fetches each spanned chapter (cached), slices verses (trimming the first/last chapter of a cross-chapter range, taking middle chapters whole), and returns the verses plus a localized label (`Jean 3:16-4:2`) and a concrete parsed span (so a freeform entry can be stored as a re-resolvable playlist item).

## Endpoints

| Method | Path | Auth | Purpose |
|--------|------|------|---------|
| GET | `/api/bible/translations/available` | Bearer (admin) | HelloAO catalogue, filtered to `config('bible.languages')` |
| GET | `/api/bible/settings` | Bearer | Enabled translations + default |
| PUT | `/api/bible/settings` | Bearer (admin) | Save settings; repopulate `bible_books` |
| GET | `/api/bible/books?translation=…` | Bearer | Books of a translation (picker) |
| GET | `/api/bible/resolve` | Bearer | Resolve a reference (`q=…` freeform, or discrete `book_code`+chapters/verses) → verses; warms the cache |

## Projection rendering (FR-BI-8)

`frontend/src/lib/projection/buildSlides.ts` resolves each reading's verses (the pre-fetch) and `splitScriptureIntoSlides` groups them under a character budget into auto-fit slides. `SlideRenderer.vue` renders scripture with small **superscript verse numbers** and shows the **reference + translation label on the first slide only** (e.g. "Jean 3:16 · Segond 1910"); a ResizeObserver-based auto-fit shrinks the text to stay legible. The projection service's `SlideDto` carries optional `verses`/`showReference` and stores/broadcasts them unchanged (no server-side parsing).

# Auth Service

The Auth Service is a Laravel 12 application that acts as SaintAugustin's unified content-and-auth API. It handles authentication, user management, songs, songbooks, song sheets, playlists, and share links. Per [ADR-0001](adr/0001-unified-api-as-content-service.md), this is the deliberate architecture, not a temporary phase-limited stepping stone — the v1.2-planned per-domain microservice split is deferred, and new domains (e.g. Bible, v0.2) are added here rather than as new deployables.

- **Runtime:** PHP 8.3 with Apache (mod_php) — see `services/auth/Dockerfile`
- **Database:** PostgreSQL 16 (`saintaugustin_db` schema)
- **Object storage:** MinIO (S3-compatible) for song sheet files
- **Auth mechanism:** Laravel Sanctum — stateless Bearer tokens
- **Container port:** 80 internally, published as `API_PORT` (default 8000) — no gateway in front of it in the current stack (see `docs/infrastructure.md`)

---

## API Routes

All routes are prefixed `/api` by Laravel, reached directly on the Auth Service's own port.

### Authentication (`/api/auth`)

| Method | Path                              | Auth           | Description                |
| ------ | --------------------------------- | -------------- | -------------------------- |
| GET    | `/api/auth/status`              | —             | Service health / version   |
| POST   | `/api/auth/login`               | —             | Obtain a Bearer token      |
| POST   | `/api/auth/password/forgot`     | —             | Send reset email           |
| POST   | `/api/auth/password/reset`      | —             | Apply reset token          |
| GET    | `/api/auth/invitations/{token}` | —             | Verify an invitation token |
| POST   | `/api/auth/register`            | —             | Register via invitation    |
| POST   | `/api/auth/logout`              | Bearer         | Revoke current token       |
| POST   | `/api/auth/refresh`             | Bearer         | Issue a fresh token        |
| POST   | `/api/auth/invitations`         | Bearer + Admin | Create an invitation       |

Registration is invitation-only. An admin calls `POST /api/auth/invitations` with `{ email, roles[] }`, which sends an email containing a single-use token. The recipient visits the registration form, the token is validated, and their account is created with the pre-assigned roles.

### Songbooks (`/api/songbooks`)

| Method | Path                    | Auth           | Description           |
| ------ | ----------------------- | -------------- | --------------------- |
| GET    | `/api/songbooks`      | Bearer         | List all songbooks    |
| GET    | `/api/songbooks/{id}` | Bearer         | Get a single songbook |
| POST   | `/api/songbooks`      | Bearer + Admin | Create a songbook     |
| PUT    | `/api/songbooks/{id}` | Bearer + Admin | Update a songbook     |
| DELETE | `/api/songbooks/{id}` | Bearer + Admin | Delete a songbook     |

Songbooks are organisational containers for songs (e.g. "Hillsong", "Hymns", "Originals"). Every song belongs to one songbook.

### Songs (`/api/songs`)

| Method | Path                           | Auth                    | Description                   |
| ------ | ------------------------------ | ----------------------- | ----------------------------- |
| GET    | `/api/songs`                 | Bearer                  | Paginated list with filtering |
| GET    | `/api/songs/{id}`            | Bearer                  | Get a single song             |
| POST   | `/api/songs`                 | Bearer (Admin/Musician) | Create a song                 |
| PUT    | `/api/songs/{id}`            | Bearer (Admin/Musician) | Update a song                 |
| DELETE | `/api/songs/{id}`            | Bearer + Admin          | Soft-delete                   |
| POST   | `/api/songs/{id}/restore`    | Bearer + Admin          | Restore from trash            |
| GET    | `/api/songs/{songId}/sheets` | Bearer                  | List sheet attachments        |
| POST   | `/api/songs/{songId}/sheets` | Bearer (Admin/Musician) | Upload a sheet                |

**Song list query parameters:** `q` (search title/author), `songbook`, `key`, `tag`, `trashed` (`with`/`only`), `per_page`, `page`.

Songs store ChordPro lyrics in the `lyrics` column. The `original_key`, `tempo`, `time_signature`, `tags[]`, `ccli_number`, and `preview_url` fields are optional metadata. Songs support soft-delete (via Laravel's `SoftDeletes`) so the library never loses data.

### Song Sheets (`/api/sheets`)

| Method | Path                 | Auth           | Description                  |
| ------ | -------------------- | -------------- | ---------------------------- |
| GET    | `/api/sheets/{id}` | Bearer         | Get metadata + presigned URL |
| DELETE | `/api/sheets/{id}` | Bearer + Admin | Delete sheet + file          |

Song sheets are PDF or image attachments stored in MinIO. On upload the file is persisted to the `saintaugustin` bucket and a record is saved in the `song_sheets` table. On download the API generates a short-lived presigned URL (configurable TTL, default 15 minutes via `SONG_SHEET_URL_TTL`). If the URL has expired the client refetches `GET /sheets/{id}` to obtain a fresh one.

### Playlists (`/api/playlists`)

| Method | Path                                           | Auth                  | Description                                          |
| ------ | ---------------------------------------------- | --------------------- | ---------------------------------------------------- |
| GET    | `/api/playlists`                             | Bearer                | Paginated list                                       |
| POST   | `/api/playlists`                             | Bearer                | Create a playlist                                    |
| GET    | `/api/playlists/{id}`                        | Bearer                | Get playlist with items                              |
| PUT    | `/api/playlists/{id}`                        | Bearer (Owner/Admin)  | Update playlist metadata                             |
| DELETE | `/api/playlists/{id}`                        | Bearer (Owner/Admin)  | Delete playlist                                      |
| POST   | `/api/playlists/{id}/duplicate`              | Bearer                | Duplicate (caller becomes owner)                     |
| POST   | `/api/playlists/{playlistId}/items`          | Bearer (Owner/Admin)  | Add a song to the playlist (idempotent — see below) |
| PUT    | `/api/playlists/{playlistId}/items/reorder`  | Bearer (Owner/Admin)  | Reorder items                                        |
| PUT    | `/api/playlists/{playlistId}/items/{itemId}` | Bearer (Owner/Admin)  | Update item (key, notes)                             |
| DELETE | `/api/playlists/{playlistId}/items/{itemId}` | Bearer (Owner/Admin)  | Remove item                                          |
| GET    | `/api/playlists/{playlistId}/share`          | Bearer (Owner/Admin)  | List share links                                     |
| POST   | `/api/playlists/{playlistId}/share`          | Bearer (Owner/Admin)  | Create a share link                                  |
| DELETE | `/api/share-links/{id}`                      | Bearer (Owner/Admin)  | Revoke a share link                                  |
| GET    | `/api/playlists/{id}/export`                 | Bearer                | Download export (`format=pdf\|txt\|staug`)           |
| GET    | `/api/share/{token}`                         | — (throttled 60/min) | Resolve public share link                            |

Each playlist item stores `position`, an optional `target_key` (transpose destination for the musician), and free-text `notes`. The `reorder` endpoint accepts an ordered array of item IDs and re-assigns `position` values in one transaction.

**Polymorphic items — songs and scripture readings (FR-PL-2).** A playlist item is one of two kinds, discriminated by `item_type`:

- `song` (the default) — carries a `song_id` and an optional `target_key`; the serialized item has a `song` block and a null `scripture`.
- `scripture` — carries a resolved USFM reference in discrete columns (`translation_id`, `book_code`, `start_chapter`, `start_verse`, `end_chapter`, `end_verse`); the serialized item has a `scripture` block (including a composed `reference` label such as `JHN 3:16-4:2`) and a null `song`.

This is a single `playlist_items` table with a discriminator, not a polymorphic join, so ordering, reorder, cascade, and duplication all work unchanged across both kinds. `song_id` is nullable (null for readings); the migration only drops its NOT NULL, leaving the FK and cascade intact. Scripture *text* is never stored — only the pointer to the passage, resolved and rendered at projection time (Stage 7). `POST /items` selects the shape via `item_type`: a song item requires `song_id`; a scripture item requires `book_code`, `start_chapter`, and `start_verse`, and rejects a backwards verse range (422). Duplicate detection (below) applies only to song items — readings may legitimately repeat.

**Adding a song is idempotent (FR-SL-4).** If the song is already in the playlist, `POST /items` inserts nothing and returns the existing item with `200 OK` instead of `201 Created`. The frontend surfaces this as an "already in playlist" toast rather than an error, so re-adding the same song from the library is a harmless no-op.

### Tags (`/api/tags`)

| Method | Path          | Auth   | Description                                               |
| ------ | ------------- | ------ | --------------------------------------------------------- |
| GET    | `/api/tags` | Bearer | Distinct, sorted union of all tags on songs and playlists |

`GET /api/tags` (FR-PL-1) backs the tag autocomplete on the create-playlist modal and the song editor. Because songs and playlists share the one API database, it is a single query — `jsonb_array_elements_text(tags)` unnests each table's JSONB `tags` array and a `UNION` deduplicates across the two. Soft-deleted songs are excluded so a tag surviving only on a trashed song does not linger in suggestions. The response is `{ "data": ["advent", "communion", ...] }`.

**Share links** carry a `mode` field (`musician` or `projection`). The `musician` payload includes full song lyrics; `projection` omits them. Public access is throttled to prevent token enumeration.

**PDF export** renders the playlist via a Blade view and returns a downloadable PDF using a Laravel PDF package.

### STAUG data interchange (`/api/imports/staug`, `/api/exports/full`, `/api/songbooks/{id}/export`)

STAUG is the signed ZIP archive format for moving content between instances — full spec in [staug-format.md](staug-format.md).

| Method | Path                                        | Auth           | Description                                                        |
| ------ | ------------------------------------------- | -------------- | ------------------------------------------------------------------ |
| GET    | `/api/playlists/{id}/export?format=staug` | Bearer         | Export a playlist as STAUG                                         |
| GET    | `/api/songbooks/{id}/export?format=staug` | Bearer         | Export a songbook as STAUG (`SongbookExportController`)          |
| POST   | `/api/imports/staug`                      | Bearer (admin) | Import (or`dry_run` preview) a signed archive — non-destructive |
| POST   | `/api/exports/full`                       | Bearer (admin) | Queue a full-library export (202); 409 if one is already running   |

The implementation lives in `app/Services/Staug/` (`StaugSigner`, `StaugArchiveWriter`, `StaugArchiveReader`, `StaugImporter`). The manifest is HMAC-signed with `config('staug.signing_key')` (a new `config/staug.php` maps `STAUG_SIGNING_KEY` — provisioned in every env layer since Stage 0). Import verifies the signature and every per-song `sha256` **before** touching the database, then merges non-destructively (match on id+title; existing songs are never overwritten; UUIDs are preserved on clean creates). The full export runs as `App\Jobs\FullExportJob` on the Redis queue worker, writes to the MinIO `exports/` prefix (2-day lifecycle expiry), and emails the admin a 48h presigned link via `StaugExportReadyNotification`. One full export runs at a time, guarded by an atomic `Cache::add` marker.

### Bible (`/api/bible`)

The Bible module (Stage 7) — full spec in [bible.md](bible.md).

| Method | Path                                  | Auth           | Description                                                          |
| ------ | ------------------------------------- | -------------- | -------------------------------------------------------------------- |
| GET    | `/api/bible/translations/available` | Bearer (admin) | HelloAO catalogue, filtered to`config('bible.languages')`          |
| GET    | `/api/bible/settings`               | Bearer         | Enabled translations + default                                       |
| PUT    | `/api/bible/settings`               | Bearer (admin) | Save settings; repopulate the`bible_books` structure cache         |
| GET    | `/api/bible/books?translation=…`   | Bearer         | A translation's books (reference picker)                             |
| GET    | `/api/bible/resolve`                | Bearer         | Resolve a reference (`q=…` freeform or discrete params) → verses |

Implementation in `app/Services/Bible/` (`HelloAoClient`, `BookMap`, `ReferenceParser`, `ScriptureResolver`). Scripture text is fetched on demand from the HelloAO Free Use Bible API and cached in **Redis** (`config('bible.api_base')` ← `BIBLE_API_BASE`, added to every env layer); it is **never persisted**. Only `bible_settings` (a single row) and `bible_books` (localized names + chapter counts) live in Postgres. `resolve` accepts French/English names and cross-chapter ranges and doubles as the projection pre-fetch (warming the chapter cache).

---

## Key Design Decisions

- **Sanctum stateless mode only.** The `statefulApi()` helper is not used on any route — all API consumers send `Authorization: Bearer {token}` headers. This avoids CSRF complications in the SPA and keeps the service truly stateless.
- **Soft deletes on songs.** Songs are never hard-deleted by users. The `trashed` query parameter on the list endpoint lets admins view and restore deleted songs.
- **Soft deletes on users.** Removing a user from the Admin → Users screen soft-deletes the record (`deleted_at`) rather than hard-deleting it, so accidental removals are recoverable at the DB level (via `User::withTrashed()`). Unlike songs, there is no `trashed` query param or `/restore` endpoint for users in v0.2 — a deliberate scope decision, not an oversight: soft delete here exists purely for DB-level recoverability, not an admin-facing undo UI.
- **MinIO presigned URLs.** File content never passes through the Laravel process on download; only the presigned URL is issued. This keeps the auth service lean and avoids large request bodies in PHP.
- **Unified API, not a temporary monolith (ADR-0001).** Songs, sheets, playlists, and (from v0.2) Bible content all live in the same Laravel app. The Nginx gateway config retains stub upstreams as a documented extraction seam, but no current work depends on that split happening.

---

## Environment Variables

| Variable                                                          | Description                                                                                                                                                                 | Default                                        |
| ----------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ---------------------------------------------- |
| `APP_KEY`                                                       | Laravel app encryption key                                                                                                                                                  | — (generate with`php artisan key:generate`) |
| `DB_HOST` / `DB_DATABASE` / `DB_USERNAME` / `DB_PASSWORD` | PostgreSQL connection                                                                                                                                                       | —                                             |
| `REDIS_HOST` / `REDIS_PORT`                                   | Redis connection (cache + queue)                                                                                                                                            | `redis:6379`                                 |
| `AWS_ENDPOINT`                                                  | MinIO S3 endpoint                                                                                                                                                           | `http://minio:9000`                          |
| `AWS_ACCESS_KEY_ID` / `AWS_SECRET_ACCESS_KEY`                 | MinIO credentials                                                                                                                                                           | —                                             |
| `AWS_BUCKET`                                                    | Default bucket name                                                                                                                                                         | `saintaugustin`                              |
| `SONG_SHEET_URL_TTL`                                            | Presigned URL lifetime (minutes)                                                                                                                                            | `15`                                         |
| `QUEUE_CONNECTION`                                              | Laravel queue driver; must be`redis` so `queue-worker` processes jobs (e.g. the STAUG full-DB export)                                                                   | `redis`                                      |
| `STAUG_SIGNING_KEY`                                             | HMAC key used to sign/verify STAUG export archives. DR-critical — must match across instances that validate each other's exports. Generate with`openssl rand -base64 32` | —                                             |

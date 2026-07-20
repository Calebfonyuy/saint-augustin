# STAUG archive format

STAUG is SaintAugustin's signed, portable interchange format (SRS FR-DX / FR-DI / FR-DF). It moves songs, songbooks, and playlists between instances as a single ZIP whose integrity and origin are guaranteed by an HMAC signature. It is produced and consumed entirely by `services/auth` (`App\Services\Staug\*`).

## Archive layout

```
manifest.json          the manifest — the exact bytes that are signed
manifest.sig           lowercase-hex HMAC-SHA256 of the manifest bytes
songs/<uuid>.json      one file per SONG (full record); one entry per distinct song
```

`type` is one of `playlist`, `songbook`, or `full`.

### `manifest.json`

```jsonc
{
  "format": "STAUG",
  "version": "1",
  "type": "playlist|songbook|full",
  "generated_at": "2026-07-14T09:00:00+00:00",
  "generator": "SaintAugustin",
  "container": { /* type-specific, see below */ },
  "songbooks": [ { "id", "name", "description", "is_default" } ],
  "songs": [ { "id", "title", "file": "songs/<uuid>.json", "sha256": "<hex of that file's bytes>" } ]
}
```

- **playlist** `container`: `{ id, name, event_date, tags, items: [...] }`. Items are emitted in order; a **song** item is `{ item_type:"song", position, song_id, target_key, notes }` and its song appears once in `songs[]`; a **scripture** item (Stage 5) is inlined as `{ item_type:"scripture", position, notes, scripture:{ translation_id, book_code, start_chapter, start_verse, end_chapter, end_verse, reference } }` with no song file.
- **songbook** `container`: `{ id, name, description, is_default }`; every song in the book is in `songs[]`.
- **full** `container`: `{ song_count, songbook_count }`; every song and songbook in the library.

### `songs/<uuid>.json`

The whitelisted song record: `id, title, author, lyrics, original_key, tempo, time_signature, tags, preview_url, ccli_number, version`, an embedded `songbook` reference, and `sheets[]` — **reference metadata only** (`original_filename, storage_disk, storage_path, file_type, mime_type, size_bytes`). Song-sheet **binaries are never bundled** (FR-DX-4); see the caveat below.

## Signing (FR-DX-3)

`StaugSigner` computes `HMAC-SHA256( "STAUG-SIG-V1\n" + rawManifestBytes , STAUG_SIGNING_KEY )` and stores the hex result in `manifest.sig`. Key rules:

- **Sign and verify the exact stored bytes.** The writer serializes the manifest once, writes those bytes as `manifest.json`, and signs those same bytes. The reader HMACs the raw `manifest.json` entry bytes directly — it never re-serializes a decoded manifest (`json_encode` output is not stable across flags/PHP versions, which would silently break verification).
- **Transitive coverage.** The manifest records each song file's `sha256`, so the one manifest signature covers every song body indirectly.
- **Domain separation.** The `STAUG-SIG-V1` prefix binds the signature to this signing scheme independently of the manifest's `format_version`.
- The key comes from `config('staug.signing_key')` (mapped from `STAUG_SIGNING_KEY`). A missing key is a server misconfiguration (500), not an invalid archive (422). Archives are only exchangeable between instances sharing the same key.

## Verification (import, FR-DI-1)

`StaugArchiveReader::open()` is adversarial and rejects on the first failure with `StaugValidationException` (→ 422), **before any DB write**:

1. `manifest.json` and `manifest.sig` are both present.
2. HMAC over the raw manifest bytes equals `manifest.sig` (constant-time).
3. The manifest is well-formed (`format`, `version`, `type`, `songs`).
4. Every ZIP entry name is safe — no `..`, no leading `/`, no backslashes; song entries match `songs/<uuid>.json`. The set of physical `songs/*.json` **exactly equals** the manifest's `songs[].file` list (no unlisted extras, no listed-but-missing files). Entry count and per-entry size are capped (zip-bomb guard).
5. Each song file's `sha256` matches the manifest.
6. Only then are song bodies decoded.

## Import merge (FR-DI-2)

`StaugImporter::import()` is **strictly non-destructive** — no update, restore, or delete ever happens. The whole batch runs in one transaction (a mid-batch failure lands zero rows). Songbooks are resolved by unique `name`. Per song, matching on **id and title**:

| archive id | existing row (`withTrashed`) | title | action | counter |
|---|---|---|---|---|
| present | not found | — | create, **preserving** the archived UUID | `created` |
| present | found | same | **skip** (incl. soft-deleted — left trashed, not restored) | `skipped` |
| present | found | differs | create a **new** record with a fresh UUID; existing untouched | `conflicted` |
| absent | — | — | create with a fresh UUID | `created` |

UUID preservation uses `forceFill([... ,'id'=>$uuid])->save()` (Eloquent's `HasUuids` only generates an id when the model's id is empty). `withTrashed()` is mandatory so a preserved id can't collide with a soft-deleted row.

### Song-sheet caveat (FR-DX-4)

Sheet binaries are referenced, not bundled. On import, a `SongSheet` row is recreated **only** in the preserve-UUID case (the stored object key embeds the original song id, so it stays valid) **and only when** the referenced object actually exists on the target disk (`Storage::disk($disk)->exists($path)`). A cross-instance import therefore leaves no dangling sheet rows; the binaries must be migrated to the target MinIO out of band for the references to resolve.

## Full-library export (FR-DF)

`POST /exports/full` (admin) enforces one export at a time with an atomic `Cache::add` marker (`staug:full-export:running`, TTL from `config('staug.full_export_lock_ttl_minutes')`); a concurrent request gets **409**. On success it dispatches `FullExportJob` and returns **202**.

`FullExportJob` (queue worker) builds a `type:"full"` archive of **songs and songbooks only** (users, playlists, and sessions are excluded), streaming one song at a time into the ZIP so memory stays bounded. It uploads to MinIO under `exports/staug-full-<timestamp>-<uuid>.zip`, generates a **48h presigned URL** (`config('staug.export_url_ttl_hours')`), and emails the requesting admin via `StaugExportReadyNotification`. The lock is released in a `finally` and again in `failed()`; the marker TTL is the backstop if the worker is hard-killed.

### Object lifecycle (FR-DF-3)

The `exports/` prefix has a **2-day expiry** lifecycle rule so objects die with their links. It is applied by the same `mc ilm rule add ... --expire-days 2 --prefix exports/` command in every deployment path: the dev `minio-init` container (`docker-compose.yml`), the production Compose stack's `minio-init` service (`deployment/compose/docker-compose.prod.yml`), and the Kubernetes `minio-init` Job (`deployment/kubernetes/05_minio.yaml`).

## Endpoints

| Method | Path | Auth | Purpose |
|--------|------|------|---------|
| GET | `/api/playlists/{id}/export?format=staug` | Bearer | Export a playlist as STAUG |
| GET | `/api/songbooks/{id}/export?format=staug` | Bearer | Export a songbook as STAUG |
| POST | `/api/imports/staug` | Bearer (admin) | Import (or `dry_run` preview) a STAUG archive |
| POST | `/api/exports/full` | Bearer (admin) | Queue a full-library export |

# ADR-0001: Unified API as the content service

**Status:** Accepted
**Date:** 2026-07-09

## Context

The v1.2 baseline design specified a per-domain microservice decomposition: separate Auth, Song, Playlist, File, and Import services, each with its own database. `README.md` and parts of `docs/` still describe this target architecture.

In practice, v0.1 was built and shipped as a single unified Laravel application (`services/auth`) that owns authentication, users, invitations, songs, songbooks, song sheets, playlists, playlist items, share links, VideoPsalm import, and playlist export, all against one shared PostgreSQL database. The Nginx gateway reserves stub upstreams (`song_service`, `playlist_service`, `file_service`, `import_service`) for a future split, but none of those services exist, and none are scheduled for v0.2.

Two related facts bound this decision:

1. **Sessions are not part of the SQL API.** There is no `sessions` migration in `services/auth`. Live/scheduled sessions, control tokens, and slide sync are owned entirely by the projection service (`services/projection`, NestJS + Redis, `sa:proj:*` keys). The projection service remains the system of record for sessions regardless of how the content domain evolves.
2. **v0.2 adds a Bible workstream** (translation management, reference resolution, playlist integration, projection) that needs to live somewhere. Splitting it into a new microservice would mean standing up a fifth deployable for a project with a single developer and a single shared database, before the original four-way split has even happened.

## Decision

The Bible module is added **inside `services/auth`**, as a new module of the existing unified API — not as a new deployable. SaintAugustin v0.2 continues to be built as three deployables:

| Deployable | Path | Owns |
| --- | --- | --- |
| API (unified backend) | `services/auth` | Auth, users, invitations, songs, songbooks, song sheets, playlists, playlist items, share links, imports/exports (incl. STAUG), Bible |
| Projection service | `services/projection` | Sessions (live/scheduled), control tokens, slide sync |
| Frontend | `frontend` | SPA |

The v1.2 per-domain microservice split (separate Auth/Song/Playlist/File/Import services, database-per-service) is formally **deferred**, not abandoned outright — the Nginx stub upstreams are left in place as a documented extraction seam, but no work in v0.2 depends on or works toward that split.

## Consequences

- All Bible-domain work (FR-BI) is Laravel work inside `services/auth`, sharing its database, auth, and deployment pipeline — no new service, no new inter-service auth, no new database.
- Documentation that still describes "5 Laravel microservices" or "database-per-service" (root `README.md`, and framing in `docs/auth-service.md`) is corrected to describe the as-built three-deployable, single-database architecture, referencing this ADR.
- Session-related work (retention, live control, reclaim/takeover) remains projection-service + frontend work, never SQL/Laravel work, since sessions have no representation in the API's database.
- If/when the v1.2 split is revisited, it is a separate ADR — this document only records that it is out of scope through v0.2.

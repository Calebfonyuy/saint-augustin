# Projection Service

The Projection Service provides real-time slide control for live worship services. A worship leader (controller) drives a playlist of lyrics slides from their device; one or more projection displays (screens, secondary browsers) follow along via WebSocket.

- **Runtime:** Node.js / NestJS 10 with Socket.IO
- **Session storage:** Redis 7 — `TEMPORARY` sessions are ephemeral (TTL, self-cleaning); `PERSISTENT` sessions (the default) live indefinitely until explicitly ended or deleted
- **Container port:** 3000
- **Gateway paths:** `/api/projection/` (HTTP), `/ws/projection/` and `/socket.io/` (WebSocket)

---

## Concepts

### Session

A **projection session** is an object stored in Redis (`SessionState`) that holds:

| Field | Type | Description |
|-------|------|-------------|
| `id` | UUID | Unique session identifier |
| `name` | string | Human-friendly label — falls back to `playlistName` if not set |
| `status` | `'NOT_STARTED' \| 'LIVE' \| 'ENDED'` | Lifecycle stage — see below |
| `kind` | `'TEMPORARY' \| 'PERSISTENT'` | Retention model — see below |
| `ownerId` / `ownerName` | string \| null | The user who created the session; drives owner/admin authorization |
| `scheduledStartAt` / `scheduledEndAt` | ISO string \| null | Informational — stored but not enforced; the actual LIVE transition happens via `/start` |
| `startedAt` / `endedAt` | ISO string \| null | Set when the session enters LIVE / ENDED |
| `playlistId` | string \| null | Source playlist (if launched from one) |
| `playlistName` | string | Display name |
| `slides` | `ProjectionSlide[]` | Ordered flat list of all slides |
| `currentIndex` | number | Index of the active slide |
| `blackout` | boolean | When true, displays show a blank screen |
| `fontScale` | number | Font size multiplier (0.5–3.0) |
| `createdAt` / `updatedAt` | ISO strings | Timestamps |

**Lifecycle:** `NOT_STARTED → LIVE → ENDED` (via `/start` and `/end`).

**Two kinds, two retention policies** (`SessionsService.create()`, defaults to `PERSISTENT` when `kind` is omitted):

- **`PERSISTENT`** (default) — created `NOT_STARTED`, no slides required up front (can be loaded later via `/load`). No TTL while `NOT_STARTED` or `LIVE`, so it survives indefinitely until the leader explicitly ends or deletes it. Once `ENDED`, gets a 30-day TTL so a share link still resolves to an "ended" page for a while instead of 404ing immediately.
- **`TEMPORARY`** (legacy, must be requested explicitly) — requires slides at creation time, enters `LIVE` immediately, and gets a fixed TTL (`SESSION_TTL_SECONDS`, default 4h) that's refreshed on every mutation and every persist — it self-cleans if abandoned, regardless of status.

### Slides

Slides are built from playlist items **on the frontend** before the session is created. Each slide maps to one lyric section of one song:

```typescript
interface ProjectionSlide {
  id: string          // unique per slide
  itemIndex: number   // which playlist item this slide belongs to
  slideIndex: number  // position within that item's slides
  songTitle: string
  section: string | null  // e.g. "Verse 1", "Chorus"
  body: string            // plain text lyrics for this section
  kind?: 'song' | 'scripture'  // parent item kind (FR-PL-2); defaults to 'song'
  reference?: string | null    // resolved label for scripture slides
}
```

The slide-building logic lives in `frontend/src/lib/projection/buildSlides.ts`. It splits ChordPro lyrics into sections and creates one `ProjectionSlide` per section.

**Scripture readings (FR-PL-2).** A playlist can now interleave songs and scripture readings. A reading currently builds a single placeholder slide tagged `kind: 'scripture'` carrying its `reference` label; the DTO (`SlideDto`) accepts these fields as optional, so both `/sessions` and `/sessions/:id/load` take mixed decks without change. Verse-by-verse rendering (fetch, auto-fit, verse numbers) is added in Stage 7 — until then the projector shows the reference text.

### Control Token

When a session enters `LIVE` (immediately for `TEMPORARY`, or via `/start` for `PERSISTENT`) the service issues a `controlToken` — a cryptographically random 24-byte base64url string. This token is stored in Redis under a separate key (`sa:proj:control-token:{sessionId}`) so it never appears in the broadcast state. Only the client that holds the control token can issue write events (next, previous, goto, blackout, font-scale). Token comparison uses a constant-time algorithm to avoid timing oracle attacks.

The frontend persists the control token client-side (per session, in `localStorage`) so a reload or tab close doesn't strand the leader in read-only mode:

- **Reclaim** (`POST /sessions/:id/reclaim`) — re-confirms a previously-issued token is still valid, without rotating it. Called on mount if a matching token is found for the current session.
- **Takeover** (`POST /sessions/:id/takeover`, owner or admin only) — force-rotates the control token and revokes any currently-connected controller sockets for that session (see below), then broadcasts `control-transferred` so the deposed client's UI flips to read-only in real time.

---

## HTTP API

All routes except `GET /sessions/:id` and `GET /health` require a Bearer token, verified by calling the Laravel Auth Service's `/api/auth/me` (response cached 60s in Redis — see `AuthGuard`/`AuthService`). "Owner or admin" means the caller is the session's `ownerId` or has the `admin` role.

| Method | Path | Auth | Description |
|--------|------|------|-------------|
| POST | `/sessions` | Bearer | Create a session; returns `{ sessionId, controlToken, state }`. `controlToken` is only non-null when the session is immediately `LIVE` (`TEMPORARY`) |
| GET | `/sessions` | Bearer | List `NOT_STARTED` + `LIVE` sessions; `?mine=1` filters to the caller's own |
| GET | `/sessions/:id` | — (public) | Fetch current session state — the `sessionId` itself is the read credential, used by display pages and share links |
| POST | `/sessions/:id/start` | Owner or admin | `NOT_STARTED → LIVE`; returns a fresh `controlToken`. Requires slides already loaded |
| POST | `/sessions/:id/end` | Owner or admin | `LIVE → ENDED`; clears the control token |
| POST | `/sessions/:id/load` | Owner or admin | Attach/replace the slide deck |
| POST | `/sessions/:id/reclaim` | Bearer | Body `{ token }`. Re-confirms the token still authorizes control — no rotation |
| POST | `/sessions/:id/takeover` | Owner or admin | Rotates the control token, revokes connected controller sockets, broadcasts `control-transferred` |
| DELETE | `/sessions/:id` | Owner or admin | Delete the session entirely |
| GET | `/health` | — | Service health check |

Session creation accepts:

```json
{
  "kind": "PERSISTENT",
  "name": "Sunday 9:30 (optional — falls back to playlistName)",
  "playlistName": "Sunday 27 April",
  "playlistId": "uuid-optional",
  "slides": [ "... optional for PERSISTENT, required for TEMPORARY" ],
  "scheduledStartAt": "2026-04-27T08:30:00Z",
  "scheduledEndAt": "2026-04-27T10:00:00Z"
}
```

---

## WebSocket API

Namespace: `/ws/projection`

Connect to `ws://<host>/ws/projection` using Socket.IO. After connecting, emit a `join` event to enter a session room.

### Client → Server events

| Event | Payload | Role required | Description |
|-------|---------|---------------|-------------|
| `join` | `{ sessionId, controlToken? }` | — | Enter a session room. If `controlToken` matches the stored token the socket is promoted to controller. |
| `next` | — | controller | Advance to the next slide |
| `previous` | — | controller | Go back one slide |
| `goto` | `{ index: number }` | controller | Jump to an absolute slide index |
| `jump-to-song` | `{ itemIndex: number }` | controller | Jump to the first slide of a playlist item |
| `blackout` | `{ on: boolean }` | controller | Toggle screen blackout |
| `font-scale` | `{ scale: number }` | controller | Set font scale (clamped 0.5–3.0) |

The `join` response includes the current `state` so a newly connected display is immediately in sync. Write events return `{ ok: true }` or `{ ok: false, error: string }`.

### Server → Client events

| Event | Payload | Description |
|-------|---------|-------------|
| `state` | `SessionState` | Broadcast to every client in the room after any mutation |
| `control-transferred` | `{ byName: string \| null }` | Broadcast after a REST `/takeover`. Every socket that was cached as `controller` for that room is server-side downgraded to `display` *before* this fires — the event is a UI signal, not the enforcement mechanism itself |

The full state is sent on every change rather than a diff. This makes reconnection trivial — a client that drops and reconnects simply re-joins and receives the current state immediately.

---

## Redis Storage Layout

```
sa:proj:session:<sessionId>           JSON-encoded SessionState
sa:proj:control-token:<sessionId>     controlToken string (LIVE only)
sa:proj:sessions:active               SET of NOT_STARTED + LIVE session ids
sa:proj:sessions:by-owner:<userId>    SET of session ids owned by that user
```

Keeping the control token in a sibling key (rather than inside the state object) ensures it is never accidentally included in a WebSocket broadcast.

**TTL policy** (`SessionsService.ttlFor()`), applied to both the state and token keys together:

| Kind | Status | TTL |
|------|--------|-----|
| `TEMPORARY` | any | `SESSION_TTL_SECONDS` (default 4h), refreshed on every persist |
| `PERSISTENT` | `NOT_STARTED` / `LIVE` | none — survives indefinitely |
| `PERSISTENT` | `ENDED` | 30 days |

The `sessions:active` and `sessions:by-owner:*` sets are best-effort indexes for listing — stale entries (state expired but the set still references it) are pruned lazily on the next `list()` call.

---

## Module Structure

```
src/
├── app.module.ts             Root NestJS module
├── main.ts                   Bootstrap (Socket.IO adapter, CORS, validation pipe)
├── health.controller.ts      GET /health
├── auth/
│   ├── auth.module.ts
│   ├── auth.guard.ts          Verifies the Bearer token against the Auth Service
│   ├── auth.service.ts        HTTP call to /api/auth/me, 60s Redis cache
│   ├── auth.types.ts          AuthUser, isAdmin()
│   └── current-user.decorator.ts
├── projection/
│   ├── projection.module.ts   imports SessionsModule (forwardRef — see sessions.module.ts)
│   └── projection.gateway.ts  WebSocket gateway (Socket.IO) + handleTakeover()
├── redis/
│   ├── redis.module.ts
│   └── redis.service.ts      Thin ioredis wrapper
└── sessions/
    ├── sessions.module.ts     imports ProjectionModule (forwardRef, for the takeover→broadcast call)
    ├── sessions.controller.ts HTTP CRUD for sessions
    ├── sessions.service.ts    Session lifecycle + mutations
    ├── session.types.ts       SessionState interface
    └── dto/
        ├── create-session.dto.ts
        ├── load-slides.dto.ts
        ├── reclaim-session.dto.ts
        └── slide.dto.ts
```

`SessionsModule` and `ProjectionModule` depend on each other (`SessionsController` needs `ProjectionGateway` to broadcast `control-transferred` after a takeover; `ProjectionGateway` needs `SessionsService` for everything else) — wired with NestJS's `forwardRef()` rather than an event bus, since it's the only cross-module call in the service.

---

## Environment Variables

| Variable | Description | Default |
|----------|-------------|---------|
| `PORT` | HTTP + WebSocket port | `3000` |
| `REDIS_HOST` | Redis hostname | `redis` |
| `REDIS_PORT` | Redis port | `6379` |
| `SESSION_TTL_SECONDS` | `TEMPORARY` session expiry (seconds) — no effect on `PERSISTENT` sessions | `14400` (4h) |
| `AUTH_SERVICE_URL` | Base URL of the Laravel Auth Service, used by `AuthGuard` to verify Bearer tokens via `/api/auth/me` | `http://auth-service:8000` |

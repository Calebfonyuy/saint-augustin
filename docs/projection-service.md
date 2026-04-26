# Projection Service

The Projection Service provides real-time slide control for live worship services. A worship leader (controller) drives a playlist of lyrics slides from their device; one or more projection displays (screens, secondary browsers) follow along via WebSocket.

- **Runtime:** Node.js / NestJS 10 with Socket.IO
- **Session storage:** Redis 7 (ephemeral — sessions expire automatically)
- **Container port:** 3000
- **Gateway paths:** `/api/projection/` (HTTP), `/ws/projection/` and `/socket.io/` (WebSocket)

---

## Concepts

### Session

A **projection session** is a short-lived object stored in Redis that holds:

| Field | Type | Description |
|-------|------|-------------|
| `id` | UUID | Unique session identifier |
| `playlistId` | string \| null | Source playlist (if launched from one) |
| `playlistName` | string | Display name |
| `slides` | `ProjectionSlide[]` | Ordered flat list of all slides |
| `currentIndex` | number | Index of the active slide |
| `blackout` | boolean | When true, displays show a blank screen |
| `fontScale` | number | Font size multiplier (0.5–3.0) |
| `createdAt` / `updatedAt` | ISO strings | Timestamps |

Sessions have a configurable TTL (default 4 hours) that resets on every mutation. A session that is not being actively controlled will expire automatically, cleaning up Redis without any explicit teardown.

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
}
```

The slide-building logic lives in `frontend/src/lib/projection/buildSlides.ts`. It splits ChordPro lyrics into sections and creates one `ProjectionSlide` per section.

### Control Token

When a session is created the service returns a `controlToken` — a cryptographically random 24-byte base64url string. This token is stored in Redis under a separate key (`sa:proj:control-token:{sessionId}`) so it never appears in the broadcast state. Only the client that holds the control token can issue write events (next, previous, goto, blackout, font-scale). Token comparison uses a constant-time algorithm to avoid timing oracle attacks.

---

## HTTP API

| Method | Path | Auth | Description |
|--------|------|------|-------------|
| POST | `/sessions` | — | Create a session; returns `{ sessionId, controlToken, state }` |
| GET | `/sessions/:id` | — | Fetch current session state (used on initial display page load) |
| DELETE | `/sessions/:id` | `X-Control-Token` header | End the session |
| GET | `/health` | — | Service health check |

Session creation accepts:

```json
{
  "playlistName": "Sunday 27 April",
  "playlistId": "uuid-optional",
  "slides": [ ... ]
}
```

The HTTP API is intentionally unauthenticated at the user level. The service runs inside the church's private network and sessions are short-lived. The `sessionId` (UUID) acts as the read credential; the `controlToken` gates writes.

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

The full state is sent on every change rather than a diff. This makes reconnection trivial — a client that drops and reconnects simply re-joins and receives the current state immediately.

---

## Redis Storage Layout

```
sa:proj:session:<sessionId>        JSON-encoded SessionState  (TTL: SESSION_TTL_SECONDS)
sa:proj:control-token:<sessionId>  controlToken string        (same TTL)
```

Keeping the control token in a sibling key (rather than inside the state object) ensures it is never accidentally included in a WebSocket broadcast.

Every mutation refreshes both keys' TTL atomically using a Redis pipeline.

---

## Module Structure

```
src/
├── app.module.ts             Root NestJS module
├── main.ts                   Bootstrap (Socket.IO adapter, CORS, validation pipe)
├── health.controller.ts      GET /health
├── projection/
│   ├── projection.module.ts
│   └── projection.gateway.ts WebSocket gateway (Socket.IO)
├── redis/
│   ├── redis.module.ts
│   └── redis.service.ts      Thin ioredis wrapper
└── sessions/
    ├── sessions.module.ts
    ├── sessions.controller.ts HTTP CRUD for sessions
    ├── sessions.service.ts    Session lifecycle + mutations
    ├── session.types.ts       SessionState interface
    └── dto/
        ├── create-session.dto.ts
        └── slide.dto.ts
```

---

## Environment Variables

| Variable | Description | Default |
|----------|-------------|---------|
| `PORT` | HTTP + WebSocket port | `3000` |
| `REDIS_HOST` | Redis hostname | `redis` |
| `REDIS_PORT` | Redis port | `6379` |
| `SESSION_TTL_SECONDS` | Session expiry (seconds) | `14400` (4h) |

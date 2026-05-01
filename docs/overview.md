# SaintAugustin – Architecture Overview

SaintAugustin is a church music management platform that helps worship teams organise songs, build service playlists, and project lyrics live to a screen. The project is structured as a monorepo containing two backend services, a Vue 3 frontend, and shared infrastructure defined in Docker Compose.

---

## High-Level Architecture

```
Browser
  │
  ▼
┌─────────────────────────────────────────┐
│  Nginx Gateway  (:8080)                 │
│  Path-based routing to upstream services│
└────┬────────────┬───────────┬───────────┘
     │            │           │
     ▼            ▼           ▼
 Auth Service  Projection  Frontend
 (Laravel 12)  Service     (Vue 3 / Vite)
 :8000         (NestJS 10)  :5173
               :3000
     │
     ▼
┌──────────────────────────────────────┐
│  Infrastructure                      │
│  PostgreSQL 16  Redis 7  MinIO       │
└──────────────────────────────────────┘
```

All HTTP traffic from the browser passes through the Nginx gateway on port 8080. The gateway routes by path prefix:

| Path prefix        | Upstream            |
|--------------------|---------------------|
| `/api/auth/`       | Auth Service        |
| `/api/songs`       | Auth Service        |
| `/api/songbooks`   | Auth Service        |
| `/api/playlists`   | Auth Service        |
| `/api/share/`      | Auth Service        |
| `/api/sheets`      | Auth Service        |
| `/api/projection/` | Projection Service  |
| `/ws/projection/`  | Projection Service (WebSocket) |
| `/socket.io/`      | Projection Service (Socket.IO) |
| `/`                | Frontend            |

---

## Services

| Service            | Language / Framework | Database     | Storage |
|--------------------|----------------------|--------------|---------|
| Auth Service       | PHP 8.3 / Laravel 12 | PostgreSQL (`saintaugustin_db`) | MinIO (song sheets) |
| Projection Service | TypeScript / NestJS 10 | Redis 7 (session state) | — |
| Frontend           | TypeScript / Vue 3 + Vite | — | — |

### Deliberately merged services

Several logically distinct capabilities were merged into the Auth Service to reduce operational overhead during development:

- **Song & Songbook CRUD** (would be a separate Song Service in production)
- **File Service** (song sheet upload/download via MinIO presigned URLs)
- **Playlist & Share Link management** (would be a separate Playlist Service)

The Nginx config and Docker Compose retain stub upstreams (`song-service`, `playlist-service`, `file-service`) so these can be extracted into their own containers later without changing the gateway configuration.

---

## User Roles

| Role           | Capabilities |
|----------------|-------------|
| `admin`        | Full CRUD on all entities; manages invitations and songbooks |
| `musician`     | Can create and edit songs; upload song sheets; read-only on songbooks |
| `projectionist` | Read access; can create and control projection sessions |

Registration is invitation-only. Admins issue invitations that carry a pre-assigned role.

---

## Development Phases

| Phase | Focus | Status |
|-------|-------|--------|
| 0 | Scaffolding, Docker Compose, CI pipeline | Done |
| 1 | Auth, Songs, Songbooks, frontend shell | Done |
| 2 | Musician view, ChordPro parser, song sheets (MinIO) | Done |
| 3 | Playlists, Playlist Builder, share links, PDF export | Done |
| 4 | Live projection (WebSocket sessions, slide renderer) | In progress |
| 5+ | Import service, mobile optimisation, offline mode | Planned |

---

## Repository Layout

```
saint-augustin/
├── docker/
│   ├── nginx/default.conf      Nginx gateway config
│   └── postgres/init-databases.sh  Creates saintaugustin_db DB on first start
├── docs/                       This documentation folder
├── frontend/                   Vue 3 + Vite + Tailwind SPA
│   └── src/
│       ├── api/                Axios API clients per resource
│       ├── components/         Shared UI components
│       ├── lib/                Pure logic: ChordPro parser, projection slide builder
│       ├── router/             Vue Router with auth guards
│       ├── stores/             Pinia stores
│       ├── types.ts            Shared domain types
│       └── views/              One component per route
├── services/
│   ├── auth/                   Laravel 12 monolith (auth + songs + playlists + files)
│   └── projection/             NestJS 10 WebSocket service
├── docker-compose.yml
└── Makefile
```

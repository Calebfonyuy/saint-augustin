# Infrastructure

SaintAugustin's local development environment is fully containerised via Docker Compose. Every service, database, and piece of infrastructure runs as a container; no dependencies need to be installed on the host beyond Docker Engine and Compose v2.

---

## Services at a Glance

| Container | Image | Ports | Data |
|-----------|-------|-------|------|
| `sa-postgres` | `postgres:16-alpine` | 5432 | `postgres_data` volume |
| `sa-redis` | `redis:7-alpine` | 6379 | `redis_data` volume |
| `sa-minio` | `minio/minio:latest` | 9000 (S3), 9001 (console) | `minio_data` volume |
| `sa-minio-init` | `minio/mc:latest` | — | One-shot bucket creator |
| `sa-gateway` | `nginx:1.27-alpine` | **8080** (public) | — |
| `sa-auth` | Built from `services/auth` | 8000 | `./services/auth` (bind mount) |
| `sa-projection` | Built from `services/projection` | 3000 | `./services/projection` (bind mount) |
| `sa-frontend` | Built from `frontend` | 5173 | `./frontend` (bind mount) |

Startup order is enforced via `depends_on` with `condition: service_healthy` checks on PostgreSQL, Redis, and MinIO.

---

## PostgreSQL

- **Image:** `postgres:16-alpine`
- **Database created:** `saintaugustin_db` (the init script at `docker/postgres/init-databases.sh` runs once on volume creation)
- **User:** configured via `POSTGRES_USER` env var (default `saintaugustin`)

**Connecting directly:**

```bash
make db-shell
# or:
docker compose exec postgres psql -U saintaugustin -d saintaugustin_db
```

---

## Redis

- **Image:** `redis:7-alpine`
- **Used by:** Auth Service (cache + queue driver), Projection Service (session storage)
- **No persistence config** — data is ephemeral and acceptable to lose on restart
- Projection sessions have a 4-hour TTL and clean themselves up automatically

---

## MinIO (Object Storage)

- **Image:** `minio/minio:latest`
- **S3 API:** port 9000
- **Web Console:** port 9001 — visit `http://localhost:9001` to browse buckets and files
- **Default bucket:** `saintaugustin` (created by `sa-minio-init` on first start)
- **Public path:** `local/saintaugustin/public` is set to anonymous download — public preview files can go here

Song sheets are stored in the `saintaugustin` bucket. The Auth Service generates short-lived presigned URLs on demand rather than issuing permanent public URLs.

**Access credentials** are set via `MINIO_ROOT_USER` / `MINIO_ROOT_PASSWORD` in `.env`.

---

## Nginx Gateway

- **Image:** `nginx:1.27-alpine`
- **Config:** `docker/nginx/default.conf`
- **Public port:** 8080 (configurable via `GATEWAY_HTTP_PORT`)
- **Max upload body:** 50 MB (for song sheet uploads)

The gateway routes all traffic by URL prefix. WebSocket connections (projection and Vite HMR) are handled with `Upgrade` / `Connection` headers and an extended `proxy_read_timeout` (86400 s).

### Path routing summary

```
/api/auth/        → auth-service:8000
/api/songs        → auth-service:8000   (currently; placeholder upstreams exist for future split)
/api/songbooks    → auth-service:8000
/api/playlists    → auth-service:8000
/api/share/       → auth-service:8000
/api/sheets       → auth-service:8000
/api/projection/  → projection-service:3000
/ws/projection/   → projection-service:3000 (WebSocket)
/socket.io/       → projection-service:3000 (Socket.IO)
/                 → frontend:5173
```

> **Note:** The Nginx config retains stub upstreams (`song_service`, `playlist_service`, `file_service`, `import_service`) pointing at containers that don't yet exist. These upstreams will return errors if ever matched, but the routing rules that reference them are not currently active.

---

## Volumes

| Volume | Purpose |
|--------|---------|
| `postgres_data` | PostgreSQL data directory |
| `redis_data` | Redis RDB snapshot |
| `minio_data` | MinIO object storage |

To completely reset the environment (including all data):

```bash
docker compose down -v
docker compose up -d
make migrate seed
```

---

## CI / CD

GitHub Actions workflows live in `.github/workflows/`. The pipeline runs on every push and pull request:

- **Projection Service** — builds the Docker image, runs `npm test`
- **Frontend** — builds the Docker image, runs `npm run lint` and `npm test`
- **Auth Service** — builds the Docker image, runs `vendor/bin/pest`

---

## Common Operations

```bash
# First-time setup
make setup
make migrate seed

# Daily start / stop
make up
make down

# Tail logs
make logs              # all services
make auth-logs         # just auth
make projection-logs   # just projection
make frontend-logs     # just frontend

# Open a database shell
make db-shell

# Drop and recreate all tables + seed
make fresh

# Run tests
make test
make test-auth
make test-projection
```

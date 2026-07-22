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
| `staug-api` | Built from `services/auth` | 8000 (host) → 80 | Auth Service (Apache/mod_php) |
| `staug-queue-worker` | Built from `services/auth` | — | `php artisan queue:work` — background job processor |
| `staug-projection` | Built from `services/projection` | 3000 | Projection Service |
| `staug-frontend` | Built from `frontend` | 5173 | Static SPA served by its own Nginx (`frontend/nginx.conf`) |

There is no separate API gateway container — the frontend calls the Auth Service and Projection Service directly via `API_BASE_URL` / `PROJECTION_WS_URL` / `PROJECTION_URL` (CORS-enabled on the Laravel side). `docker/nginx/default.conf` documents a gateway routing layout that is not currently deployed in `docker-compose.yml`.

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

## Queue Worker

- **Container:** `staug-queue-worker`, same image/build as the Auth Service, running `php artisan queue:work redis --tries=3 --backoff=5 --sleep=3` instead of serving HTTP.
- Processes Laravel's queued jobs (`QUEUE_CONNECTION=redis`) — e.g. the STAUG full-DB export job. Without this container, jobs dispatched with `dispatch()` sit in Redis and never run.
- Runs alongside `staug-api`; check `docker compose logs queue-worker` if queued jobs aren't completing.

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

## API Gateway (not currently deployed)

`docker/nginx/default.conf` still documents a path-based Nginx gateway (auth/songs/playlists/projection routing, incl. stub upstreams for a future service split), but no `gateway` container is defined in `docker-compose.yml` today. Each service is reached directly on its own host port instead (see the table above). If the gateway is reintroduced, update this section and the Services table together.

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

## Production Deployment

The Compose stack above is for **local development** (build contexts, permissive
defaults). Two production deployment paths ship in `deployment/` — see
[`docs/SETUP.md`](SETUP.md) for the step-by-step of each and
[`deployment/README.md`](../deployment/README.md) for how to choose.

| Path | Directory | When |
|------|-----------|------|
| Kubernetes (recommended) | [`deployment/kubernetes/`](../deployment/kubernetes/) | Cluster, multi-replica, rolling updates |
| Production Docker Compose | [`deployment/compose/`](../deployment/compose/) | Single host, no cluster required |
| Apache-VM (single VPS) | [`deployment/apache-VM/`](../deployment/apache-VM/) | One VM behind Apache (uses the root dev compose + a loopback override) |

Both first-party paths deploy the same three application images
(`calebfonyuy/staug-{api,projection,frontend}`, tagged with `APP_VERSION`) plus
PostgreSQL, Redis, and MinIO, and both run the **queue worker** and apply the
`exports/` 48h MinIO lifecycle rule.

### Kubernetes (`deployment/kubernetes/`)

One numbered manifest per workload, applied in order (`kubectl apply -f
deployment/kubernetes/`). There is **no Helm chart** and **no Ingress** —
services are exposed via NodePort:

| Manifest | Workload | NodePort |
|----------|----------|----------|
| `00_namespace.yaml` | Namespace `staug` | — |
| `01_t_secrets.example.yaml` | Secret `staug-secrets` (template → copy to `secrets.yaml`, gitignored) | — |
| `02_configmap.yaml` | ConfigMap `staug-config` (non-secret env) | — |
| `03_postgres.yaml` | PostgreSQL StatefulSet + PV/PVC | 31006 |
| `04_redis.yaml` | Redis Deployment + PV/PVC | 31005 |
| `05_minio.yaml` | MinIO Deployment + PV/PVC + **`minio-init` Job** (bucket + `exports/` lifecycle) | 31003 (S3), 31004 (console) |
| `06_api_service.yaml` | Auth API Deployment + **`auth-migrate` Job** | 31001 |
| `06b_queue_worker.yaml` | `auth-queue-worker` Deployment (`queue:work`) | — |
| `07_projection_service.yaml` | Projection Deployment | 31002 |
| `08_frontend_service.yaml` | Frontend Deployment | 31000 |

- **Config vs. secrets split.** Non-secret env lives in the `staug-config`
  ConfigMap; passwords, `APP_KEY`,`STAUG_SIGNING_KEY`, and SMTP
  credentials live in the `staug-secrets` Secret. Every Deployment/Job pulls
  both via `envFrom`.
- **Storage.** PostgreSQL and MinIO use `storageClassName: manual` with
  node-local `hostPath` PersistentVolumes under `/home/data/staug/*` (bound to
  their claims via `claimRef`). For a multi-node cluster, uncomment the
  `nodeAffinity` block so pods schedule to the node that holds the data.
- **Migrations** run as the one-shot `auth-migrate` Job (`migrate --force` +
  `db:seed --force`) so only one pod ever migrates, independent of API pod
  restarts. The **`minio-init` Job** creates the bucket and installs the
  `exports/` 48h lifecycle rule.
- **Images** are pinned (`calebfonyuy/staug-*:0.2`, infra images to concrete
  tags). If you host the app images in a private registry, wire an
  `imagePullSecrets` entry (a commented stub is in `06_api_service.yaml`).

### Production Docker Compose (`deployment/compose/`)

`docker-compose.prod.yml` is a single-host production stack, **distinct from the
root dev compose**: pinned image tags (no build contexts), fail-closed required
secrets (`${VAR:?}`), a one-shot `migrate` service gating the API, health checks
and `restart: unless-stopped` on every service, and named volumes. Copy
`.env.prod.example` → `.env.prod`, fill it in, then `docker compose -f
deployment/compose/docker-compose.prod.yml --env-file .env.prod up -d`.

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

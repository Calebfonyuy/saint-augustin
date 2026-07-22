# Production Docker Compose

A single-node production deployment of SaintAugustin using Docker Compose.
This is the lightweight alternative to the [Kubernetes path](../kubernetes/):
one host, pinned images, no cluster required. It is **distinct from the
repo-root `docker-compose.yml`**, which targets local development (build
contexts, permissive defaults).

| | Root `docker-compose.yml` (dev) | `deployment/compose/docker-compose.prod.yml` (prod) |
| --- | --- | --- |
| Images | built from local source | **pulled** by pinned tag (`APP_VERSION`) |
| Secrets | inline dev defaults | **required** via `${VAR:?}` (fail-closed) |
| Migrations | manual | one-shot `migrate` service, gates the API |
| DB / Redis ports | published | internal only |
| Restart / health | partial | `unless-stopped` + healthchecks on all |

## What it runs

`postgres` · `redis` · `minio` (+ `minio-init`) · `migrate` (one-shot) ·
`auth-service` · `queue-worker` · `projection-service` · `frontend`.

`minio-init` creates the bucket, opens the `public/` prefix, and installs the
**48h lifecycle rule on `exports/`** so full-DB export objects auto-expire in
step with the presigned link the export job emails (parity with the K8s
`minio-init` Job).

## Prerequisites

- A host with Docker Engine + Compose v2.
- The three application images pushed to a registry the host can pull:
  `calebfonyuy/staug-api`, `calebfonyuy/staug-projection`,
  `calebfonyuy/staug-frontend`, all tagged with your `APP_VERSION`.
  Build + push from the repo root, e.g.:

  ```bash
  export APP_VERSION=0.2
  docker compose build          # builds api / projection / frontend
  docker push calebfonyuy/staug-api:$APP_VERSION
  docker push calebfonyuy/staug-projection:$APP_VERSION
  docker push calebfonyuy/staug-frontend:$APP_VERSION
  ```

  (Or retag your locally-built `:latest` images to `:$APP_VERSION`.)

## Deploy

```bash
cd deployment/compose
cp .env.prod.example .env.prod
$EDITOR .env.prod                       # fill every REQUIRED / REPLACE_ME value

docker compose -f docker-compose.prod.yml --env-file .env.prod config   # validate
docker compose -f docker-compose.prod.yml --env-file .env.prod up -d     # start
```

The `migrate` service runs `php artisan migrate --force && db:seed --force`
to completion before `auth-service` and `queue-worker` start.

Verify:

```bash
docker compose -f docker-compose.prod.yml --env-file .env.prod ps
curl -fsS http://<host>:${API_PORT:-8000}/health
# confirm the exports/ lifecycle rule landed:
docker run --rm --network staug-prod-network \
  -e MC_HOST_local="http://$MINIO_ROOT_USER:$MINIO_ROOT_PASSWORD@minio:9000" \
  minio/mc:RELEASE.2025-08-13T08-35-41Z ilm ls local/${AWS_BUCKET:-saintaugustin}
```

## Day-2

```bash
# Roll out a new version
export APP_VERSION=0.3                    # in .env.prod
docker compose -f docker-compose.prod.yml --env-file .env.prod pull
docker compose -f docker-compose.prod.yml --env-file .env.prod up -d

# Logs / ad-hoc artisan / teardown
docker compose -f docker-compose.prod.yml --env-file .env.prod logs -f auth-service
docker compose -f docker-compose.prod.yml --env-file .env.prod exec auth-service php artisan tinker
docker compose -f docker-compose.prod.yml --env-file .env.prod down          # keep volumes
docker compose -f docker-compose.prod.yml --env-file .env.prod down -v        # drop data
```

## Caveats

- **Plain HTTP.** Terminate TLS at a reverse proxy in front of this stack
  (the [`apache-VM/`](../apache-VM/) path shows one way with Apache + certbot).
- **`AWS_ENDPOINT` must be browser-reachable** — presigned song-sheet and
  export URLs are handed to the browser, not proxied through the API.
- **Backups.** The named volumes (`staug_postgres_data`, `staug_minio_data`)
  hold the only copies. Snapshot them on a schedule.
- **Single point of failure** — one host. Fine for parish scale; use the
  Kubernetes path for HA.

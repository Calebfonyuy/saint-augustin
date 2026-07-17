# SaintAugustin – Phase 0 Setup Checklist

Step-by-step instructions for setting up the development environment on your Debian server.

## Prerequisites Checklist

- [ ] Debian 12 (bookworm) server accessible via SSH
- [ ] VS Code with [Remote - SSH](https://marketplace.visualstudio.com/items?itemName=ms-vscode-remote.remote-ssh) extension
- [ ] `sudo` access on the server
- [ ] GitHub account (for repository + CI)

---

## Step 1: Extract the Scaffold

```bash
# On your Debian server
cd ~
tar xzf saint-augustin-scaffold.tar.gz
cd saint-augustin
```

## Step 2: Install System Dependencies

```bash
sudo chmod +x scripts/setup-debian.sh
sudo ./scripts/setup-debian.sh
```

This installs:
- Docker Engine + Compose v2 ([docs](https://docs.docker.com/engine/install/debian/))
- Node.js 20 LTS ([NodeSource](https://github.com/nodesource/distributions))
- PHP 8.3 + extensions ([sury.org](https://packages.sury.org/php/))
- Composer 2 ([getcomposer.org](https://getcomposer.org/download/))

**After the script completes, log out and back in** (or run `newgrp docker`)
so your user can run Docker without `sudo`.

Verify:
```bash
docker run hello-world          # Docker works without sudo
docker compose version          # Compose v2 installed
node --version                  # v20.x.x
php --version                   # 8.3.x
composer --version              # 2.x.x
```

## Step 3: Create Environment Files

```bash
cd ~/saint-augustin
cp .env.example .env
cp services/auth/.env.example services/auth/.env
```

Edit `.env` and change at minimum:
- `POSTGRES_PASSWORD` — pick a dev password
- `MINIO_ROOT_PASSWORD` — pick a dev password
- `JWT_SECRET` — at least 32 random characters
- `STAUG_SIGNING_KEY` — at least 32 random characters (`openssl rand -base64 32`); DR-critical, keep it backed up

## Step 4: Install Application Dependencies

```bash
# Auth Service (Laravel)
cd services/auth
composer install
php artisan key:generate   # generates APP_KEY in .env
cd ../..

# Projection Service (NestJS)
cd services/projection
npm i
cd ../..

# Frontend (Vue 3)
cd frontend
npm i
cd ..
```

## Step 5: Start Infrastructure

```bash
# Start PostgreSQL, Redis, MinIO first
docker compose up -d postgres redis minio

# Wait for PostgreSQL to be ready (~10 seconds)
docker compose logs -f postgres   # watch for "ready to accept connections"
# Ctrl+C to exit logs
```

Verify databases were created:
```bash
docker compose exec postgres psql -U saintaugustin -c '\l'
# Should show: saintaugustin_db
```

## Step 6: Start All Services

```bash
docker compose up -d
docker compose ps   # all containers should be "running" or "healthy"
```

## Step 7: Run Migrations and Seed

```bash
docker compose exec auth-service php artisan migrate
docker compose exec auth-service php artisan db:seed
```

## Step 8: Verify Everything Works

```bash
# Auth Service health (host port from AUTH_SERVICE_PORT, default 8000)
curl http://localhost:8000/health
# Expected: {"status":"ok"}  (or similar Laravel health response)

# Auth API status
curl http://localhost:8000/api/auth/status
# Expected: {"service":"auth-service","status":"ok","version":"0.1.0"}

# Projection Service health
curl http://localhost:3000/health
# Expected: {"status":"ok","service":"projection-service","timestamp":"..."}

# Queue worker is up and processing jobs (see docs/infrastructure.md)
docker compose logs queue-worker
```

There is no API gateway container in the current stack — the frontend talks to the Auth Service and Projection Service directly (see `docs/infrastructure.md`).

Open in browser (replace `your-server` with your server IP or hostname):
- **Frontend:** http://your-server:5173
- **MinIO Console:** http://your-server:9001 (login with MINIO_ROOT_USER/PASSWORD)

If connecting from VS Code Remote SSH, you can forward ports:
- VS Code auto-detects open ports and offers to forward them
- Or manually: `Ctrl+Shift+P` → "Forward a Port" → enter `5173`, `8000`, `3000`, `9001`

## Step 9: Initialize Git Repository

```bash
cd ~/saint-augustin
git init
git checkout -b main
git add .
git commit -m "Phase 0: project scaffolding

- Monorepo structure with services/auth, services/projection, frontend
- Docker Compose: PostgreSQL 16, Redis 7, MinIO, Nginx gateway
- Laravel 12 Auth Service skeleton with Octane, Sanctum, health endpoint
- NestJS 10 Projection Service with WebSocket echo gateway (learning spike)
- Vue 3 + Vite + TypeScript frontend with Tailwind CSS, Pinia, vue-router
- GitHub Actions CI pipeline (lint, test, Docker build)
- Debian setup script (Docker, Node 20, PHP 8.3, Composer)
- Makefile with common dev commands"

git checkout -b develop
```

Push to GitHub:
```bash
# Create the repo on GitHub first, then:
git remote add origin git@github.com:YOUR_USERNAME/saint-augustin.git
git push -u origin main
git push -u origin develop
```

## Step 10: Verify CI Pipeline

After pushing, check GitHub → Actions tab. The CI pipeline should:
- ✅ Build Projection Service Docker image
- ✅ Build Frontend Docker image
- ⏭️ Auth Service tests (commented out until `composer install` runs in CI)

---

## VS Code Recommended Extensions

Install these via the Extensions panel (`Ctrl+Shift+X`):

| Extension | ID |
|-----------|-----|
| Vue - Official | `Vue.volar` |
| Tailwind CSS IntelliSense | `bradlc.vscode-tailwindcss` |
| PHP Intelephense | `bmewburn.vscode-intelephense-client` |
| ESLint | `dbaeumer.vscode-eslint` |
| Docker | `ms-azuretools.vscode-docker` |
| EditorConfig | `EditorConfig.EditorConfig` |
| GitLens | `eamodio.gitlens` |

## Daily Development Workflow

```bash
# Start your dev session
cd ~/saint-augustin
docker compose up -d        # or: make up

# Tail logs while working
make logs                   # all services
make auth-logs              # just auth service

# Run tests
make test                   # all tests
make test-auth              # auth only

# Reset database if needed
make fresh                  # migrate:fresh --seed

# Stop when done
docker compose down         # or: make down
```

## Production Deployment

The steps above stand up the **development** stack (root `docker-compose.yml`,
build contexts, dev defaults). For production, pick one of the two first-party
paths under `deployment/` (see [`deployment/README.md`](../deployment/README.md)
and [`infrastructure.md`](infrastructure.md)). Both deploy the same three app
images plus PostgreSQL/Redis/MinIO, run the queue worker, and apply the
`exports/` 48h MinIO lifecycle rule.

### Build & push the images (both paths)

Production pulls images by tag; it never builds from source. Build and push
them first, tagged with the `APP_VERSION` you will deploy:

```bash
export APP_VERSION=0.2
docker compose build                       # builds api / projection / frontend
docker push calebfonyuy/staug-api:$APP_VERSION
docker push calebfonyuy/staug-projection:$APP_VERSION
docker push calebfonyuy/staug-frontend:$APP_VERSION
```

### Option A — Kubernetes (`deployment/kubernetes/`)

```bash
cp deployment/kubernetes/01_t_secrets.example.yaml deployment/kubernetes/secrets.yaml
$EDITOR deployment/kubernetes/secrets.yaml          # fill APP_KEY, JWT_SECRET,
                                                    # STAUG_SIGNING_KEY, DB/MinIO/SMTP
# Review non-secret values (URLs, NodePorts) in 02_configmap.yaml, then:
kubectl apply -f deployment/kubernetes/00_namespace.yaml
kubectl apply -f deployment/kubernetes/secrets.yaml
kubectl apply -f deployment/kubernetes/          # config, infra, jobs, deployments
kubectl -n staug get pods
```

The `auth-migrate` and `minio-init` Jobs run once; the app is reachable on the
NodePorts documented in `infrastructure.md` (frontend `31000`, API `31001`,
projection `31002`).

### Option B — Production Docker Compose (`deployment/compose/`)

The promotion from the dev compose is: build+tag `APP_VERSION` (above), then
run the production compose with a filled `.env.prod`:

```bash
cd deployment/compose
cp .env.prod.example .env.prod
$EDITOR .env.prod                                   # set every REQUIRED value
docker compose -f docker-compose.prod.yml --env-file .env.prod config    # validate
docker compose -f docker-compose.prod.yml --env-file .env.prod up -d      # deploy
```

Unlike the dev compose, secrets are **fail-closed** (the stack refuses to start
if a required value is unset) and a one-shot `migrate` service runs
`migrate --force`/`db:seed --force` before the API and worker start. See
[`deployment/compose/README.md`](../deployment/compose/README.md) for day-2
operations and caveats.

## Troubleshooting

**Port already in use:**
```bash
sudo lsof -i :5432    # find what's using the port
# If system PostgreSQL is running:
sudo systemctl stop postgresql
sudo systemctl disable postgresql
```

**Docker permission denied:**
```bash
sudo usermod -aG docker $USER
newgrp docker   # or log out and back in
```

**Auth Service won't start (APP_KEY missing):**
```bash
cd services/auth
php artisan key:generate
cd ../..
docker compose restart auth-service
```

**PostgreSQL init script didn't run:**
```bash
# The init script only runs on first volume creation
docker compose down -v                  # removes volumes
docker compose up -d postgres           # recreates with init
```

**Frontend can't reach the backend:**
Check that all services are healthy, and that `VITE_API_BASE_URL` / `VITE_WS_URL` / `VITE_PROJECTION_BASE_URL` in `.env` point at the Auth/Projection service ports:
```bash
docker compose ps
docker compose logs auth-service
```

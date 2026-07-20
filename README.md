# SaintAugustin

> *"Qui cantat, bis orat"* — He who sings, prays twice

A web-based worship presentation and song management platform. Replaces desktop-bound tools like [VideoPsalm](https://videopsalm.org/) with a collaborative, browser-based solution featuring real-time chord transposition, playlist management, and live projection.

## Architecture

Three deployables, one shared database (see [ADR-0001](docs/adr/0001-unified-api-as-content-service.md) — the v1.2-planned per-domain microservice split is deferred):

- **API** (`services/auth`) — **Laravel 12** unified backend: auth, users, invitations, songs, songbooks, song sheets, playlists, playlist items, share links, imports/exports
- **Projection service** (`services/projection`) — **NestJS 10** — WebSocket real-time slide synchronization; also the system of record for sessions (Redis, not SQL)
- **Frontend** (`frontend`) — **Vue 3 + Vite + TypeScript** SPA with Tailwind CSS
- **PostgreSQL 16** (single shared database) + **Redis 7** (cache/broker/session state) + **MinIO** (S3 object storage)
- **Kubernetes** (recommended) or a standalone **production Docker Compose** stack in production; the root Docker Compose is for local dev (see [`deployment/`](deployment/) and [ADR-0002](docs/adr/0002-production-deployment-topology.md))

Full specifications: SaintAugustin SRS v0.2 (Drive) extends and amends the v1.2 baseline; see `docs/` for the as-built architecture.

## Prerequisites

| Tool | Version | Reference |
|------|---------|-----------|
| Docker Engine | 24+ | https://docs.docker.com/engine/install/debian/ |
| Docker Compose | v2 (plugin) | Bundled with Docker Engine |
| Node.js | 20 LTS | https://github.com/nodesource/distributions |
| PHP | 8.3 | https://packages.sury.org/php/ |
| Composer | 2.x | https://getcomposer.org/download/ |
| Git | 2.x | System package |

### Automated Setup (Debian 12)

```bash
sudo ./scripts/setup-debian.sh
# Log out and back in for docker group, then:
newgrp docker
```

This installs Docker, Node.js 20, PHP 8.3 with required extensions (`pdo_pgsql`, `redis`, `mbstring`, `zip`, `intl`, `opcache`, `pcntl`, `sockets`), and Composer.

## Quick Start

```bash
# 1. Clone and enter project
git clone <repo-url> saint-augustin
cd saint-augustin

# 2. Create environment files
cp .env.example .env
cp services/auth/.env.example services/auth/.env
# Edit .env if needed (passwords, etc.)

# 3. Install dependencies
cd services/auth && composer install && cd ../..
cd services/projection && npm i && cd ../..
cd frontend && npm i && cd ..

# 4. Generate Laravel app key
cd services/auth
php artisan key:generate
cd ../..

# 5. Start everything
docker compose up -d

# 6. Wait for PostgreSQL, then run migrations and seed
sleep 10
docker compose exec auth-service php artisan migrate --seed

# 7. Verify
curl http://localhost:8000/health          # Auth Service health
curl http://localhost:8000/api/auth/status  # Auth API status
open http://localhost:5173                  # Frontend
```

Or use the Makefile shortcut:

```bash
make env
make setup
make migrate seed
```

## Services & Ports

There is no API gateway container — the frontend calls the Auth Service and Projection Service directly (see `docs/infrastructure.md`).

| Service | Container | Port | URL |
|---------|-----------|------|-----|
| **Frontend** (Vite) | staug-frontend | 5173 | http://localhost:5173 |
| **Auth Service** | staug-api | 8000 | http://localhost:8000/api/... |
| **Projection Service** | staug-projection | 3000 | http://localhost:3000 |
| **Queue Worker** | staug-queue-worker | — | background job processor, no HTTP port |
| **PostgreSQL** | staug-postgres | 5432 | `psql -h localhost -U saintaugustin` |
| **Redis** | staug-redis | 6379 | `redis-cli -h localhost` |
| **MinIO** (S3) | staug-minio | 9000 | http://localhost:9000 |
| **MinIO Console** | staug-minio | 9001 | http://localhost:9001 |

## Project Structure

```
saint-augustin/
├── .env.example                       # Global environment config
├── .editorconfig                      # Consistent formatting
├── docker-compose.yml                 # Full local stack
├── Makefile                           # Dev commands (make help)
├── docker/
│   ├── nginx/default.conf             # API gateway routing
│   └── postgres/init-databases.sh     # Creates per-service databases
├── scripts/
│   └── setup-debian.sh                # Debian dev server provisioning
├── services/
│   ├── auth/                          # Laravel 12 – unified API (see ADR-0001)
│   │   ├── app/Models/User.php        # UUID, roles as JSON array
│   │   ├── config/                    # DB, Redis, Sanctum, hashing
│   │   ├── database/migrations/       # Users, songs, playlists, share links, etc.
│   │   ├── routes/api.php             # /api/* endpoints
│   │   └── tests/                     # Pest test suite
│   └── projection/                    # NestJS 10 – Projection Service
│       └── src/
│           ├── sessions/              # Live/scheduled sessions, control tokens (Redis)
│           └── projection.gateway.ts  # WebSocket slide sync
├── frontend/                          # Vue 3 + Vite + TypeScript
│   └── src/
│       ├── App.vue                    # App shell
│       ├── stores/                    # Pinia stores
│       └── views/                     # One component per route
├── deployment/                        # Prod deploy: kubernetes/ · compose/ · apache-VM/
├── charts/                            # Placeholder — Helm chart deferred (see ADR-0002)
└── .github/workflows/ci.yml           # Lint + test + Docker build
```

## Development Workflow

### Remote Development (VS Code + SSH)

This project is designed for remote development on a Debian server:

1. Install [Remote - SSH](https://marketplace.visualstudio.com/items?itemName=ms-vscode-remote.remote-ssh) extension in VS Code
2. Connect to your Debian server via SSH
3. Open the `saint-augustin/` folder
4. Recommended VS Code extensions:
   - **Vue - Official** (`Vue.volar`)
   - **Tailwind CSS IntelliSense** (`bradlc.vscode-tailwindcss`)
   - **PHP Intelephense** (`bmewburn.vscode-intelephense-client`)
   - **ESLint** (`dbaeumer.vscode-eslint`)
   - **Docker** (`ms-azuretools.vscode-docker`)

### Common Commands

```bash
make help              # Show all available commands
make up                # Start all services
make down              # Stop all services
make logs              # Tail all logs
make auth-logs         # Tail auth service logs
make test              # Run all tests
make lint              # Run all linters
make fresh             # Reset database (migrate:fresh --seed)
make db-shell          # Open psql console
```

### Hot Reload

- **Frontend**: Vite HMR — edit `.vue`/`.ts` files and see changes instantly
- **Auth Service**: no bind mount / watch process in `docker-compose.yml` — source is baked into the image at build time, so `docker compose build auth-service && docker compose up -d auth-service` after editing PHP
- **Projection Service**: no bind mount / watch process in `docker-compose.yml` either — built once at image build time (`npm run build`), so rebuild+restart after editing `.ts` files

### Database Access

```bash
# Connect to PostgreSQL
make db-shell

# Inside psql:
\l                    -- list databases (saintaugustin_db)
\c saintaugustin_db            -- connect to auth database
\dt                   -- list tables
SELECT * FROM users;  -- query users
```

### MinIO Object Storage

Access the MinIO web console at http://localhost:9001 (credentials in `.env`). The `saintaugustin` bucket is auto-created on first start. MinIO is S3-compatible — swapping to AWS S3 in production requires only an endpoint change ([MinIO docs](https://min.io/docs/minio/kubernetes/upstream/)).

## Default Users (Development)

After running `make seed`:

| Email | Password | Roles |
|-------|----------|-------|
| admin@saintaugustin.local | password | Admin, Musician, Projectionist |
| musician@saintaugustin.local | password | Musician |
| projectionist@saintaugustin.local | password | Projectionist |

## Development Phases

v0.1 phases (see `docs/overview.md` for detail):

| Phase | Name | Status |
|-------|------|--------|
| **0** | Scaffolding, Docker Compose, CI pipeline | ✅ Done |
| **1** | Auth, Songs, Songbooks, frontend shell | ✅ Done |
| **2** | Musician view, ChordPro parser, song sheets (MinIO) | ✅ Done |
| **3** | Playlists, Playlist Builder, share links, PDF export | ✅ Done |
| **4** | Live projection (WebSocket sessions, slide renderer) | 🔄 In progress |
| **5+** | Import service, mobile optimisation, offline mode | ⬜ Planned |

v0.2 (defect fixes, Bible workstream, STAUG data-interchange) is tracked separately — see `SaintAugustin_Implementation_v0.2.md` (Drive) and this repo's `v0.2/stage-N-*` branches.

## Key References

- [Laravel 12 Documentation](https://laravel.com/docs/12.x)
- [Laravel Octane](https://laravel.com/docs/12.x/octane)
- [Laravel Sanctum](https://laravel.com/docs/12.x/sanctum)
- [NestJS WebSockets](https://docs.nestjs.com/websockets/gateways)
- [Vue 3 Composition API](https://vuejs.org/guide/introduction.html)
- [Vite](https://vite.dev/guide/)
- [Pinia State Management](https://pinia.vuejs.org/)
- [Tonal.js (transposition)](https://github.com/tonaljs/tonal)
- [Tailwind CSS](https://tailwindcss.com/docs)
- [PostgreSQL 16](https://www.postgresql.org/docs/16/)
- [Redis Streams](https://redis.io/docs/data-types/streams/)
- [MinIO on Kubernetes](https://min.io/docs/minio/kubernetes/upstream/)
- [Docker Compose](https://docs.docker.com/compose/compose-file/)
- [Kubernetes](https://kubernetes.io/docs/)
- [Helm Charts](https://helm.sh/docs/)
- [ChordPro Format](https://www.chordpro.org/)
- [Socket.IO](https://socket.io/docs/v4/)

## License

Internal — proprietary.

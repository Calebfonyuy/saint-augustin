# SaintAugustin

> *"Qui cantat, bis orat"* — He who sings, prays twice

A web-based worship presentation and song management platform. Replaces desktop-bound tools like [VideoPsalm](https://videopsalm.org/) with a collaborative, browser-based solution featuring real-time chord transposition, playlist management, and live projection.

## Architecture

Microservice architecture with a hybrid backend:

- **5× Laravel 12** services (Auth, Song, Playlist, File, Import) — CRUD-heavy REST APIs
- **1× NestJS 10** service (Projection) — WebSocket real-time slide synchronization
- **Vue 3 + Vite + TypeScript** SPA frontend with Tailwind CSS
- **PostgreSQL 16** (database-per-service) + **Redis 7** (cache/broker) + **MinIO** (S3 object storage)
- **Nginx** API gateway → **Kubernetes** in production (Docker Compose for local dev)

Full specifications: `SaintAugustin_SRS_v1.2.docx`, `SaintAugustin_TechStack_v1.0.docx`, `SaintAugustin_DevPhases_v1.0.docx`

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
curl http://localhost:8080/health          # Auth Service health
curl http://localhost:8080/api/auth/status  # Auth API status
open http://localhost:5173                  # Frontend (direct)
open http://localhost:8080                  # Frontend (via gateway)
```

Or use the Makefile shortcut:

```bash
make env
make setup
make migrate seed
```

## Services & Ports

| Service | Container | Port | URL |
|---------|-----------|------|-----|
| **Gateway** (Nginx) | sa-gateway | 8080 | http://localhost:8080 |
| **Frontend** (Vite) | sa-frontend | 5173 | http://localhost:5173 |
| **Auth Service** | sa-auth | 8000 (internal) | via gateway: `/api/auth/*` |
| **Projection Service** | sa-projection | 3000 (internal) | via gateway: `/ws/projection/*` |
| **PostgreSQL** | sa-postgres | 5432 | `psql -h localhost -U saintaugustin` |
| **Redis** | sa-redis | 6379 | `redis-cli -h localhost` |
| **MinIO** (S3) | sa-minio | 9000 | http://localhost:9000 |
| **MinIO Console** | sa-minio | 9001 | http://localhost:9001 |

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
│   ├── auth/                          # Laravel 12 – Auth Service
│   │   ├── app/Models/User.php        # UUID, roles as JSON array
│   │   ├── config/                    # DB, Redis, Sanctum, Octane, hashing
│   │   ├── database/migrations/       # Users + Sanctum tokens
│   │   ├── routes/api.php             # /api/auth/* endpoints
│   │   └── tests/                     # Pest: health + user model tests
│   ├── projection/                    # NestJS 10 – Projection Service
│   │   └── src/
│   │       ├── projection.gateway.ts  # WebSocket echo + rooms (Phase 0 spike)
│   │       └── health.controller.ts   # GET /health
│   ├── song/                          # Phase 1
│   ├── playlist/                      # Phase 3
│   ├── file/                          # Phase 2
│   └── import/                        # Phase 5
├── frontend/                          # Vue 3 + Vite + TypeScript
│   └── src/
│       ├── App.vue                    # Shell with header
│       ├── stores/auth.ts             # Pinia auth store skeleton
│       └── views/HomeView.vue         # Service health dashboard
├── charts/                            # Helm charts (Phase 6)
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
- **Auth Service**: Laravel Octane `--watch` — restarts workers on file change
- **Projection Service**: NestJS `--watch` — recompiles on `.ts` file change

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

| Phase | Name | Status |
|-------|------|--------|
| **0** | Project Scaffolding | ✅ Current |
| **1** | Foundation (Auth + Song CRUD + UI) | ⬜ Next |
| **2** | Musician Experience (Chords, transposition) | ⬜ |
| **3** | Playlists & Collaboration | ⬜ |
| **4** | Projection Engine (WebSocket live) | ⬜ |
| **5** | Import & Migration (VideoPsalm) | ⬜ |
| **6** | Polish & Deploy (i18n, Kubernetes) | ⬜ |

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

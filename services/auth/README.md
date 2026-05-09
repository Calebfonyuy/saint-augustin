# Auth Service

Laravel 12 + Octane (FrankenPHP) on PHP 8.3. Handles authentication, user accounts, JWT issuance, and acts as the API host for the song, playlist, file, and import domains until those services are extracted.

## Prerequisites

- PHP 8.3 with extensions: `pdo_pgsql`, `redis`, `mbstring`, `zip`, `intl`, `pcntl`, `sockets`
- Composer 2
- PostgreSQL 16 running and accessible
- Redis 7 running and accessible

For local development the root `docker-compose.yml` starts all infrastructure. The instructions below assume you want to run this service **outside** Docker.

---

## Environment Setup

```bash
cd services/auth
cp .env.example .env
```

Open `.env` and set at minimum:

| Variable | Purpose | Example |
|---|---|---|
| `DB_HOST` | PostgreSQL host | `127.0.0.1` |
| `DB_PORT` | PostgreSQL port | `5432` |
| `DB_DATABASE` | Database name | `saintaugustin_db` |
| `DB_USERNAME` | DB user | `saintaugustin` |
| `DB_PASSWORD` | DB password | *(see root .env.example)* |
| `REDIS_HOST` | Redis host | `127.0.0.1` |
| `REDIS_PORT` | Redis port | `6379` |
| `JWT_SECRET` | 32+ char random string | `php artisan key:generate --show` |
| `APP_URL` | Base URL of this service | `http://localhost:8001` |

Generate the application key:

```bash
php artisan key:generate
```

Install PHP dependencies:

```bash
composer install
```

Run migrations:

```bash
php artisan migrate
```

---

## Starting the Service

### Development (with hot reload via Octane watch)

```bash
php artisan octane:start --watch --host=0.0.0.0 --port=8001
```

The `--watch` flag requires `chokidar`. If not installed:

```bash
npm install --save-dev chokidar
```

### Development (standard PHP dev server, no Octane)

```bash
php artisan serve --host=0.0.0.0 --port=8001
```

Use this if FrankenPHP is not installed locally. Functionally equivalent for development — the only difference is the underlying HTTP server.

### Production (via Docker)

```bash
# From project root
docker compose up sa-auth
```

The Dockerfile uses FrankenPHP in production mode with an optimised autoloader.

---

## Debugging

### Telescope (request/query inspector)

Laravel Telescope is available in local environments. Once the service is running:

```
http://localhost:8001/telescope
```

Telescope shows every HTTP request, database query, log entry, job, and exception with full context.

### Logs

```bash
tail -f storage/logs/laravel.log
```

Set `LOG_LEVEL=debug` in `.env` to increase verbosity. This shows query bindings, resolved bindings, and gate decisions.

### Artisan REPL (Tinker)

```bash
php artisan tinker
```

Useful for inspecting model state, testing service methods, or firing events interactively:

```php
// Check a user record
App\Models\User::find(1)

// Inspect a JWT
app(\App\Services\JwtService::class)->decode('eyJ...')
```

### IDE debugger (Xdebug)

Set these in `.env` (or as server env vars):

```ini
XDEBUG_MODE=debug
XDEBUG_CONFIG=client_host=host.docker.internal
```

Then launch a debug session from PhpStorm or VS Code with the Listen for Connections configuration. Breakpoints in `app/` are hit on the next HTTP request.

---

## Running Tests

Tests use **Pest** (a Laravel-native PHPUnit wrapper). A dedicated test database is required.

### One-time test database setup

```bash
# Create the test database (once)
psql -U saintaugustin -c "CREATE DATABASE saintaugustin_db_test;"

# Run migrations against the test database
php artisan migrate --env=testing
```

### Run the full suite

```bash
composer test
# expands to: vendor/bin/pest
```

### Run a single test file

```bash
vendor/bin/pest tests/Feature/AuthControllerTest.php
```

### Run tests matching a description

```bash
vendor/bin/pest --filter "can register a new user"
```

### Run with coverage report

```bash
vendor/bin/pest --coverage --min=80
```

Requires `pcov` or `xdebug` extension to be installed.

---

## Investigating Failing Tests

### Verbose output

```bash
vendor/bin/pest --verbose
```

Shows each test name and its pass/fail status. Without `--verbose`, Pest only prints dots.

### Stop on first failure

```bash
vendor/bin/pest --stop-on-failure
```

Prevents later test output from scrolling past the first error.

### Dump SQL queries executed during a test

Add to the test body:

```php
DB::listen(fn($q) => dump($q->sql, $q->bindings));
```

Or enable the query log globally in `tests/TestCase.php`:

```php
DB::enableQueryLog();
// ... test body ...
dd(DB::getQueryLog());
```

### Inspect a test database after a failure

By default, Pest uses `RefreshDatabase` which wraps each test in a transaction that is rolled back. To leave data in place for inspection, swap the trait in the failing test class:

```php
// Change:
use RefreshDatabase;
// To:
use DatabaseTransactions; // or remove the trait entirely
```

Then connect to `saintaugustin_db_test` and inspect the tables:

```bash
psql -U saintaugustin -d saintaugustin_db_test
```

### Authentication failures in feature tests

The service uses Sanctum for stateless Bearer token auth. Ensure test cases that call authenticated routes use:

```php
$this->actingAs($user, 'sanctum')
```

Not `actingAs($user)` alone — that defaults to the `web` guard which uses sessions.

---

## Code Quality

```bash
# Lint (check only, no changes)
composer lint

# Lint with auto-fix
composer lint:fix

# Static analysis (PHPStan via Larastan)
composer analyse
```

The linter is configured in `pint.json` (PSR-12 preset). The static analyser is configured in `phpstan.neon`.

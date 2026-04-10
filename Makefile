# =============================================================================
# SaintAugustin – Development Commands
# =============================================================================

.DEFAULT_GOAL := help

# ── Infrastructure ───────────────────────────────────────────────────────────

.PHONY: up down restart logs ps

up: ## Start all services
	docker compose up -d

down: ## Stop all services
	docker compose down

restart: ## Restart all services
	docker compose restart

logs: ## Tail all logs
	docker compose logs -f

ps: ## Show running containers
	docker compose ps

# ── Individual Services ──────────────────────────────────────────────────────

.PHONY: auth-logs auth-shell projection-logs frontend-logs

auth-logs: ## Tail auth service logs
	docker compose logs -f auth-service

auth-shell: ## Open shell in auth service container
	docker compose exec auth-service sh

projection-logs: ## Tail projection service logs
	docker compose logs -f projection-service

frontend-logs: ## Tail frontend logs
	docker compose logs -f frontend

# ── Database ─────────────────────────────────────────────────────────────────

.PHONY: db-shell migrate seed fresh

db-shell: ## Open psql shell
	docker compose exec postgres psql -U saintaugustin

migrate: ## Run auth service migrations
	docker compose exec auth-service php artisan migrate

seed: ## Run auth service seeders
	docker compose exec auth-service php artisan db:seed

fresh: ## Drop and re-run all migrations + seed
	docker compose exec auth-service php artisan migrate:fresh --seed

# ── Testing ──────────────────────────────────────────────────────────────────

.PHONY: test test-auth test-projection lint

test: test-auth test-projection ## Run all tests

test-auth: ## Run auth service tests
	docker compose exec auth-service vendor/bin/pest

test-projection: ## Run projection service tests
	docker compose exec projection-service npm test

lint: ## Run all linters
	docker compose exec auth-service vendor/bin/pint --test
	docker compose exec projection-service npm run lint
	cd frontend && npm run lint

# ── MinIO ────────────────────────────────────────────────────────────────────

.PHONY: minio-console

minio-console: ## Open MinIO console URL
	@echo "MinIO Console: http://localhost:9001"
	@echo "Login: minioadmin / minioadmin_changeme"

# ── Setup ────────────────────────────────────────────────────────────────────

.PHONY: setup env

setup: env ## First-time setup
	cd services/auth && composer install
	cd services/projection && npm i
	cd frontend && npm i
	docker compose up -d postgres redis minio
	@echo "Waiting for PostgreSQL..."
	@sleep 5
	docker compose up -d
	@echo ""
	@echo "✅ Setup complete. Run 'make migrate seed' to initialize the database."

env: ## Create .env from .env.example
	@test -f .env || (cp .env.example .env && echo "Created .env from .env.example")
	@test -f services/auth/.env || (cp services/auth/.env.example services/auth/.env && echo "Created services/auth/.env")

# ── Help ─────────────────────────────────────────────────────────────────────

.PHONY: help

help: ## Show this help
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) | sort | \
		awk 'BEGIN {FS = ":.*?## "}; {printf "\033[36m%-20s\033[0m %s\n", $$1, $$2}'

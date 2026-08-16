# Project Management System — task runner
#
# Run `make` or `make help` to list available targets.
# Targets that take arguments use variables, e.g.:
#   make console c="debug:router"
#   make composer-require p="symfony/uid"
#   make npm-add p="react-router"
#
# Set TTY=-T to disable TTY allocation (useful in CI or when piping output):
#   make TTY=-T migrate

TTY      ?=
DC       := docker compose
EXEC     := $(DC) exec $(TTY)
BACKEND  := $(EXEC) backend
FRONTEND := $(EXEC) frontend
CONSOLE  := $(BACKEND) php bin/console

.DEFAULT_GOAL := help

##@ General

.PHONY: help
help: ## Show this help
	@awk 'BEGIN {FS = ":.*##"; printf "\nUsage:\n  make \033[36m<target>\033[0m\n"} \
		/^[a-zA-Z_0-9-]+:.*?##/ { printf "  \033[36m%-18s\033[0m %s\n", $$1, $$2 } \
		/^##@/ { printf "\n\033[1m%s\033[0m\n", substr($$0, 5) }' $(MAKEFILE_LIST)
	@echo ""

##@ Docker

.PHONY: up
up: ## Start all services in the background
	$(DC) up -d

.PHONY: build
build: ## Rebuild images and start all services
	$(DC) up -d --build

.PHONY: down
down: ## Stop and remove containers
	$(DC) down

.PHONY: down-v
down-v: ## Stop containers and wipe the Postgres volume
	$(DC) down -v

.PHONY: restart
restart: down up ## Restart all services

.PHONY: ps
ps: ## Show service status
	$(DC) ps

.PHONY: logs
logs: ## Tail logs for all services
	$(DC) logs -f --tail=100

.PHONY: logs-backend
logs-backend: ## Tail backend (php-fpm) logs
	$(DC) logs -f --tail=100 backend

.PHONY: logs-frontend
logs-frontend: ## Tail frontend (vite) logs
	$(DC) logs -f --tail=100 frontend

.PHONY: logs-nginx
logs-nginx: ## Tail nginx logs
	$(DC) logs -f --tail=100 nginx

##@ Shells

.PHONY: sh-backend
sh-backend: ## Open a shell in the backend container
	$(DC) exec backend sh

.PHONY: sh-frontend
sh-frontend: ## Open a shell in the frontend container
	$(DC) exec frontend sh

.PHONY: psql
psql: ## Open a psql session on the pms database
	$(DC) exec database psql -U pms -d pms

##@ Symfony

.PHONY: console
console: ## Run a Symfony console command: make console c="debug:router"
	$(CONSOLE) $(c)

.PHONY: cache-clear
cache-clear: ## Clear the Symfony cache
	$(CONSOLE) cache:clear

.PHONY: routes
routes: ## List registered routes
	$(CONSOLE) debug:router

.PHONY: composer-install
composer-install: ## Install backend PHP dependencies
	$(BACKEND) composer install

.PHONY: composer-require
composer-require: ## Add a PHP package: make composer-require p="symfony/uid"
	$(BACKEND) composer require $(p)

.PHONY: composer-update
composer-update: ## Update backend PHP dependencies
	$(BACKEND) composer update

##@ Database

.PHONY: migration
migration: ## Generate a migration from entity changes
	$(CONSOLE) doctrine:migrations:diff

.PHONY: migrate
migrate: ## Apply pending migrations
	$(CONSOLE) doctrine:migrations:migrate --no-interaction

.PHONY: migrate-status
migrate-status: ## Show migration status
	$(CONSOLE) doctrine:migrations:status

.PHONY: db-create
db-create: ## Create the database if it does not exist
	$(CONSOLE) doctrine:database:create --if-not-exists

.PHONY: db-drop
db-drop: ## Drop the database
	$(CONSOLE) doctrine:database:drop --force --if-exists

.PHONY: db-reset
db-reset: db-drop db-create migrate ## Drop, recreate and migrate the database

.PHONY: fixtures
fixtures: ## Load Doctrine fixtures (wipes existing data)
	$(CONSOLE) doctrine:fixtures:load --no-interaction

##@ Frontend

.PHONY: npm-install
npm-install: ## Install frontend dependencies
	$(FRONTEND) npm install

.PHONY: npm-add
npm-add: ## Add an npm package: make npm-add p="react-router"
	$(FRONTEND) npm install $(p)

.PHONY: lint
lint: ## Lint the frontend (oxlint)
	$(FRONTEND) npm run lint

.PHONY: typecheck
typecheck: ## Type-check the frontend
	$(FRONTEND) npx tsc -b

.PHONY: frontend-build
frontend-build: ## Production build of the frontend
	$(FRONTEND) npm run build

##@ Testing

.PHONY: test
test: ## Run the PHPUnit suite: make test a="--filter testPostCreatesProject"
	$(BACKEND) php bin/phpunit $(a)

.PHONY: test-db
test-db: ## Create and migrate the test database
	$(CONSOLE) --env=test doctrine:database:create --if-not-exists
	$(CONSOLE) --env=test doctrine:migrations:migrate --no-interaction

##@ Utilities

.PHONY: health
health: ## Curl the API health endpoint
	@curl -fsS http://localhost:8000/api/health && echo "" || echo "API not reachable on http://localhost:8000"

.PHONY: install
install: build migrate ## First-time setup: build images, start services, migrate
	@echo ""
	@echo "Frontend  http://localhost:5174"
	@echo "API       http://localhost:8000"

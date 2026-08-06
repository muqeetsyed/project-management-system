# Project Management System

Base project setup: Symfony (API) + React/TypeScript (frontend) + PostgreSQL, wired together with Docker Compose.

## Stack

- **backend/** — Symfony 7 skeleton, Doctrine ORM (PostgreSQL), Doctrine Migrations, Nelmio CORS bundle
- **frontend/** — React + TypeScript, scaffolded with Vite
- **PostgreSQL 16** — via Docker
- **Nginx** — serves the Symfony app, proxies PHP to the `backend` (php-fpm) container

## Requirements

- Docker + Docker Compose

No local PHP, Composer, or PostgreSQL install needed — everything runs in containers.

## Running

```bash
make install     # build images, start services, run migrations
```

Or with Docker Compose directly:

```bash
docker compose up -d --build
```

Services:

| Service  | URL                            |
|----------|---------------------------------|
| Frontend | http://localhost:5174           |
| Backend API | http://localhost:8000        |
| Health check | http://localhost:8000/api/health |
| PostgreSQL | localhost:5432 (db: `pms`, user: `pms`, password: `pms`) |

The frontend calls `/api/health` on load and shows the API + database connection status.

## Common commands

Everything is wrapped in the `Makefile`. Run `make` (or `make help`) for the full list.

```bash
make up                          # start services
make down                        # stop and remove containers
make down-v                      # also wipe the Postgres volume
make logs                        # tail all logs (also logs-backend, logs-frontend, logs-nginx)

make console c="debug:router"    # any Symfony console command
make cache-clear

make migration                   # generate a migration from entity changes
make migrate                     # apply pending migrations
make db-reset                    # drop, recreate, migrate
make fixtures                    # load Doctrine fixtures

make composer-require p="symfony/uid"
make npm-add p="react-router"
make lint                        # oxlint on the frontend
make typecheck

make sh-backend                  # shell into a container (also sh-frontend)
make psql                        # psql session on the pms database
make health                      # curl the API health endpoint
```

Add `TTY=-T` when running non-interactively (CI, piped output), e.g. `make TTY=-T migrate`.

## Project layout

```
project-management-system/
├── backend/          # Symfony application
├── frontend/         # React + TypeScript application (Vite)
├── docker/
│   ├── php/          # PHP-FPM Dockerfile
│   └── nginx/        # Nginx site config
├── docker-compose.yml
└── Makefile          # task runner — `make help`
```

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

```bash
# Symfony console (e.g. doctrine migrations)
docker compose exec backend php bin/console <command>

# Create a migration after adding/editing entities in backend/src/Entity
docker compose exec backend php bin/console make:migration
docker compose exec backend php bin/console doctrine:migrations:migrate

# Frontend package install (after adding dependencies)
docker compose exec frontend npm install

# Tear down
docker compose down          # stop and remove containers
docker compose down -v       # also wipe the Postgres volume
```

## Project layout

```
project-management-system/
├── backend/          # Symfony application
├── frontend/         # React + TypeScript application (Vite)
├── docker/
│   ├── php/          # PHP-FPM Dockerfile
│   └── nginx/        # Nginx site config
└── docker-compose.yml
```

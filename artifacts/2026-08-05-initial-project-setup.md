# 2026-08-05 — Initial project setup

## Goal

Bootstrap a base "Project Management System" project with Symfony (backend), React (frontend), and PostgreSQL — a clean starting point to build on, not a finished product.

## Decisions & why

**Docker Compose for everything, not local installs.**
The machine had no PHP, Composer, or PostgreSQL installed locally (only Docker and Node). Rather than asking the user to install a PHP toolchain, the whole backend/database stack runs in containers — `docker compose up -d --build` is the only requirement. Composer itself was even run through a throwaway `composer:2` container to scaffold the Symfony skeleton, since there was no local PHP to run it with.

**Symfony skeleton + Doctrine ORM + Nelmio CORS, not a heavier stack (e.g. API Platform).**
The ask was a "basic setup," so kept to `symfony/skeleton` plus the minimum needed to talk to Postgres from a separate-origin React app: `symfony/orm-pack` (Doctrine ORM + Migrations), `symfony/serializer-pack`, and `nelmio/cors-bundle`. No business entities were added — just a `/api/health` endpoint that runs `SELECT 1` against the database, so the base project proves connectivity without presuming what the app will actually manage.

**React + TypeScript via Vite, calling the API from a separate origin.**
User chose TypeScript over plain JS when asked. Frontend and backend are decoupled services (frontend on its own dev server, backend behind Nginx) rather than Symfony serving bundled frontend assets — this keeps the two independently deployable, which is the more common shape for this stack. CORS is configured via an env var (`CORS_ALLOW_ORIGIN`) read by `nelmio_cors.yaml`, so the allowed origin lives in `docker-compose.yml` next to the port it corresponds to.

**Nginx + php-fpm rather than Symfony's built-in dev server.**
Slightly more setup than `symfony server:start`, but avoids installing the Symfony CLI at all and mirrors a more production-like request path (nginx → fastcgi → php-fpm).

**Removed Symfony's auto-generated `backend/compose.yaml` and `compose.override.yaml`.**
The `doctrine/doctrine-bundle` recipe scaffolds its own standalone Postgres compose file when installed. Since this project already has its own root `docker-compose.yml` orchestrating everything (including Postgres), those recipe-generated files were dead weight that could confuse a future contributor into running a second, conflicting Postgres. Deleted before the initial commit.

## Issues encountered

- **Stale local Docker image mistagged as `php:8.4-fpm-alpine`.** The first backend image build silently used a locally-cached image under that tag which actually contained PHP 8.3 internals (mismatched with what Composer had resolved dependencies against, which required PHP ≥ 8.4). `php -v` inside the container kept reporting 8.3.33 even after changing the Dockerfile's `FROM` line and rebuilding. Fixed by rebuilding with `docker compose build --no-cache --pull backend`, which forces a fresh pull from the registry instead of trusting the local tag.
- **Port 5173 already in use.** Another local project (`qbil-trade-php-1`) already had host port 5173 bound for its own Vite server. Remapped this project's frontend to host port **5174** (`"5174:5173"` in `docker-compose.yml`) and updated `CORS_ALLOW_ORIGIN` and the README to match.
- **Frontend container had broken DNS, so `npm install` hung indefinitely.** The container's `/etc/resolv.conf` pointed at an unreachable stub resolver (`127.0.0.53`), so it couldn't resolve `registry.npmjs.org` — `npm install` doesn't fail fast on this, it just hangs. Force-recreating the container (`docker compose up -d --force-recreate frontend`) gave it fresh network config and fixed it. Confirmed not a fluke by tearing the whole stack down and bringing it up clean afterward — it came up correctly in one shot.

## Result / how to verify

```
docker compose up -d --build
curl http://localhost:8000/api/health   # {"status":"ok","database":"connected"}
open http://localhost:5174              # frontend, shows API + DB status
```

Repo: https://github.com/muqeetsyed/project-management-system (public, `main` branch). Initial commit `3f169ce`.

See the root `README.md` for the full command reference (migrations, package installs, teardown).

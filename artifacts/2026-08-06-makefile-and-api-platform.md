# 2026-08-06 — Makefile task runner, and getting API Platform to serve

## Goal

Two pieces of work in one session:

1. Wrap the project's day-to-day commands in a `Makefile`, so they stop being long `docker compose exec …` invocations typed from memory.
2. Get API Platform actually serving — it had been added to `composer.json` but every browser request to `/api` returned a 400.

## What was done

- Added a root `Makefile` (~40 targets) covering Docker lifecycle, container shells, Symfony console, Doctrine migrations, and frontend tooling. `make` with no arguments prints self-documenting help grouped by section.
- Rewrote the README's "Common commands" section around `make`, and added the `Makefile` to the project layout tree.
- Installed `symfony/twig-bundle` to fix the API Platform HTML serialization error (see *Issues encountered*).

Commits: `aa7efd3` (Makefile + README), `bc9e8d1` (API Platform + Twig).

## Decisions & why

**A Makefile, not shell scripts or npm scripts.**
The commands span two containers and two ecosystems (Composer/Symfony and npm/Vite), so neither `package.json` scripts nor `composer.json` scripts could own all of them without one reaching awkwardly into the other's territory. Make sits above both and is already installed everywhere.

**Self-documenting help via `##` comments, with `help` as `.DEFAULT_GOAL`.**
Each target carries its own description on the same line, so help output can't drift out of sync with the targets the way a hand-maintained list would. Bare `make` prints it rather than doing something — a safe default for a file that also contains `down-v` and `db-reset`.

**Arguments passed as variables (`c=`, `p=`), not as make goals.**
`make console c="debug:router"` rather than the `%:` catch-all-target trick that lets you write `make console debug:router`. The catch-all approach reads more naturally but makes every typo'd target name silently succeed as a no-op, which is a bad trade in a file people run destructive targets from.

**`TTY=-T` as an explicit opt-out rather than auto-detection.**
The first draft auto-detected an interactive terminal with `$(shell test -t 0 && echo tty)` and added `-T` when absent. That is broken: the `$(shell …)` sub-shell never has the terminal on stdin, so the check reports "not a TTY" *always*, silently forcing `-T` even in interactive use. It was also written as a tab-indented `ifeq` block before the first target, which GNU Make would reject outright. Replaced with a plain `TTY ?=` variable — no detection, no lying. Interactive by default; `make TTY=-T migrate` in CI or when piping.

**`doctrine:migrations:diff` for `make migration`, not `make:migration`.**
The README had documented `make:migration`, but `symfony/maker-bundle` isn't a dependency of this project, so that command doesn't exist here. The migrations bundle's own `diff` does the same job with what's actually installed. README corrected to match.

**`make` documented alongside `docker compose`, not as a replacement.**
The README still shows the raw `docker compose up -d --build` under Running. The Makefile is a convenience over the compose file, not an abstraction hiding it — someone should be able to work in this repo without reading the Makefile first.

## Issues encountered

**`Serialization for the format "html" is not supported` on every browser request to `/api`.**

Symptom: `GET /api` from a browser returned HTTP 400 with an API Platform error body, trace pointing at `api-platform/state/Processor/SerializeProcessor.php:85`. API clients sending `Accept: application/ld+json` were fine; `/api/health` was fine.

Diagnosis, in order:

- `debug:config api_platform formats` showed **only** `jsonld` registered. So `html` was not a configured format — meaning normal content negotiation should have rejected the request with **406 Not Acceptable**. Getting a **400 serialization failure** instead was the real clue: something *accepted* the HTML request and carried it all the way to the serializer before anything tried to encode it.
- API Platform's own source ties the two together. `ApiPlatformExtension.php:153-157` carries the comment *"Disabling docs is a master switch: also disable Swagger UI and ReDoc to prevent HTML documentation from being served on resource endpoints."* Swagger UI is what puts HTML on those endpoints, and `debug:router` confirmed the docs routes (`api_doc`, `api_entrypoint`) were live.
- Swagger UI renders through Twig (`api-platform/symfony/Bundle/Resources/views/SwaggerUi/`). This project is an API-only Symfony skeleton — `vendor/twig` and `vendor/symfony/twig-bundle` did not exist.

Root cause: Swagger UI is enabled by default and registers `html` as a documentation format, but its renderer is Twig, which wasn't installed. Fixed by installing `symfony/twig-bundle`. No configuration changed.

**Why the error message was unhelpful.** GraphiQL guards itself on Twig's presence and throws a clear *"GraphiQL interfaces depend on Twig. Please activate TwigBundle…"* (`ApiPlatformExtension.php:792`). The Swagger UI service files at lines 674-682 load **unconditionally**, with no equivalent check. So `SwaggerUiProvider` handled the HTML request, found no Twig to render with, and execution fell through to the serializer — producing a serializer error instead of "you need Twig." Worth remembering generally: **a 400 from the serializer rather than a 406 means a component registered a format it can't actually render.**

**Missing `api-platform/doctrine-orm`.** Before the above, the console wouldn't boot at all — `"Doctrine support cannot be enabled as the doctrine ORM component is not installed."` `api-platform/symfony` was present but the ORM bridge is a separate package. Added to `composer.json` as `^4.3`.

## Result / how to verify

```
make install          # build, start, migrate
make                  # list all targets

curl -H 'Accept: text/html'            http://localhost:8000/api   # 200, Swagger UI
curl -H 'Accept: application/ld+json'  http://localhost:8000/api   # 200, JSON-LD entrypoint
curl                                   http://localhost:8000/api/health
```

Content negotiation is intact — browsers get the docs page, API clients still get JSON-LD.

Note that Swagger UI currently lists **no endpoints** (`"paths":[]`). That's expected: `backend/src/Entity/` and `backend/src/ApiResource/` are both still empty, so nothing carries an `#[ApiResource]` attribute yet. The `.gitignore` inside `src/ApiResource/` is an empty placeholder from the API Platform Flex recipe, there only to keep the directory tracked — it can go once a real resource lands.

See the root `README.md` for the command reference.

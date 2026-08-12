# Next Steps

A plain-language plan for what to build next, in the order that makes sense.
Each step says **what**, **why**, **how**, and **how you know it's done**.

---

## Where the project is right now

Think of the app as three layers:

| Layer | State |
|-------|-------|
| **Database** | `project` and `task` tables exist (one migration applied). |
| **API** | Only `Project` is exposed, and only for **list**, **read one**, and **create**. |
| **Frontend** | Shows a health check ("is the API alive?") and nothing else. |

In more detail:

- ✅ `Project` entity + `Task` entity + `ProjectStatus` enum
- ✅ `ProjectResource` (the API shape of a project) with `GET /api/projects`, `GET /api/projects/{id}`, `POST /api/projects`
- ❌ No way to **edit** or **delete** a project
- ❌ `Task` is in the database but has **no API at all**
- ❌ No tests anywhere (`backend/tests/` doesn't exist)
- ❌ Fixtures file is empty — there's no sample data to work with
- ❌ Frontend never calls the projects API

So the theme of the next steps is: **finish the Project API, then give Task the same treatment, then let the frontend actually use it.**

> Housekeeping: `artifacts/2026-08-10-project-api-write-path.md` and the edit to `artifacts/README.md` are not committed yet.

---

## Step 1 — Prove that creating a project actually works

**Effort:** 10 minutes · **Priority:** do this first

### What

Send a real `POST` request and confirm a project is saved.

### Why

The last piece of work added `POST`, but the write-up says it was never run end to end. Two of the fixes in it (`Map(if: false)` on `createdAt`, and the validation rules) were *reasoned about*, not *observed working*. Everything below builds on the write path, so it needs to be trustworthy first.

### How

```bash
make up

# 1. Create one
curl -i -X POST http://localhost:8000/api/projects \
  -H "Content-Type: application/ld+json" \
  -d '{"name":"First project","status":"active"}'

# 2. Read it back
curl http://localhost:8000/api/projects

# 3. Send something invalid — an empty name
curl -i -X POST http://localhost:8000/api/projects \
  -H "Content-Type: application/ld+json" \
  -d '{"name":""}'
```

If something goes wrong, the real reason is in the logs:

```bash
docker compose logs backend --tail=50
```

### Done when

- Request 1 returns **201** with an `id` and a filled-in `createdAt`
- Request 2 shows the project, **with `createdAt` still present** (this is what proves the read direction didn't break)
- Request 3 returns **422**, not 500

**Status code cheat sheet:** `400` broken JSON · `405` operation not enabled · `415` wrong `Content-Type` · `422` validation refused it · `500` a genuine crash.

---

## Step 2 — Finish the Project API: update and delete

**Effort:** 30 minutes

### What

Add `Put`, `Patch`, and `Delete` operations to `ProjectResource`.

### Why

Right now a project can be born but never changed or removed. A "project management system" where you can't rename a project or archive it isn't usable yet. This is also the cheapest possible win — API Platform gives you these for free once you list them.

### How

In `backend/src/ApiResource/ProjectResource.php`, add to the `operations` array:

```php
new Put(),
new Patch(),
new Delete(),
```

(and add the matching `use ApiPlatform\Metadata\Put;` etc. at the top).

**The difference between the two update verbs, simply:**
- `PUT` = "here is the whole object, replace it" — fields you leave out get wiped.
- `PATCH` = "here are just the bits that changed" — everything else is left alone.

`PATCH` is usually what a UI wants. It needs the content type `application/merge-patch+json`.

### Watch out for

The same trap as `createdAt`. The `ObjectMapper` copies properties across blindly — if a field arrives as `null` because nobody mentioned it, `null` is what gets written to the database. Test an update that only changes the name and confirm the description survives.

### Done when

```bash
make TTY=-T console c="debug:router"
```

lists 6 project routes, and you have successfully renamed a project with `PATCH` and deleted one with `DELETE` (which returns **204** — success, no body).

---

## Step 3 — Put real data in the fixtures

**Effort:** 30 minutes

### What

Fill in `backend/src/DataFixtures/AppFixtures.php` with a handful of projects, each with a few tasks.

### Why

**Fixtures** are fake starter data for development. Right now the file is an empty stub, so every time you reset the database you have to create everything by hand with curl. This step pays for itself immediately — every step after this one needs data to look at.

It also gives you the first real exercise of the `Project` ↔ `Task` relationship in code, rather than in theory.

### How

Create a few `Project` objects, create `Task` objects, attach them with `$project->addTask($task)`, then `$manager->persist($project)` and `$manager->flush()` once at the end.

Because `Project` declares `cascade: ['persist', 'remove']` on its tasks, persisting the project persists its tasks too — you don't need to persist each task separately.

```bash
make db-reset      # drop, recreate, migrate
make fixtures      # load the sample data
make psql          # then: SELECT * FROM project;
```

### Done when

`make db-reset && make fixtures` gives you a database with several projects and tasks, repeatably, in one command.

---

## Step 4 — Give Task its own API

**Effort:** 2–3 hours · **This is the biggest learning step**

### What

Create `TaskResource` — the same DTO-backed pattern `ProjectResource` uses — and expose tasks over HTTP.

### Why

Tasks are the actual point of a project management system. This is also where you hit the one thing `Project` never made you solve: **how does a relationship between two things get represented in JSON?**

### The new problem this raises

A task belongs to a project. So when someone creates a task, how do they say which project?

Two common answers, and it's worth understanding both:

1. **Send the project's IRI** — `{"title": "Write docs", "project": "/api/projects/1"}`. An **IRI** is just API Platform's way of pointing at another resource with a URL instead of a raw id. This is the idiomatic choice.
2. **Nested routes** — `POST /api/projects/1/tasks`, where the project comes from the URL. Nicer to use, more setup.

Start with option 1. It's the default and requires the least machinery.

### Also worth thinking about

`Task` has a `completed` boolean *and* a `completedAt` timestamp. Nothing currently keeps them in agreement — a caller could set `completed: true` and leave `completedAt` empty, or worse, the reverse. Decide who owns that rule: the API, or the entity itself. Doing it in the entity (`setCompleted()` also sets the timestamp) means it can never be bypassed.

### Done when

You can create a task against an existing project, list the tasks, and mark one complete — and `completedAt` is filled in without the caller having to send it.

---

## Step 5 — Write the first tests

**Effort:** 2–3 hours

### What

Set up PHPUnit and write API tests for the endpoints built so far.

### Why

Everything up to now has been verified by hand with curl. That works once. It doesn't work when you have twelve endpoints and you change one shared thing. A test is just curl that you don't have to remember to re-run.

There is currently no `backend/tests/` directory and no test framework installed — this is a from-scratch step.

### How

```bash
make composer-require p="--dev symfony/test-pack"
make composer-require p="--dev justinrainbow/json-schema"
```

API Platform ships `ApiTestCase`, which gives you a test HTTP client. A test reads roughly like: send a request → assert the status code → assert something about the JSON that came back.

Start with the cases you already checked by hand in Step 1:
- creating a valid project returns 201
- creating a project with an empty name returns 422
- listing projects returns the ones you created

**Important:** tests need their own database, separate from your development one, so a test run never destroys your fixtures.

### Done when

`make TTY=-T console c=...` aside, a single test command runs green, and deliberately breaking a validation rule makes it go red.

---

## Step 6 — Make the frontend use the API

**Effort:** 3–4 hours

### What

Replace the health-check page with a real projects screen: a list, and a form to add one.

### Why

This is the first time the two halves of the project actually meet. It's also where CORS, error handling, and loading states stop being theoretical.

### How

Build it in small pieces, in this order:

1. **List projects** — fetch `GET /api/projects` and render the results.
   API Platform's default JSON-LD format wraps results, so the array you want is under `member` (older versions used `hydra:member`) — check the actual response shape before writing the type.
2. **Create a project** — a form that `POST`s, then refreshes the list.
3. **Handle failure** — show the validation message when the API returns 422, instead of silently doing nothing.

`VITE_API_URL` is already wired up in `App.tsx`, and the CORS bundle is already configured, so the plumbing exists.

### Worth deciding here

Right now `App.tsx` does everything. Before it grows, decide whether to pull API calls into a small `src/api/` module. Not urgent, but much cheaper to do at 2 screens than at 10.

### Done when

You can create a project in the browser, see it appear in the list, and see a readable error when you submit an empty name.

---

## Step 7 — Pagination, filtering and sorting

**Effort:** 1–2 hours

### What

Let the projects list be filtered by status and sorted by creation date.

### Why

With fixtures loaded, a plain list is already awkward. And this is one of the best demonstrations of what API Platform buys you — these are attributes on the resource class, not code you write.

### How

Add `#[ApiFilter(...)]` attributes to `ProjectResource` for search (on `status`) and ordering (on `createdAt`). Pagination is already on by default — confirm it and set a sensible page size.

### Done when

`GET /api/projects?status=active&order[createdAt]=desc` returns what you'd expect, and `/api/docs` shows the new query parameters.

---

## Later — not yet

These matter, but doing them now would slow the learning down:

- **Authentication and users** — a `User` entity, login, and rules about who may touch which project. Big topic; worth its own dedicated stretch, and it's much easier once the API shape has settled.
- **CI** — running the tests automatically on every push. Do this once Step 5 exists and there's something to run.
- **Task ordering / subtasks / comments** — features, not foundations.

---

## The short version

| # | Step | Effort | Why now |
|---|------|--------|---------|
| 1 | Verify `POST` works end to end | 10 min | Everything else assumes it does |
| 2 | Add `PUT` / `PATCH` / `DELETE` | 30 min | Cheapest win available |
| 3 | Real fixture data | 30 min | Makes every later step easier |
| 4 | `TaskResource` API | 2–3 h | The core feature + relationships in JSON |
| 5 | First tests | 2–3 h | Hand-testing stops scaling around here |
| 6 | Frontend projects screen | 3–4 h | Where the two halves finally meet |
| 7 | Filters, sorting, pagination | 1–2 h | Shows off what API Platform gives free |

Steps 1–3 are one comfortable sitting. Step 4 is the one that teaches the most.

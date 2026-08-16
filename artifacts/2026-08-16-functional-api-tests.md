# 2026-08-16 — Functional tests for the Project API

## Goal

Every change to the API so far had been verified by hand — a curl command, or clicking through the frontend. Nothing stopped a regression from shipping silently.

Set up a PHPUnit functional-test harness against the real API and write a first suite covering `ProjectResource`, so that status codes, JSON shape, validation rules and the DTO boundary are locked down automatically.

## What was done

### Dependencies

`backend/composer.json` — six dev packages added:

```json
"require-dev": {
    "dama/doctrine-test-bundle": "^8.6",
    "doctrine/doctrine-fixtures-bundle": "^4.3",
    "justinrainbow/json-schema": "^6.10",
    "phpunit/phpunit": "^13.3",
    "symfony/browser-kit": "7.4.*",
    "symfony/css-selector": "7.4.*",
    "symfony/http-client": "7.4.*",
    "symfony/maker-bundle": "^1.67"
}
```

`justinrainbow/json-schema` powers the schema assertions and `dama/doctrine-test-bundle` provides test isolation. `symfony/http-client` is a hard requirement of `ApiTestCase` specifically — its client implements the HttpClient interface, which is why it isn't needed for a plain `WebTestCase` setup. The rest is the standard `symfony/test-pack` set.

### Wiring

`backend/config/bundles.php` — DAMA registered for the test environment only:

```php
DAMA\DoctrineTestBundle\DAMADoctrineTestBundle::class => ['test' => true],
```

`backend/phpunit.dist.xml` — the recipe left `<extensions>` empty; the rollback extension goes there:

```xml
<extensions>
    <!-- Wraps every test in a transaction and rolls it back afterwards. -->
    <bootstrap class="DAMA\DoctrineTestBundle\PHPUnit\PHPUnitExtension" />
</extensions>
```

…and one added line that turned out to be essential (see *Issues encountered*):

```xml
<server name="APP_ENV" value="test" force="true" />
<env name="APP_ENV" value="test" force="true" />
```

`config/reference.php` picked up DAMA's config shape automatically — generated, not hand-written.

### Task runner

`Makefile` — a `##@ Testing` section reusing the existing `$(BACKEND)` / `$(CONSOLE)` variables:

```make
.PHONY: test
test: ## Run the PHPUnit suite: make test a="--filter testPostCreatesProject"
	$(BACKEND) php bin/phpunit $(a)

.PHONY: test-db
test-db: ## Create and migrate the test database
	$(CONSOLE) --env=test doctrine:database:create --if-not-exists
	$(CONSOLE) --env=test doctrine:migrations:migrate --no-interaction
```

`test-db` is idempotent and is the target to run after any future migration.

### The tests

`backend/tests/Api/ProjectResourceTest.php` — seven tests:

| Test | Covers |
|---|---|
| `testGetCollectionReturnsProjects` | 200, content type, `totalItems`, collection schema |
| `testGetSingleProjectExposesExpectedFields` | field values, item schema, `tasks` **absent** |
| `testGetUnknownProjectReturns404` | 404 path |
| `testPostCreatesProject` | 201 + genuine DB round-trip |
| `testPostWithBlankNameReturnsValidationError` | `#[Assert\NotBlank]` → 422 |
| `testPostWithOverlongNameReturnsValidationError` | `#[Assert\Length(max: 255)]` → 422 |
| `testPatchUpdatesProject` | `merge-patch+json` update |

### One production fix

`backend/src/ApiResource/ProjectResource.php` — a real bug the tests caught:

```diff
 #[ApiProperty(writable: false)]
-#[Map(if: false)]
+#[Map(if: new TargetClass(ProjectResource::class))]
 public ?\DateTimeImmutable $createdAt = null;
```

## Concepts explained

**Functional test — driving the whole app, not one class**

A *unit* test checks one class in isolation, with everything around it faked. A **functional** test boots the entire application and pushes a real request through it — routing, serializer, validator, Doctrine, database, all of it. What comes back is a real HTTP response.

The important trick: there is no web server and no network involved. Symfony builds a `Request` object in memory, hands it to the kernel, and gets a `Response` back. That's why 7 tests that each hit the database finish in under a second.

Think of it as a crash-test dummy in a real car, rather than testing the seatbelt on a bench.

**`ApiTestCase` vs `WebTestCase` — pick the one that matches the output**

Both boot the app. They differ in what they hand you:

- `WebTestCase` (Symfony) is built for HTML. Its client returns a `Crawler` for poking at the DOM. Right for Twig pages and forms — in this project, `HealthController`.
- `ApiTestCase` (API Platform) is built for JSON. Its client returns a response object with `toArray()`, and it adds JSON-specific assertions.

Everything under `#[ApiResource]` uses `ApiTestCase`, which is why this file does:

```php
final class ProjectResourceTest extends ApiTestCase
```

The two clients also have quite different call signatures — `ApiTestCase` takes a tidy options array (`['json' => [...]]`) where `WebTestCase` takes five positional arguments. Mixing up examples from the two is a common source of confusion.

**The `test` environment and a separate database**

Symfony runs in an *environment* — `dev`, `prod`, or `test` — and each can load different configuration. Tests run in `test`, which matters because `config/packages/doctrine.yaml` already contained this:

```yaml
when@test:
    doctrine:
        dbal:
            dbname_suffix: "_test%env(default::TEST_TOKEN)%"
```

That single line appends `_test` to the database name, so tests hit `pms_test` while development data sits safely in `pms`. It was written months ago and only started earning its keep now.

**Test isolation via transaction rollback (DAMA)**

Tests write rows. Left alone, those rows pile up: the second run of `testGetCollectionReturnsProjects` would find four projects instead of two and fail. Tests would also start depending on the order they ran in — the worst kind of flaky.

The naive fix is to wipe and rebuild the schema between tests, which is extremely slow. `dama/doctrine-test-bundle` does something smarter: it opens a database **transaction** before each test and rolls it back afterwards. The test sees its own writes normally; the moment it finishes, the database forgets them.

It's a video game save-state — play the level however you like, then reload and it's as if nothing happened. Cost: one line of config. Benefit: a suite that's still fast at 500 tests.

**Subset assertions — `assertJsonContains`**

```php
$this->assertJsonContains([
    '@context' => '/api/contexts/Project',
    'totalItems' => 2,
]);
```

This checks that those keys are present with those values. It does **not** demand the response look *only* like that. That distinction matters a lot: if a field is added to `ProjectResource` next month, a test comparing the entire response body would break for no real reason, while this one keeps passing. Assert on the part of the contract you actually care about.

**JSON Schema assertions — checking shape, not values**

```php
$this->assertMatchesResourceItemJsonSchema(ProjectResource::class);
```

API Platform can describe what a `Project` response *should* look like — which fields exist, and what type each one is — derived automatically from the resource class. This assertion checks the real response against that description.

It's the difference between proofreading a form's answers and checking the form has the right boxes on it. This one assertion catches a whole category of serialization mistakes without naming a single field by hand.

**422 vs 400, and the `violations` array**

A malformed request that isn't even valid JSON is a `400 Bad Request`. A request that parsed perfectly but broke a *business* rule is `422 Unprocessable Entity`. Validation failures are always the second kind — the server understood you completely and is declining anyway.

API Platform turns the validator's output into a `violations` array:

```php
$violations = $response->toArray(false)['violations'];
$this->assertSame('name', $violations[0]['propertyPath']);
```

`toArray(false)` is the detail worth remembering: by default a 4xx response throws when you try to read it, and `false` says "don't — I'm expecting this failure."

**The identity map, and why `$em->clear()` appears**

Doctrine keeps every entity it has loaded in an in-memory cache called the **identity map**. Ask for project #5 twice and the second call returns the same PHP object without touching the database.

That's a performance win in production and a trap in tests. After a POST, checking that the project exists would just hand back the object already sitting in memory — proving nothing about whether it reached the database. Hence:

```php
$this->em->clear();
$saved = $this->em->getRepository(Project::class)->findOneBy(['name' => 'Voyager']);
```

`clear()` empties the map, forcing a genuine `SELECT`. It's the difference between checking a letter was posted and checking it actually arrived.

**Conditional mapping direction — `#[Map(if: ...)]`**

The `ProjectResource` DTO and the `Project` entity are separate classes, and Symfony's ObjectMapper copies values between them in **both** directions: entity → resource when reading, resource → entity when writing.

`#[Map(if: false)]` switches the mapping off — but *unconditionally*, in both directions. That's fine for blocking writes and disastrous for reads, because the field then never gets populated on the way out either.

`TargetClass` makes the rule directional:

```php
#[Map(if: new TargetClass(ProjectResource::class))]
```

This reads as: only map when the destination is `ProjectResource` — that is, only when reading. Writes are still blocked, but the value now flows out to the API.

`TaskResource::$completedAt` already used exactly this pattern, with a comment warning about the trap. `ProjectResource` hadn't caught up.

> `merge-patch+json` and the `operations` array come up in these tests but were covered in [2026-08-12 — Editing and deleting projects](2026-08-12-project-api-edit-delete.md).

## Decisions & why

**Tests build their own data instead of using `ProjectFixtures`.** The fixtures exist for development. If `testGetCollectionReturnsProjects` asserted `totalItems === 12` because that's what the fixtures happen to load, the test would break every time someone edited an unrelated fixture file. A private `createProject()` helper means each test states its own preconditions.

**`$alwaysBootKernel = true` rather than relaxing `failOnDeprecation`.** The strict flags in `phpunit.dist.xml` are worth keeping — they're how deprecations get noticed before an upgrade forces the issue. Opting into the API Platform 5 behaviour explicitly fixes the cause instead of muting the symptom, and means one less thing to fix at upgrade time.

**Fixed `ProjectResource` rather than softening the assertion.** The failing test was correct; the code was wrong. Deleting the assertion would have been faster and would have preserved the bug.

**`TaskResource` tests deliberately left out.** Scope was one resource, green, with the pattern established. The Task tests — IRI relations, the `completedAt` read-only guard — are the obvious next piece.

**No authentication tests.** `symfony/security-bundle` isn't installed. There's nothing to test yet.

## Issues encountered

**The test kernel was booting in `dev`, not `test`.** All seven tests errored identically:

```
LogicException: Could not find service "test.service_container".
Try updating the "framework.test" config to "true".
```

The advice in that message was a red herring — `framework.test: true` was already set under `when@test`. The real cause is one line in Symfony's `KernelTestCase.php:133`:

```php
$env = $options['environment'] ?? $_ENV['APP_ENV'] ?? $_SERVER['APP_ENV'] ?? 'test';
```

`$_ENV` is consulted **before** `$_SERVER`. `docker-compose.yml` sets `APP_ENV: dev` as a genuine container environment variable, which PHP puts in `$_ENV` — and the PHPUnit recipe only writes `$_SERVER`:

```xml
<server name="APP_ENV" value="test" force="true" />
```

So `dev` won, the app booted without the test container, and nothing worked. Adding the matching `<env>` line fixed all seven at once.

This is specific to running Symfony in Docker with `APP_ENV` set in the compose file. On a plain local install `$_ENV['APP_ENV']` is unset and the stock config works fine — which is presumably why the recipe ships the way it does.

**A deprecation counted as a failure.** `phpunit.dist.xml` sets `failOnDeprecation="true"`, and `ApiTestCase::createClient()` emits one unless you declare your intent:

```php
protected static ?bool $alwaysBootKernel = true;
```

In API Platform 5 the default flips to `false`. Setting it now silences the warning and pins current behaviour across that upgrade.

**The tests found a real bug on their first green-ish run.** `createdAt` was missing from every project response. Confirmed against the live dev API, not just the test:

```json
{"@id":"/api/projects/24","@type":"Project","id":24,"name":"Project number 1","status":"active"}
```

No `createdAt` anywhere, despite the column being `NOT NULL` and populated in `Project::__construct()`. The unconditional `#[Map(if: false)]` meant the DTO property stayed `null`, and API Platform omits null values from its output. The frontend could never have shown a "created" date.

Fixed with the `TargetClass` condition described above — the same pattern `TaskResource` already used.

## Result / how to verify

```bash
make test
```

```
OK (7 tests, 23 assertions)
```

Isolation was verified rather than assumed:

| Check | Result |
|---|---|
| Two consecutive runs | Byte-identical output — no leakage between runs |
| `select count(*) from project` on `pms_test` after a run | **0** — nothing committed |
| Same query on `pms` (dev) | **5** — untouched |

Useful commands:

```bash
make test                                  # whole suite
make test a="--filter testPostCreatesProject"   # one test
make test-db                               # after adding a migration
```

The database checks matter as much as the green bar: they're what proves the rollback extension is actually wired, and that the suite is pointed at `pms_test` rather than quietly eating development data.

## Interview questions

**(Conceptual)** What distinguishes a functional test from a unit test, and why does this suite hit a real database rather than mocking Doctrine?

**(Conceptual)** Why does `ProjectResourceTest` extend `ApiTestCase` instead of `WebTestCase`, given both can issue requests?

**(Conceptual)** What is Doctrine's identity map, and what problem does it cause in a test that has just issued a POST?

**(Code-specific)** `testGetCollectionReturnsProjects` asserts `totalItems => 2` and passes on every run even though it inserts two rows each time — what makes that true?

**(Code-specific)** Why does `testPostWithBlankNameReturnsValidationError` call `toArray(false)` rather than `toArray()`?

**(Code-specific)** The suite asserts `assertArrayNotHasKey('tasks', $data)` — what would it mean about `ProjectResource` if that assertion started failing?

**(Code-specific)** `phpunit.dist.xml` now sets `APP_ENV` twice, once as `<server>` and once as `<env>` — why is the `<server>` line alone insufficient in this project but sufficient in most Symfony tutorials?

**(Code-specific)** `#[Map(if: false)]` on `createdAt` blocked writes as intended, so why did it also remove the field from GET responses?

**(Code-specific)** Why is `assertMatchesResourceItemJsonSchema()` worth calling when the test already asserts on specific field values with `assertJsonContains()`?

**(Follow-up)** DAMA rolls back a transaction after each test — what would break if code under test opened its own explicit transaction and committed it?

**(Follow-up)** Write the test that would have caught the `createdAt` bug at the *collection* level rather than the item level — and say why the item-level test caught it first.

**(Follow-up)** `TaskResource::$project` is submitted as an IRI (`"/api/projects/1"`) — what would a test need to set up before it could POST a task, and what should it assert about a task whose project doesn't exist?

**(Follow-up)** If `pms_test` drifted out of sync with `pms` after a new migration, how would the failure present itself, and which `make` target fixes it?

**(Follow-up)** The suite currently trusts `#[Assert\NotBlank]` on `name` — how would you test that validation runs on `PATCH` as well as `POST`, and why might the two paths behave differently?

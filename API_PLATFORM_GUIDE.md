# API Platform in `project-management-system` — DTO-first implementation guide

## Context

You just learned API Platform and want to apply it here. `project-management-system` is the right
target: `artifacts/2026-08-05-initial-project-setup.md` records that API Platform was deliberately
*skipped* at setup ("kept to the minimum... not a heavier stack"), leaving a bare Symfony 7.4
skeleton with **zero entities** and a hand-written `/api/health`. Nothing to retrofit.

**Design constraint you set: resources are bound to DTOs, not entities.** No `#[ApiResource]` on any
Doctrine entity. Entities stay pure persistence; the API contract lives in its own classes.

This is a guide for *you* to implement. Each stage says what it exercises, what to write, and how to
prove it works.

---

## Current state (verified 2026-08-07)

| Thing | State |
|---|---|
| `api-platform/symfony` | **v4.3.17 installed**, bundle registered in `config/bundles.php` |
| `config/packages/api_platform.yaml`, `config/routes/api_platform.yaml` | exist; routes under `/api` |
| `GET /api` | **500** — Doctrine bridge missing (see Stage 0) |
| `api-platform/doctrine-orm` | **not installed** |
| `symfony/object-mapper` | **not installed** |
| `symfony/security-bundle`, `twig-bundle`, `expression-language` | **not installed** |
| `symfony/validator`, `serializer`, `uid` | installed |
| Entities | none — `src/Entity/` holds only `.gitignore` |
| Docker | 4 containers up; PHP 8.4.24, Composer 2.10.2 |

### Two environment gotchas

1. **Composer in the container runs as root**, so generated files land `root:root` on your bind mount
   (`config/packages/api_platform.yaml` already is). After any `composer require` / `make:entity`:
   `sudo chown -R $USER:$USER backend` — or add `user: "1000:1000"` to the `backend` service.
2. **`/api/health` coexists fine.** `HealthController` owns its own route; API Platform only claims
   paths for declared resources. Leave it.

---

## The decision that shapes everything: how DTOs reach the database

Going DTO-first means you give up the Doctrine state layer's automatic wiring — **and with it,
filters, pagination, and IRI resolution**, unless you deliberately keep them. There are two ways to
do that, and you should understand both because the second explains the first.

### Strategy A — DTO + `stateOptions` + ObjectMapper *(recommended default)*

API Platform 4.3 ships a mapping layer built for exactly your constraint. I verified it in your
installed vendor tree:

- `metadata/Resource/Factory/ObjectMapperMetadataCollectionFactory.php` — if an operation's
  `stateOptions` names an `entityClass` **and** ObjectMapper `#[Map]` metadata links it to your
  resource class, it flags the operation `canMap(true)`.
- `state/Provider/ObjectMapperProvider.php` — decorates the Doctrine provider, then maps the result
  into your DTO. Crucially, when the result is a paginator it rewraps it in `MappedObjectPaginator`,
  **preserving `totalItems` / `currentPage` / `lastPage` / `itemsPerPage`**.
- `state/Processor/ObjectMapperProcessor.php` (+ `Input`/`Output` variants) — the write half.

Net effect: **Doctrine still runs the query against the entity — so `#[ApiFilter]` and pagination keep
working — and you only ever expose DTOs.** That is the best of both, and it's the least code.

```php
#[ApiResource(
    shortName: 'Project',
    operations: [new GetCollection(), new Get(), new Post(), new Patch(), new Delete()],
    stateOptions: new Options(entityClass: Project::class),   // ApiPlatform\Doctrine\Orm\State\Options
)]
#[Map(target: Project::class)]                                 // Symfony\Component\ObjectMapper\Attribute\Map
final class ProjectResource
{
    #[ApiProperty(identifier: true)]
    public ?int $id = null;
    public string $name = '';
    public ?string $description = null;
    public string $status = 'active';
    public ?\DateTimeImmutable $createdAt = null;
}
```

Requires `composer require symfony/object-mapper` (Symfony 7.3+; you're on 7.4).

> Verify after installing: the exact `Options` constructor signature and whether `#[ApiFilter]` on the
> resource needs entity-side property names. I couldn't confirm those — `api-platform/doctrine-orm`
> isn't installed yet, so its source isn't on disk. Everything else above I read directly.

To verify after installation: the exact Options constructor signature, and whether #[ApiFilter] on the resource requires entity-side property names. These could not be confirmed in advance because api-platform/doctrine-orm is not yet installed, so its source is unavailable for inspection. Everything else above was confirmed directly against the installed vendor tree.

>Mapping-direction note. Class-level #[Map(target: X::class)] is one-directional: it declares "this class maps into X" — in this case, >ProjectResource → Project, which is the direction the Processor requires (DTO from a request body → entity to persist). It does not >provide the opposite direction, which the Provider requires after Doctrine fetches a Project (Project → ProjectResource, entity → DTO for >the response).

Two options address the missing direction:

>Add #[Map(target: ProjectResource::class)] to Project as well. This works, but places a mapping attribute on the entity, which conflicts >with the "entities stay pure persistence" principle.
>Declare the reverse direction on the DTO instead, stacking a second attribute:

```php
#[Map(target: Project::class)]   // ProjectResource → Project  (write direction)
#[Map(source: Project::class)]   // Project → ProjectResource  (read direction)
final class ProjectResource { ... }
```
This keeps Project free of mapping metadata. This should be verified against the installed version before relying on it — declaring source on the target class specifically to avoid modifying the domain object is documented as a Symfony 8.1 ObjectMapper feature. This project runs Symfony 7.4, where the object-mapper release on that line may only support the earlier pattern (declaring #[Map(target:)] on the source class). If source is not recognized at the installed version, fall back to option 1 — a smaller compromise than it appears, since #[Map] is a general-purpose Symfony attribute rather than an API Platform–specific one.

### Strategy B — DTO + fully custom provider/processor

No Doctrine state layer at all. Total control; you hand-roll filtering and pagination. Use it where
the response genuinely isn't a table (stats, reports, aggregates, multi-entity writes).

Exact signatures, read from your installed `api-platform/state`:

```php
// ApiPlatform\State\ProviderInterface
public function provide(Operation $operation, array $uriVariables = [], array $context = []): object|array|null;

// ApiPlatform\State\ProcessorInterface
public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []);

// ApiPlatform\State\Pagination\TraversablePaginator
__construct(\Traversable $traversable, float $currentPage, float $itemsPerPage, float $totalItems)

// ApiPlatform\State\Pagination\Pagination  (inject this service)
public function getPagination(?Operation $operation = null, array $context = []): array;  // → [$page, $offset, $limit]
```

Filters arrive as `$context['filters']` — an array of raw query params. **Nothing parses them for
you in Strategy B.** That's the cost, and feeling it once is why B is worth doing.

**Recommendation:** Strategy A for `Project` and `Task`. Strategy B for the genuinely non-tabular
resources in Stage 3. Build A first.

### What DTO-first changes vs the entity-bound tutorials

| Concern | Entity-bound | DTO-first (yours) |
|---|---|---|
| Serialization groups | essential — one class, two shapes | **largely redundant** — use separate Input/Output classes instead |
| Validation | constraints on the entity | constraints on the **Input DTO**; entity stays clean |
| Filters / pagination | free | free **only** under Strategy A |
| `object` in `security:` | the entity | the **DTO** — voters must adapt (Stage 4) |
| Identifier / IRI | Doctrine `#[ORM\Id]` | explicit `#[ApiProperty(identifier: true)]` |

**Groups vs DTO-per-operation is a real fork.** DTO-first, prefer explicit classes —
`ProjectCreateInput`, `ProjectUpdateInput`, `ProjectOutput`. More files, but each is a typed,
self-documenting contract, which is the entire reason to go DTO-first. Reach for groups only when two
shapes differ by one or two fields.

---

## Stage 0 — Make the install work

```bash
docker compose exec backend composer require api-platform/doctrine-orm
docker compose exec backend composer require symfony/object-mapper
docker compose exec backend composer require symfony/twig-bundle          # Swagger UI
docker compose exec backend composer require symfony/expression-language  # security: expressions
sudo chown -R $USER:$USER backend
docker compose exec backend php bin/console cache:clear
```

**Verify:** `curl -s -H "Accept: application/ld+json" http://localhost:8000/api` returns a Hydra
entrypoint (not a 500), and `http://localhost:8000/api` renders Swagger UI with zero resources.

---

## Stage 1 — Entities (persistence only) + first DTO resource

### Entities — deliberately free of API concerns

`src/Entity/Project.php`, `src/Entity/Task.php`. **No `#[ApiResource]`, no `#[Groups]`, no
`#[ApiFilter]`, no validation constraints.** Just Doctrine mapping. Enjoy this — it's the payoff.

- **Project** — `id`, `name`, `description`, `status`, `createdAt`, `tasks` (OneToMany → Task, `cascade: ['persist','remove']`)
- **Task** — `id`, `title`, `description`, `completed`, `dueDate`, `completedAt`, `project` (ManyToOne, `inversedBy: 'tasks'`)

Use a backed enum for status (`src/Enum/ProjectStatus.php`). API Platform puts allowed values into the
OpenAPI schema automatically when the DTO property is typed as the enum.

```bash
docker compose exec backend php bin/console make:migration
docker compose exec backend php bin/console doctrine:migrations:migrate -n
sudo chown -R $USER:$USER backend
```

### Resource DTOs

Put them in `src/ApiResource/` — **not** `src/Entity/`. `config/packages/doctrine.yaml` maps
`src/Entity` for ORM attributes, so keeping resources out of it stops Doctrine trying to map them.

Start with `ProjectResource` exactly as sketched above. Get `GetCollection` + `Get` green before
adding writes.

**The relation question — decide it explicitly.** `ProjectResource::$tasks` can be:
- `string[]` of IRIs (`/api/tasks/3`) — cheap, no N+1
- `TaskResource[]` embedded — richer, needs mapping config and care about query count

Try both and diff the JSON. This is the highest-value thing in this stage.

**Verify:**
```bash
curl -X POST http://localhost:8000/api/projects -H "Content-Type: application/ld+json" \
  -d '{"name":"Website redesign","description":"Q3 push","status":"active"}'
curl -s http://localhost:8000/api/projects | jq
```
Confirm the response is your DTO shape, and that `id`/`createdAt` are populated server-side even
though you couldn't send them. Then fill the empty `src/DataFixtures/AppFixtures.php` stub with a few
projects and tasks so collections have something to page through.

---

## Stage 2 — Validation, filters, pagination

**Validation** goes on Input DTOs:

```php
final class ProjectCreateInput
{
    #[Assert\NotBlank]
    #[Assert\Length(min: 3, max: 120)]
    public string $name = '';

    #[Assert\GreaterThan('today', message: 'Due date must be in the future.')]
    public ?\DateTimeImmutable $dueDate = null;
}
```

Wire per operation: `new Post(input: ProjectCreateInput::class, output: ProjectResource::class)`.

Verify a **422** (well-formed but invalid) with a `violations` array, and separately a **400**
(malformed JSON). Knowing which is which matters.

**Filters** — Strategy A only, declared on the resource, targeting entity properties:

```php
#[ApiFilter(SearchFilter::class, properties: ['name' => 'partial', 'status' => 'exact'])]
#[ApiFilter(OrderFilter::class, properties: ['createdAt', 'name'])]
#[ApiFilter(DateFilter::class, properties: ['createdAt'])]
```

On `TaskResource` add `BooleanFilter` on `completed` and `SearchFilter` on `project` (`exact`) —
`/api/tasks?project=/api/projects/1` is the filter that makes the API feel real.

**Pagination** — `paginationItemsPerPage: 10`, `paginationClientItemsPerPage: true`,
`paginationMaximumItemsPerPage: 50`.

**Verify:** `curl "http://localhost:8000/api/projects?name=redesign&order[createdAt]=desc"`, then
`?page=2&itemsPerPage=5` and read `hydra:totalItems` / `hydra:view`. Then check Swagger UI — every
filter appears as a documented parameter. **If pagination metadata is missing or wrong, your
ObjectMapper wiring is off**, because `MappedObjectPaginator` is what carries those numbers through.

---

## Stage 3 — Strategy B: resources with no table behind them

Now do the custom providers/processors, where DTO-first genuinely earns its keep.

### 3a. Read — computed stats

```php
#[ApiResource(
    shortName: 'ProjectStats',
    operations: [new Get(uriTemplate: '/projects/{id}/stats')],
    provider: ProjectStatsProvider::class,
)]
final class ProjectStats
{
    public function __construct(
        public int $projectId = 0,
        public string $projectName = '',
        public int $totalTasks = 0,
        public int $completedTasks = 0,
        public float $completionRate = 0.0,
        public ?\DateTimeImmutable $nextDueDate = null,
    ) {}
}
```

No `stateOptions`, no table, no migration. `$uriVariables['id']` is how the URL segment reaches you.
Do the counting in one aggregate repository query, not in PHP.

### 3b. Read — a hand-paginated collection

Add `new GetCollection()` to a Strategy-B resource and return a `TraversablePaginator` built from
`Pagination::getPagination()` (signatures above), reading `$context['filters']` yourself. Painful on
purpose: it shows you precisely what Strategy A hands you for free.

### 3c. Write — one request, several entities

`POST /api/project-drafts` creating a project *and* its initial tasks — the case an entity-shaped
resource handles badly.

```php
#[ApiResource(operations: [new Post(uriTemplate: '/project-drafts')], processor: CreateProjectDraftProcessor::class)]
final class ProjectDraftInput
{
    #[Assert\NotBlank] public string $name = '';
    public ?string $description = null;
    /** @var string[] */
    #[Assert\Count(max: 20)] public array $taskTitles = [];
}
```

The processor builds entities, persists, and returns a `ProjectResource`.

### 3d. Decorating the Doctrine processor

Wrap rather than replace — stamp `completedAt` when a task flips to completed:

```php
public function __construct(
    #[Autowire(service: 'api_platform.doctrine.orm.state.persist_processor')]
    private ProcessorInterface $inner,
) {}
```

Two service ids worth memorising: `api_platform.doctrine.orm.state.persist_processor` and
`...state.remove_processor`. Note the ordering under Strategy A: **ObjectMapper maps DTO → entity
before persistence**, so `$data` here is the *entity*, not your DTO. Confirm that with a `dump()`
rather than assuming — it's the easiest thing to get backwards.

---

## Stage 4 — Custom operations + security

### Custom operation

`POST /api/tasks/{id}/complete` — a state transition, not a field edit. Prefer a processor over a
controller (controllers are the legacy path in v4):

```php
new Post(
    uriTemplate: '/tasks/{id}/complete',
    input: false,          // no request body
    output: TaskResource::class,
    read: true,            // load from {id} first
    processor: CompleteTaskProcessor::class,
    name: 'complete',
)
```

### Security — decide this before you start

**You chose `Project + Task` with no `User` entity, so there's no authentication yet** and
`is_granted()` has nothing to evaluate. My recommendation, which the rest of this assumes: add
`symfony/security-bundle` with an **in-memory user provider + HTTP Basic**. No entity, no migration,
no JWT — but voters and `security:` expressions genuinely execute, which is the point.

```yaml
# config/packages/security.yaml
security:
    password_hashers:
        Symfony\Component\Security\Core\User\InMemoryUser: { algorithm: plaintext }
    providers:
        in_memory:
            memory:
                users:
                    admin: { password: admin, roles: ['ROLE_ADMIN'] }
                    alice: { password: alice, roles: ['ROLE_USER'] }
    firewalls:
        api:
            pattern: ^/api
            stateless: true
            http_basic: { provider: in_memory }
    access_control:
        - { path: ^/api/health, roles: PUBLIC_ACCESS }
        - { path: ^/api/docs, roles: PUBLIC_ACCESS }
        - { path: ^/api, roles: PUBLIC_ACCESS }   # let operation-level security decide
```

Two things not to "fix": `framework.yaml` has `session: true` while the API firewall is
`stateless: true` — correct and intentional. And the React app on :5174 will get 401s on protected
endpoints; `nelmio_cors.yaml` already allows the `Authorization` header, and keeping reads public
while protecting writes keeps the existing UI working.

Operation level:

```php
new Delete(security: "is_granted('ROLE_ADMIN')", securityMessage: 'Only admins may delete projects.'),
new Patch(security: "is_granted('PROJECT_EDIT', object)"),
new Post(securityPostDenormalize: "is_granted('PROJECT_CREATE', object)"),
```

`security` runs **before** denormalization (no `object` yet for POST); `securityPostDenormalize` runs
after. Knowing which to reach for is the lesson.

**The DTO-first wrinkle:** `object` is now your **DTO**, not the entity — so a voter can't just read
`$project->getOwner()`. Three options, pick one deliberately:
1. Carry the ownership field on the DTO (simplest; works if it's non-sensitive).
2. Have the voter load the entity by id from the DTO (an extra query, but honest).
3. Do the check in the processor, where you hold the real entity.

With no `User` entity, base the rule on an `ownerUsername` string compared to
`$token->getUserIdentifier()`. Crude, but it exercises the real voter mechanism and maps 1:1 onto a
proper `User` relation later.

**Verify** — same URL, three outcomes:
```bash
curl -i -X DELETE http://localhost:8000/api/projects/1                  # 401
curl -i -u alice:alice -X DELETE http://localhost:8000/api/projects/1   # 403
curl -i -u admin:admin -X DELETE http://localhost:8000/api/projects/1   # 204
```

---

## Stage 5 — Verify and write up

1. Walk every endpoint: CRUD, a 422, a 400, filters, pagination, stats, draft POST, `/complete`, and
   the three-way auth check.
2. Open Swagger UI and confirm generated docs match reality — filters as parameters, enum values in
   schemas, security on protected operations. Wrong docs mean a wrong API.
3. **`grep -r ApiResource backend/src/Entity/` should return nothing.** That's your architectural
   constraint, enforced.
4. Point the React frontend at one real endpoint (it only calls `/api/health` today).
5. Update `README.md` (its endpoint table lists only the health check).
6. Write `artifacts/2026-08-07-api-platform-dto-first.md` per `artifacts/README.md` (Goal /
   Decisions & why / Issues encountered / Result) and add it to the index. Record the reversal of the
   original "skip API Platform" decision, the DTO-over-entity choice **and its cost** (Strategy B
   loses free filtering/pagination), plus the two install gotchas.

---

## Suggested order

Stages are cumulative. Stage 0 is mandatory. **Stages 0–2 give you a working, filterable, documented
DTO-backed API** — that's the natural stopping point if you want to timebox. Stage 3 is where the
DTO-first design actually pays off, and Stage 4 is where it costs you something.

## Files you'll create

| Path | Stage |
|---|---|
| `backend/src/Entity/Project.php`, `Task.php` *(no API attributes)* | 1 |
| `backend/src/Enum/ProjectStatus.php` | 1 |
| `backend/src/Repository/ProjectRepository.php`, `TaskRepository.php` | 1 |
| `backend/migrations/VersionXXXX.php` | 1 |
| `backend/src/ApiResource/ProjectResource.php`, `TaskResource.php` | 1 |
| `backend/src/ApiResource/ProjectCreateInput.php`, `ProjectUpdateInput.php` | 2 |
| `backend/src/ApiResource/ProjectStats.php`, `ProjectDraftInput.php` | 3 |
| `backend/src/State/*Provider.php`, `*Processor.php` | 3–4 |
| `backend/config/packages/security.yaml` | 4 |
| `backend/src/Security/Voter/ProjectVoter.php` | 4 |
| `README.md`, `artifacts/2026-08-07-api-platform-dto-first.md` | 5 |

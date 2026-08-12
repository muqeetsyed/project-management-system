# 2026-08-10 — Opening the write path on the Project API resource

## Goal

`ProjectResource` could already be read — `GET /api/projects` and `GET /api/projects/{id}` were serving. Make it writable, so projects can be created through the API, and reject bad input properly instead of letting Postgres do it.

## What was done

- `backend/src/ApiResource/ProjectResource.php` — added the `Post` operation; retyped `$status` from `string` to the `ProjectStatus` enum; marked `$createdAt` as `writable: false` and excluded it from object mapping; added `NotBlank` and `Length(max: 255)` to `$name`.
- Installed `symfony/validator`, which the constraints need.

No controller was written. `stateOptions: new Options(entityClass: Project::class)` already told API Platform which entity backs the resource, and `#[Map(target: Project::class)]` already described the conversion — both directions ride the same declaration, so enabling writes really is one line plus the corrections below.

Commit `931e209`, three files, +18/−12.

## How the write path runs

Reads and writes are the same five stages in opposite directions:

```
JSON body → ProjectResource → Validator → ObjectMapper → Doctrine
(ld+json)    the API shape     422 here    copies props   INSERT
```

Stage 4 is where a DTO-backed resource earns its keep and also where it bites. The `ObjectMapper` is deliberately dumb: it copies same-named properties across without coercing types and without asking whether a value was ever deliberately set. Both of the crashes below follow directly from that one fact.

## Symfony concepts used here, in plain words

**Entity vs. resource**
`Project` is the database shape — Doctrine reads and writes rows through it. `ProjectResource` is the API shape — it decides what the outside world sees as JSON. Two classes for one thing, so the database can change without breaking the public contract, and vice versa.

**`stateOptions`**
The bridge to persistence. `new Options(entityClass: Project::class)` tells API Platform which entity to load from and save to. Without it, a DTO resource has no idea where its data lives, and you would be writing a state provider and a state processor by hand.

**ObjectMapper**
Symfony's property-to-property copier, aimed at a target class by `#[Map(target: ...)]`. Property-level `#[Map(if: false)]` excludes one property from the copy.

**`ApiProperty(writable: false)`**
Works at the JSON layer: if a caller sends this field in a request body, ignore it. It also drops the field from the OpenAPI request schema while keeping it in the response schema. This is what stops a caller backdating a record by sending their own `createdAt`.

**Validation constraints**
`#[Assert\NotBlank]` and friends are attributes describing rules. API Platform runs them automatically on write operations and returns `422` with a per-field breakdown when they fail. They are inert on a read-only resource — there is nothing to validate on a `GET`.

**Why duplicate the column length**
`Assert\Length(max: 255)` restates `#[ORM\Column(length: 255)]` on purpose. The database constraint protects the data; the validation constraint produces a civil `422` instead of a stack trace. Keeping the pair in sync by hand is normal Symfony practice.

## Decisions & why

**Typed `$status` as the enum rather than transforming a string.**
The alternative was keeping `public string $status` and attaching a transform to the `#[Map]`. Using `ProjectStatus` directly is less code, and it makes API Platform publish the enum's cases in the OpenAPI schema — so `/api/docs` offers a fixed set of values and an invalid one is rejected at deserialization rather than at the database.

**Both `writable: false` and `Map(if: false)` on `$createdAt`.**
These look redundant and are not. They act on different layers, and only the second one prevents the crash described below. `writable: false` is there for a separate reason: trust. A client-settable `createdAt` is a `createdAt` you cannot use for sorting, auditing or reporting.

**Left `description` and `status` unvalidated.**
`description` is a nullable `TEXT` column, so there is genuinely nothing to enforce. `status` is typed as the enum, which means PHP and the deserializer already reject anything that isn't a valid case.

## Issues encountered

**`405 Method Not Allowed` on POST.**
Not a bug — the `operations` array listed only `GetCollection` and `Get`. API Platform exposes exactly what you name.

**`TypeError` on `$status`, affecting reads too.**
The entity holds a `ProjectStatus`; the resource declared `string`. The mapper copies without converting, so it tried to put an enum object into a text-only slot. This one bit reads as well as writes, so it would have surfaced as soon as any row existed.

**`$createdAt` overwriting itself with `null`.**
The entity sets `createdAt` in its constructor. Nobody sends it, so on the resource it stays `null` — and the mapper dutifully copies that `null` over the date the constructor just set, into a non-nullable `\DateTimeImmutable`. Fixed with `#[Map(if: false)]`.

Worth recording precisely, because it was initially mis-diagnosed: `writable: false` does **not** fix this. The property is `null` not because a caller sent `null` but because nobody set it at all, so the JSON layer never enters the picture.

**Constraints silently doing nothing — the wrong `Assert`.**
The import was `use Webmozart\Assert\Assert;`, a runtime guard library that happens to be installed as a sub-dependency, rather than `use Symfony\Component\Validator\Constraints as Assert;`. Same class name, unrelated jobs. The attribute referenced a class that doesn't exist, so no rule was ever registered — no error, no warning, nothing.

The tell: whenever an attribute silently does nothing, read the `use` line before doubting the attribute. Note the correct one is `as Assert` — an alias for a whole namespace, which is why `#[Assert\NotBlank]` contains a backslash.

**Confusion over `application/problem+json`.**
It isn't a request header you set — it's the server's standard envelope for explaining a refusal, so seeing it means the request arrived and was rejected, with the reason in the body. Related Postman trap: choosing **Body → raw → JSON** quietly adds its own `Content-Type: application/json`. An explicit header row overrides it, and the Postman console shows what actually went out.

## Result / how to verify

```
make TTY=-T console c="lint:container"                                  # [OK] container linted successfully
make TTY=-T console c="debug:validator 'App\ApiResource\ProjectResource'"  # NotBlank + Length on $name
make TTY=-T console c="debug:router"                                    # 3 project routes
```

`debug:router` now lists the write route alongside the two reads:

```
_api_/projects{._format}_get_collection   GET    /api/projects.{_format}
_api_/projects/{id}{._format}_get         GET    /api/projects/{id}.{_format}
_api_/projects{._format}_post             POST   /api/projects.{_format}
```

**Still untested.** No POST has been sent end to end, so the write path has not run against a real request. The `Map(if: false)` fix in particular is reasoned rather than observed. The check that closes both:

```
curl -X POST http://localhost:8000/api/projects \
  -H "Content-Type: application/ld+json" \
  -d '{"name":"First project","status":"active"}'
```

Expect `201` with a populated `id` and `createdAt`, and a `Location` header. Then `GET` it back and confirm `createdAt` is still present — that is what proves `Map(if: false)` didn't break the read direction. An empty `{"name":""}` should give `422`. A `500` means the container logs have the real answer:

```
docker compose logs backend --tail=50
```

Status codes worth recognising while testing: `400` malformed JSON, `405` operation not declared, `415` wrong or missing `Content-Type`, `422` validation rejected it, `500` a real crash.

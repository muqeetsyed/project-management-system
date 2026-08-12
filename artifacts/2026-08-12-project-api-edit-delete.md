# 2026-08-12 — Editing and deleting projects

## Goal

A project could be created and read, but never changed or removed. Close that gap so the Project API supports the full lifecycle — the "cheapest win available" from `NEXT_STEPS.md` Step 2.

## What was done

`backend/src/ApiResource/ProjectResource.php` — one file, one attribute. Two operations were added to the `operations` array:

```php
operations: [
    new GetCollection(),
    new Get(),
    new Post(),
    new Patch(),     // ← new
    new Delete(),    // ← new
],
```

plus the matching imports at the top of the file.

No controller, no service, no repository method. The `stateOptions: new Options(entityClass: Project::class)` line that was already there tells API Platform which entity backs this resource, and `#[Map(target: Project::class)]` already describes the conversion between them. Both were written for the read and create paths in the previous piece of work; update and delete ride the same declaration for free.

`PUT` was **deliberately left out** — see *Issues encountered*.

The project API now serves five routes:

```
GET     /api/projects          list
GET     /api/projects/{id}     read one
POST    /api/projects          create
PATCH   /api/projects/{id}     edit          ← new
DELETE  /api/projects/{id}     remove        ← new
```

## Concepts explained

**The `operations` array — you get exactly what you name**

API Platform does not guess which HTTP verbs a resource should support. The `operations` array is the complete list, and anything not in it returns `405 Method Not Allowed`. This is why adding edit and delete is a two-line change: the machinery for all these verbs already exists inside API Platform, and the array is just the guest list saying which ones are allowed through the door.

That behaviour is directly observable here — after removing `Put` from the array, a `PUT` request returns `405`, not `404`. The route genuinely does not exist.

**`PATCH` vs `POST` vs `PUT` — three different kinds of write**

`POST` creates something new. `PATCH` changes part of something that already exists — you send only the fields you want to change, and everything else is left alone. `PUT` traditionally means *replace the whole thing*, where fields you omit get wiped.

The analogy: editing a document. `POST` is writing a new page, `PATCH` is correcting one sentence, `PUT` is tearing the page out and writing a fresh one.

`PATCH` is almost always what a user interface wants, because a form that edits a project's name shouldn't have to resend its description, status, and every other field just to avoid destroying them. That is why `PATCH` is the operation kept here.

**`merge-patch+json` — PATCH needs its own content type**

`PATCH` doesn't accept the usual `application/ld+json`. It requires:

```
Content-Type: application/merge-patch+json
```

The name describes the rule literally: the JSON you send is *merged into* what's already stored, rather than replacing it. Sending the wrong content type here gives `415 Unsupported Media Type` — a confusing error if you don't know to expect it, because the request body itself is perfectly valid.

**`204 No Content` — success with nothing to say**

`DELETE` returns `204`, not `200`. The distinction is that `200` means "here is the result" and `204` means "it worked, and there is deliberately no body." Once a project is deleted there is nothing sensible to return, so the response is empty on purpose. An empty body from a `DELETE` is the success case, not a sign something went wrong.

**Cascade — deletion travels down the relationship**

This one isn't new in this diff, but `DELETE` is what makes it matter for the first time. `Project` already declares:

```php
#[ORM\OneToMany(targetEntity: Task::class, mappedBy: 'project', cascade: ['persist', 'remove'])]
private Collection $tasks;
```

`cascade: ['remove']` tells Doctrine that deleting a project should also delete all of that project's tasks. Without it, deleting a project would leave orphaned task rows pointing at a project that no longer exists — or the database would refuse the delete outright.

Think of it as shredding a folder: the folder goes, and everything filed inside it goes with it.

> Not verified in practice. There is no Task API yet and no fixture data, so no project with tasks attached exists to delete. The configuration is correct on paper; the behaviour hasn't been observed.

**Why validation applies to edits without any extra work**

The constraints on `$name` were written for `POST`:

```php
#[Assert\NotBlank]
#[Assert\Length(max: 255)]
public ?string $name = '';
```

They now guard `PATCH` too, because validation runs on the resource object regardless of which operation produced it. Rules live on the *shape*, not on the *route* — write them once, and every write path inherits them.

## Decisions & why

**Kept `PATCH`, dropped `PUT`.**
`PATCH` covers the actual use case (a UI editing some fields), and keeping `PUT` would have meant shipping a workaround for an upstream bug — described below — in exchange for an endpoint whose name promises replace semantics it would not have delivered. Fewer endpoints, no workaround, no misleading contract.

**No `Put` means no partial-update ambiguity.**
With only one update verb there is exactly one answer to "what happens to fields I don't send": they stay as they are.

## Issues encountered

**`PUT` returned `500`, and the cause is structural.**

The first version of this change added `Put()` alongside `Patch()` and `Delete()`. `PATCH` and `DELETE` worked immediately; `PUT` crashed:

```
Given object is not an instance of the class this property was declared in
  at vendor/api-platform/doctrine-common/State/PersistProcessor.php:77
```

The reason is a genuine mismatch between standard PUT handling and a **DTO-backed** resource. In `PersistProcessor`, the standard-PUT branch does this:

```php
$reflectionProperties = $this->getReflectionProperties($data);   // properties of Project (the entity)
...
$newData = 1 === \count($identifiers) ? $manager->getReference(...) : clone $previousData;
...
if (($newValue = $reflectionProperty->getValue($data)) !== $reflectionProperty->getValue($newData)) {
```

`$data` is the mapped `Project` **entity**, so the reflection properties belong to `Project`. But `$context['previous_data']` holds a `ProjectResource` **DTO**, because that is what the provider returns for this resource. When the identifier lookup doesn't yield exactly one value, `$newData` becomes `clone $previousData` — a `ProjectResource` — and the code then tries to read `Project`'s properties off it. Hence the error, verbatim.

**The workaround, and why it was abandoned.**

Setting `extraProperties: ['standard_put' => false]` on the operation routes the request down the older "populate the existing object" path and does fix the crash — this was tested and returned `200`. But it changes what `PUT` means: a `PUT` omitting `description` was observed leaving the old description intact, which is `PATCH` behaviour wearing a `PUT` label. Given `PATCH` was already in the array doing that job honestly, `PUT` was removed instead.

**Leftover import.**

Removing `new Put(...)` from the array left `use ApiPlatform\Metadata\Put;` behind as an unused import. Dropped before committing. A small reminder that deleting a usage and deleting its import are two separate edits.

**Pre-existing: `createdAt` is still missing from every API response.**

Unrelated to this change and not introduced by it, but it's still true and worth recording, because `NEXT_STEPS.md` Step 1 treated the opposite as the check that the read path survived. The database has the values:

```
 id |     name      |     created_at
  2 | poject 1      | 2026-08-10 15:17:17
  3 | First project | 2026-08-12 03:55:08
```

but no `GET`, `POST`, or `PATCH` response contains a `createdAt` field. So `#[Map(if: false)]` is suppressing the mapping in **both** directions — it stops the null-overwrite on write, as intended, but also skips entity → DTO on read. The previous artifact predicted this fix was "reasoned rather than observed"; this is the half that didn't hold.

## Result / how to verify

```bash
make TTY=-T console c="debug:router"    # 5 project routes
```

Checks actually run, against a live container:

```bash
# create something to work on
ID=$(curl -s -X POST http://localhost:8000/api/projects \
  -H "Content-Type: application/ld+json" \
  -d '{"name":"Edit me","description":"keep this text","status":"active"}' \
  | grep -o '"id":[0-9]*' | cut -d: -f2)

# edit only the name — description must survive
curl -X PATCH http://localhost:8000/api/projects/$ID \
  -H "Content-Type: application/merge-patch+json" \
  -d '{"name":"Renamed"}'

# validation still applies on edit
curl -X PATCH http://localhost:8000/api/projects/$ID \
  -H "Content-Type: application/merge-patch+json" \
  -d '{"name":""}'

# remove it
curl -X DELETE http://localhost:8000/api/projects/$ID
```

Observed results:

| Request | Result |
|---|---|
| `PATCH` name only | `200` — `description` unchanged |
| `PATCH` `{"name":""}` | `422` with `name: This value should not be blank.` |
| `DELETE` | `204`, empty body |
| `GET` after delete | `404` |
| `PUT` | `405` — correctly not exposed |

Not verified: cascade deletion of a project's tasks, for lack of any task data.

## Interview questions

**(Conceptual)** Why does adding `PATCH` and `DELETE` to a resource require no controller code, and what is doing the work instead?

**(Conceptual)** What is the difference between `PATCH` and `PUT`, and why is `PATCH` usually the better fit for a UI edit form?

**(Conceptual)** Why does `DELETE` return `204` rather than `200`?

**(Code-specific)** A `PATCH` to this endpoint sent with `Content-Type: application/ld+json` fails — what status code comes back, and why does the content type matter when the JSON body is valid?

**(Code-specific)** The `#[Assert\NotBlank]` on `$name` was added when only `POST` existed, yet it now rejects an empty name on `PATCH` too — why does it apply without being restated?

**(Code-specific)** Removing `new Put()` from the `operations` array makes a `PUT` request return `405` rather than `404` — what does that difference tell you about how API Platform routes requests?

**(Code-specific)** `Project::$tasks` declares `cascade: ['persist', 'remove']`; which half of that is exercised by the new `DELETE` operation, and what would happen to task rows without it?

**(Follow-up)** The `PUT` crash came from `previous_data` holding a `ProjectResource` while the reflection properties came from `Project` — what does that tell you about the assumption the standard-PUT code path makes about a resource?

**(Follow-up)** `extraProperties: ['standard_put' => false]` fixed the crash but made `PUT` behave like `PATCH` — what would you have to build to get true replace semantics on this DTO-backed resource?

**(Follow-up)** `#[Map(if: false)]` on `$createdAt` prevents a null from overwriting the stored date on write, but also hides the field on read — how would you keep the write protection while restoring the field in responses?

**(Follow-up)** `DELETE` currently removes a project permanently; how would you change this to a soft delete, and what would `GET /api/projects` need to do differently afterwards?

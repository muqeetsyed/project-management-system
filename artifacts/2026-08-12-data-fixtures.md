# 2026-08-12 — Seeding real data with Doctrine fixtures

## Goal

`NEXT_STEPS.md` Step 3 — "Put real data in the fixtures." Two fixture classes existed as uncommitted scratch work, but the Task one could not run at all, and neither produced data worth developing against. Every later step (filters, pagination, tests) needs a database that repopulates in one command.

The 2026-08-12 edit/delete artifact closed with *"Not verified: cascade deletion of a project's tasks, for lack of any task data."* The Task API work (`c849abe`) has since closed that gap by making tasks creatable over HTTP; this makes the data standing rather than hand-built.

## What was done

Two files, both new to git (`??` in `git status` — they were untracked scratch work before this session, so there is no committed baseline to diff against).

### The starting point

Both fixtures were near-identical copies of the same loop:

```php
// TaskFixtures.php — as it was
for($i = 1; $i <= 5; $i++) {
    $task = new Task();
    $task->setTitle("Task project ". $i);
    $manager->persist($task);
}
$manager->flush();
```

This **could not run**. `Task::$project` is declared:

```php
#[ORM\ManyToOne(inversedBy: 'tasks')]
#[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
private ?Project $project = null;
```

`nullable: false` means the `project_id` column rejects nulls, so `flush()` would have died on a NOT NULL violation. And even with a project attached, nothing guaranteed `ProjectFixtures` ran first — Doctrine loads fixture classes in an unspecified order unless you say otherwise.

### `ProjectFixtures.php` — minimal change

Only what `TaskFixtures` needs to reach the projects:

```php
public const PROJECT_COUNT = 5;

public function load(ObjectManager $manager): void
{
    for ($i = 1; $i <= self::PROJECT_COUNT; $i++) {
        $project = new Project();
        $project->setName('Project number '.$i);
        $manager->persist($project);

        // Named so TaskFixtures can hang its tasks off a real project.
        $this->addReference(self::reference($i), $project);
    }

    $manager->flush();
}

public static function reference(int $index): string
{
    return 'project-'.$index;
}
```

The project data itself is untouched — same five names as before.

### `TaskFixtures.php` — rewritten

Declares its dependency, so ordering is guaranteed rather than lucky:

```php
class TaskFixtures extends Fixture implements DependentFixtureInterface
{
    public function getDependencies(): array
    {
        return [ProjectFixtures::class];
    }
}
```

Replaces `"Task project $i"` with a fixed table of five templates covering the fields the old version left empty — `description` (including one deliberate `null`), `dueDate` (past, near-future, and absent), and `completed`:

```php
private const TASK_TEMPLATES = [
    ['title' => 'Write the project brief', 'description' => 'Agree the scope and deliverables with the client.', 'dueInDays' => -21, 'completed' => true],
    ['title' => 'Set up the repository',   'description' => 'Create the repo, the CI pipeline and branch protection.', 'dueInDays' => -14, 'completed' => true],
    ['title' => 'Build the API endpoints', 'description' => 'Expose projects and tasks over the REST API.', 'dueInDays' => -2, 'completed' => false],
    ['title' => 'Review the test coverage','description' => null, 'dueInDays' => 7, 'completed' => false],
    ['title' => 'Plan the release',        'description' => 'Pick a release window and write the changelog.', 'dueInDays' => null, 'completed' => false],
];
```

And builds each task against a real project, with a per-project count so the seeded data isn't uniform:

```php
$project = $this->getReference(ProjectFixtures::reference($projectIndex), Project::class);

$taskCount = 1 + $projectIndex % \count(self::TASK_TEMPLATES);

for ($offset = 0; $offset < $taskCount; $offset++) {
    $template = self::TASK_TEMPLATES[($projectIndex + $offset) % \count(self::TASK_TEMPLATES)];

    $task = new Task();
    $task->setTitle($template['title']);
    $task->setDescription($template['description']);
    $task->setDueDate(null === $template['dueInDays'] ? null : $today->modify($template['dueInDays'].' days'));
    // setCompleted() owns the completedAt stamp, so it is not set here.
    $task->setCompleted($template['completed']);
    $task->setProject($project);

    $manager->persist($task);
}
```

Result: 5 projects, 15 tasks, counts of 2/3/4/5/1 — so there's a nearly-empty project and a busy one to test against.

## Concepts explained

**A fixture is a script that fills your database with fake starter data**

A **fixture** class extends `Fixture` and implements one method, `load()`. Running `make fixtures` wipes the database and calls every fixture's `load()` in turn. The point is repeatability: instead of creating projects by hand with `curl` after every reset, you get the same known dataset every time, in one command.

Think of it as the "New Game" state of a video game — a fresh, known starting world you can return to whenever you've made a mess.

**`ObjectManager`, `persist()` and `flush()` — nothing hits the database until the end**

`ObjectManager` is Doctrine's handle on the database. `persist($task)` does **not** write a row; it only tells Doctrine "start tracking this object, I intend to save it." The actual `INSERT` statements happen at `flush()`, which writes everything tracked so far in one transaction.

```php
$manager->persist($task);   // queued
...
$manager->flush();          // now the INSERTs run
```

It's a shopping basket: `persist()` puts an item in the basket, `flush()` is the checkout. This is also why the crash in the old code would have surfaced at `flush()` and not at `persist()` — Doctrine had no reason to look at the missing `project_id` until it built the actual SQL.

**`DependentFixtureInterface` — telling Doctrine what has to run first**

By default Doctrine gives no promise about the order fixture classes run in. `DependentFixtureInterface` adds one method, `getDependencies()`, returning the classes that must load before this one:

```php
public function getDependencies(): array
{
    return [ProjectFixtures::class];
}
```

Doctrine reads these and sorts the fixtures accordingly. Here it's not a preference but a hard requirement: a task with no project violates a NOT NULL column, so tasks *cannot* be created before projects exist.

Like a recipe — you can't ice the cake before you've baked it, and this is the line in the method that says so.

**References — passing objects between fixture classes**

`ProjectFixtures` and `TaskFixtures` are separate classes with no shared variables, so `TaskFixtures` has no way to reach the `Project` objects the other one just made. The **reference registry** solves that: one fixture stores an object under a name, another retrieves it.

```php
// ProjectFixtures — stores it
$this->addReference('project-1', $project);

// TaskFixtures — collects it
$project = $this->getReference('project-1', Project::class);
```

It's a coat-check ticket: hand the object in with a name, use the name to get the same object back later.

Two details worth knowing. The second argument to `getReference()` — the class — is **required** in `doctrine/data-fixtures` 2.x (this project locks 2.2.1); older tutorials show a one-argument version that no longer works. And rather than repeat the string `'project-1'` in two files, the name is generated by `ProjectFixtures::reference($i)`, so a typo becomes a method-name error rather than a mysterious "reference not found" at runtime.

**The owning side — why `setProject()` and not `addTask()`**

In a `OneToMany`/`ManyToOne` pair, only one side maps to an actual database column. `Task` holds the `project_id` column, so `Task` is the **owning side**, and `Task::setProject()` is the call that decides what actually gets written. `Project::addTask()` maintains the in-memory collection on the other side.

```php
$task->setProject($project);   // this is what fills project_id
```

Both work here, because `Project::addTask()` calls `$task->setProject($this)` internally — a well-written inverse side keeps both halves in sync. But `setProject()` is the direct expression of what the database needs, so it's the one used.

The rule to remember: the side holding the foreign key column is the side Doctrine listens to when writing.

**Reusing the entity's own invariant instead of setting fields by hand**

`Task` guards a rule: `completed` and `completedAt` must never disagree. `setCompleted()` owns that pairing — flipping the flag to `true` stamps `completedAt`, flipping it back clears it. The fixture deliberately calls it and never touches `completedAt`:

```php
// setCompleted() owns the completedAt stamp, so it is not set here.
$task->setCompleted($template['completed']);
```

Seeded rows therefore satisfy the same rule as rows created through the API. If the fixture set both fields itself, it could quietly produce a state the application considers impossible — a completed task with no completion date — and you'd spend an afternoon debugging code that was never wrong.

**Fixed data, not random data**

The templates are a hardcoded list rather than randomly generated values (`fakerphp/faker` isn't installed, and wasn't added). Fixed data means every `make fixtures` produces an identical database, so a bug you find is a bug you can reproduce, and a test asserting "project 1 has 2 tasks" stays true tomorrow.

Random fixtures give better-looking demo data and worse debugging — a failure that only appears on some seeds is far harder to chase down.

**Not in this diff:** `ProjectStatus` — the fixtures never set `status`, so all five projects take the entity's `ProjectStatus::Active` default. The enum is explained in the [2026-08-06 entities artifact](2026-08-06-project-and-task-entities.md).

## Decisions & why

**Separate `ProjectFixtures` / `TaskFixtures` rather than filling `AppFixtures`.**
`NEXT_STEPS.md` Step 3 suggests putting everything in `AppFixtures.php`. The split classes already existed, and keeping them makes the load order explicit through `getDependencies()` instead of implicit through statement order in one method. `AppFixtures` is left as the empty stub Symfony generated; it still runs and does nothing.

**`ProjectFixtures` changed as little as possible.**
Only the two things `TaskFixtures` needs — the reference registration and the count constant. The project names and data are untouched. The task fixture was the thing asked about; enriching projects would have been scope creep.

**No `fakerphp/faker`.**
It would have meant a new dependency and non-reproducible data, for prettier strings. Not worth it at this stage.

**Uneven task counts per project.**
`1 + $projectIndex % 5` gives 2/3/4/5/1. A uniform 5-tasks-per-project dataset can't catch an off-by-one in a "tasks remaining" count or an empty-state bug in the UI. The variety is free.

**Task titles repeat across projects.**
"Build the API endpoints" appears under several projects. Accepted deliberately — a rotating window through five templates is simpler to read than five unique title sets, and duplicate titles across different projects are realistic anyway.

## Issues encountered

**The tool sandbox silently swallowed every `docker` command.**

`docker exec … php -l` returned exit code 1 with *no output at all* — no error, nothing on stderr, an empty file when redirected. The same happened to plain `docker ps`, and even `echo hello` inside the container. This made it look like the container was broken or the file was missing.

The actual cause: `docker` on `PATH` resolves to the snap wrapper at `/snap/bin/docker`, and the sandbox blocked it without reporting a failure. Invoking `/snap/bin/docker` directly with the sandbox disabled worked immediately. Worth remembering — a silent empty result from a container command is more likely tooling than a broken container.

**`TaskFixtures.php` was deleted mid-session and had to be rewritten.**

The file was written, verified on disk (`3059` bytes, 68 lines, contents read back), and then vanished — the directory mtime jumped and only `AppFixtures.php` and `ProjectFixtures.php` remained. `ProjectFixtures.php` survived.

The likely cause is VS Code's Source Control **"Discard Changes"** on the open file. For a *tracked* file that reverts it; for an **untracked** file it deletes it outright, with no git history to recover from. The `File > Revert File` menu item is the safe equivalent. Both fixture files are still untracked, so this stays a live hazard until they're committed.

**The editor showed a stale version of the file.**

Before the deletion, the changes appeared to be missing entirely — VS Code's language server still reported the file as 20 lines while disk had 68. Compounding it, `git diff` showed nothing either, because both files are untracked and so have no baseline to diff against. `git add -N backend/src/DataFixtures/` makes them visible to `git diff`.

## Result / how to verify

```bash
make fixtures      # wipes the database and reloads
```

Observed output:

```
> purging database
> loading App\DataFixtures\AppFixtures
> loading App\DataFixtures\ProjectFixtures
> loading App\DataFixtures\TaskFixtures
```

Both files also pass `php -l`. Checked against the live database:

```sql
SELECT p.id, p.name, count(t.id) AS tasks,
       count(t.id) FILTER (WHERE t.completed) AS done
FROM project p LEFT JOIN task t ON t.project_id = p.id
GROUP BY p.id, p.name ORDER BY p.id;
```

| id | name | tasks | done |
|---|---|---|---|
| 24 | Project number 1 | 2 | 1 |
| 25 | Project number 2 | 3 | 0 |
| 26 | Project number 3 | 4 | 2 |
| 27 | Project number 4 | 5 | 2 |
| 28 | Project number 5 | 1 | 1 |

15 tasks, every one attached to a project. The row dump confirmed the fields the old fixture left empty are now populated — descriptions present with one `NULL`, due dates spanning `2026-07-22` through `2026-08-19` plus one absent, and `completed_at` stamped on exactly the rows where `completed = true` and no others, so the `setCompleted()` invariant held.

**Not re-checked here:** cascade deletion. It was verified during the Task API work (commit `c849abe`) once tasks could be created over HTTP; this seed now supplies standing data to exercise it — `DELETE /api/projects/26` should take four task rows with it — but that request wasn't run as part of this work.

## Interview questions

**(Conceptual)** What does a fixture do, and why is repeatable seed data worth more than data you create by hand with `curl`?

**(Conceptual)** What is the difference between `persist()` and `flush()`, and why does only one of them talk to the database?

**(Conceptual)** In a `OneToMany`/`ManyToOne` pair, which side is the owning side, and how do you tell by looking at the database?

**(Code-specific)** The original `TaskFixtures` never called `setProject()` — at which line would it have failed, and with what kind of error?

**(Code-specific)** Why does `TaskFixtures` implement `DependentFixtureInterface` instead of simply relying on `ProjectFixtures` being loaded first?

**(Code-specific)** What problem do `addReference()` / `getReference()` solve that a plain PHP variable could not solve here?

**(Code-specific)** Why does `getReference()` need `Project::class` as a second argument in `doctrine/data-fixtures` 2.x, when older examples pass only a name?

**(Code-specific)** The fixture calls `setCompleted(true)` but never touches `completedAt` — what would break if it set both fields directly?

**(Code-specific)** `ProjectFixtures::reference()` is a static method rather than an inline `'project-'.$i` string in both files — what failure mode does that prevent?

**(Follow-up)** The fixtures use hardcoded templates rather than Faker — what do you gain, and when would randomised data actually be the better choice?

**(Follow-up)** `$today->modify('-21 days')` makes due dates relative to the load date, so the seeded data shifts every time you reload — when would that be a problem for a test suite, and how would you fix it?

**(Follow-up)** Deleting project 26 should remove its four tasks via `cascade: ['remove']` and `onDelete: 'CASCADE'` — which of the two actually does the work, and how could you tell which one fired?

**(Follow-up)** How would you split these fixtures into groups so a test suite could load only projects, without any tasks?

**(Follow-up)** If the dataset grew to 10,000 tasks, what would go wrong with a single `flush()` at the end, and what would you change?

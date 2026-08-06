# 2026-08-06 — Project and Task entities, and the Symfony ideas behind them

## Goal

Give the app its first two real database tables: `Project` and `Task`, with a one-to-many link between them. Until now `backend/src/Entity/` was empty, so the API had nothing to expose.

## What was done

- `backend/src/Enum/ProjectStatus.php` — a PHP backed enum with three cases: `active`, `archived`, `on_hold`.
- `backend/src/Entity/Project.php` — id, name, description, status, createdAt, and a collection of tasks.
- `backend/src/Entity/Task.php` — id, title, description, completed, dueDate, completedAt, and the project it belongs to.
- `backend/src/Repository/ProjectRepository.php` and `TaskRepository.php` — the query classes each entity points at.
- Also committed a pending `symfony/expression-language` install that was sitting in the working tree.

Written by hand, not with `make:entity`: `symfony/maker-bundle` isn't a dependency of this project, and the mapping attributes were going to be rewritten anyway.

## Symfony concepts used here, in plain words

This is the vocabulary you need to read the two new files. Nothing here is specific to this project — it's the standard Symfony/Doctrine mental model.

**Entity**
A plain PHP class that represents one row in a database table. `Project` is a class; each project you save is one object of that class and one row in the `project` table. No parent class, no magic — just properties and getters/setters.

**Doctrine / ORM**
Doctrine is the library that moves data between your PHP objects and the SQL database. ORM stands for *Object-Relational Mapper*: objects on one side, relational tables on the other, Doctrine translating between them. You write `$project->setName('X')`, Doctrine writes the `UPDATE` statement.

**Attributes (the `#[...]` lines)**
Attributes are notes attached to a class or property that another tool reads. `#[ORM\Column(length: 255)]` above `$name` tells Doctrine "this property is a database column, `VARCHAR(255)`". The PHP engine itself ignores them; Doctrine reads them at runtime to learn the shape of your table. Older Symfony projects used YAML or XML files for this — same information, different place.

**Mapping**
The whole set of those attributes together. "The mapping is valid" means Doctrine understands how to turn your classes into tables. You can check it any time:

```
make console c="doctrine:schema:validate --skip-sync"
```

**Repository**
A class whose job is *finding* entities. The entity holds data; the repository holds the queries. `ProjectRepository` extends `ServiceEntityRepository`, which gives you `find()`, `findAll()`, `findBy()` for free. Custom queries (like "all projects with overdue tasks") would go in there as new methods.

**Relations: `OneToMany` and `ManyToOne`**
One project has many tasks; each task belongs to one project. That's the same relationship seen from both ends, so it's written twice — `#[ORM\OneToMany]` on `Project::$tasks`, `#[ORM\ManyToOne]` on `Task::$project`.

**Owning side vs inverse side**
Only one of the two sides actually controls the database. The side holding the foreign key column — here `Task`, because the `task` table has the `project_id` column — is the **owning side**. The other side (`Project::$tasks`) is the **inverse side**, and it exists for convenience in PHP. This is why the two attributes name each other:

- `Task::$project` says `inversedBy: 'tasks'` — "the inverse of me is the `$tasks` property"
- `Project::$tasks` says `mappedBy: 'project'` — "I'm mapped by the `$project` property over there"

The practical consequence: **Doctrine only saves what the owning side says.** Pushing a task into `$project->getTasks()` without also setting `$task->setProject($project)` saves nothing. That's exactly why `addTask()` sets both sides for you — the helper exists to make it impossible to forget.

**Collection**
`Project::$tasks` isn't a plain array, it's a Doctrine `Collection` (an `ArrayCollection` when new). It behaves like an array but can also load lazily — the tasks aren't fetched from the database until you actually touch the collection. It gets created in the constructor, which is the only reason `Project` has one.

**Cascade**
`cascade: ['persist', 'remove']` means operations flow from the project down to its tasks. Save a project with new tasks attached → the tasks get saved too, no separate `persist()` call. Delete a project → its tasks get deleted too, instead of leaving rows pointing at a project that no longer exists.

**Migration**
A file with the SQL needed to move the database from its current shape to the new one. You don't write it by hand — Doctrine compares your entities against the live database and generates the difference:

```
make migration    # generate the file
make migrate      # run it
```

Migrations are committed to git, so every environment applies the same steps in the same order.

**Bundle**
A plugin for Symfony. `DoctrineBundle`, `ApiPlatformBundle`, `TwigBundle` — each one wires a library into the framework and adds its config and console commands. The list lives in `backend/config/bundles.php`.

**Service container, autowiring, dependency injection**
Symfony keeps a registry of ready-to-use objects called *services*. When a class needs one, it asks for it in its constructor by type and Symfony hands it over — that's *dependency injection*, and Symfony figuring out which service matches the type on its own is *autowiring*. `HealthController` is the example already in this project: it type-hints `Connection` in its constructor and just receives a live database connection, never creating one.

**Console**
`bin/console` is the command-line entry point to the app. Every command in this write-up runs through it; the `Makefile` just wraps it in the right `docker compose exec`. `make console c="list"` shows everything available.

**Backed enum**
A PHP 8.1 language feature (not Symfony's) — a fixed set of named cases, each backed by a scalar value. `ProjectStatus::Active` is the case, `'active'` is what's stored in the database. Doctrine converts between the two via `enumType:`, so `$project->getStatus()` always returns a `ProjectStatus` object, never a loose string.

## Decisions & why

**A backed enum for `status`, not a plain string.**
Three payoffs. PHP-side, an invalid status is a `TypeError` at the point of assignment rather than a bad row discovered later. Doctrine-side, `enumType:` handles the conversion in both directions. API Platform-side, version 4 serializes backed enums natively *and* lists the allowed values in the generated OpenAPI schema — so the docs stay correct without anyone maintaining them.

**Stored as `VARCHAR`, not a Postgres `ENUM` type.**
`enumType:` maps to `VARCHAR(255)`, so the allowed values live in PHP only. Adding a fourth status later is a one-line code change with no migration. A native Postgres enum would push that validation into the database at the cost of a migration for every new case — not worth it at this stage.

**`status` and `createdAt` are non-nullable, with defaults set in PHP.**
`$status` is typed `ProjectStatus` (not `?ProjectStatus`) and defaults to `Active`; `$createdAt` is set in the constructor. A project therefore can't exist in a half-initialized state. `createdAt` keeps a setter so fixtures can backdate records.

**`Task::$project` is `nullable: false` with `onDelete: 'CASCADE'`.**
A task with no project has no meaning in this domain, so the column is required. The ORM-level `cascade: ['remove']` handles deletion through the entity manager; the database-level `ON DELETE CASCADE` is the backstop for deletes issued as raw SQL.

Trade-off to be aware of: the standard `removeTask()` helper sets the owning side to `null`, which will fail on flush against a non-null column. Fine as long as tasks get *moved* to another project or deleted outright, never orphaned. If orphaning ever needs to be a legal operation, the fix is `orphanRemoval: true` on the `OneToMany`.

**`dueDate` is a `DATE`, `completedAt` is a `TIMESTAMP`.**
A due date is a day ("due Friday"); a completion is a moment in time. Different granularity, different column type. Both are `DateTimeImmutable` — immutable date objects can't be modified by accident after being handed to another part of the app.

**`completed` and `completedAt` are not linked.**
Setting `completed = true` does not stamp `completedAt`. Deliberate: that's a state-transition rule, and it belongs in a service or a Doctrine lifecycle listener where it can be tested, not hidden inside a setter that callers expect to be dumb.

**No `#[ApiResource]` yet.**
This piece of work was scoped to the entities. Nothing is exposed through API Platform, so Swagger UI still lists no endpoints — the enum's OpenAPI payoff isn't visible until the attribute is added. That's the natural next step.

## Result / how to verify

```
make TTY=-T console c="doctrine:schema:validate --skip-sync"   # [OK] The mapping files are correct.
make TTY=-T console c="doctrine:schema:update --dump-sql"      # preview the SQL, change nothing
```

The generated SQL confirms the intent:

```sql
CREATE TABLE project (id INT GENERATED BY DEFAULT AS IDENTITY NOT NULL, name VARCHAR(255) NOT NULL,
  description TEXT DEFAULT NULL, status VARCHAR(255) DEFAULT 'active' NOT NULL,
  created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id));

CREATE TABLE task (id INT GENERATED BY DEFAULT AS IDENTITY NOT NULL, title VARCHAR(255) NOT NULL,
  description TEXT DEFAULT NULL, completed BOOLEAN DEFAULT false NOT NULL, due_date DATE DEFAULT NULL,
  completed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, project_id INT NOT NULL, PRIMARY KEY (id));

ALTER TABLE task ADD CONSTRAINT FK_527EDB25166D1F9C FOREIGN KEY (project_id)
  REFERENCES project (id) ON DELETE CASCADE NOT DEFERRABLE;
```

No migration has been generated yet — the tables don't exist in the database. To create them:

```
make migration && make migrate
```

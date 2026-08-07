---
name: create-artifact
description: Invoked by the user typing /create-artifact (and also whenever the user asks in words to make an artifact, summary, writeup, or documentation of the changes in a git branch, diff, PR, or commit — phrasings like "make an artifact of the changes in this branch", "summarize what I changed", "document this PR", "explain the changes"). Produces a shareable artifact of the branch's changes that does two things — first it documents what was implemented, and second (crucially) it ALSO explains, in plain beginner-friendly language, the core framework/language concepts those changes rely on (for a Symfony diff that might be Entity, OneToMany, ManyToOne, Cascade, Enum, or API Resource). Do NOT just describe what was implemented; always add a section that teaches the underlying concepts simply, and a final section of interview-style questions (questions only, no answers) grounded in the diff. Works for any framework such as Symfony, Laravel, Django, Rails, or React — detect the stack from the diff.
---

# Create Artifact

Invoked by `/create-artifact` (or by an equivalent request in words). Produce a shareable artifact that does three jobs at once:

1. **Documents what changed** in a git branch (the implementation).
2. **Teaches the concepts** those changes depend on, in language a beginner could follow.
3. **Tests understanding** with interview-style questions grounded in the actual diff.

Jobs 2 and 3 are what make this skill different from a plain diff summary. A normal changelog tells someone *what* was done. This artifact also makes sure a newcomer understands *the ideas* the code is built on and can *check* that understanding — so don't skip or shortchange the concept explanations or the questions.

## Workflow

### 1. Gather the changes

Figure out what changed on the branch. Prefer real git data over guessing:

```bash
# What branch are we on, and what's it diffed against?
git branch --show-current
git log --oneline main..HEAD        # or master/develop as the base
git diff --stat main..HEAD
git diff main..HEAD
```

If the base branch is unknown, ask the user or infer it (`main`, `master`, `develop`). If there's no git repo or the diff isn't available, work from whatever the user has provided in the conversation, and note that the summary is based on that.

### 2. Detect the stack and the concepts in play

Read the diff and identify the framework/language (Symfony, Laravel, Django, Rails, React, plain JS, etc.) from file paths, imports, annotations/attributes, and syntax.

Then list the **framework concepts that actually appear in the diff** — only the ones genuinely used. Don't pad the list with concepts that weren't touched. For a Symfony diff that adds a related collection with an enum status field exposed over an API, that might be: Entity, OneToMany, ManyToOne, Cascade, Enum, API Resource. A React diff might yield: Component, Props, State, Hook, Context.

### 3. Choose the format — detect the repo's convention first

Before picking a format, **look at how the repo already documents things**, and match it. Consistency with the project beats an abstractly "nicer" format.

- Check for an existing convention: a `docs/`, `artifacts/`, `changelog/`, or similar directory; existing `.md` writeups; a PR-description template. If the project already keeps change writeups in Markdown under `artifacts/`, produce Markdown that fits right in (and consider matching their filename pattern, e.g. `YYYY-MM-DD-short-title.md`).
- If there's **no** existing convention, choose by audience: **HTML** for a styled, shareable read (headings, code blocks, a scannable glossary), or **Markdown** for something lightweight meant to drop into the repo or a PR description.
- When genuinely unsure and there's no signal from the repo, prefer HTML for readability.

Save the file to `/mnt/user-data/outputs/` and present it. If the repo has a conventional location (like `artifacts/`), also mention that's where it would live in the project.

### 4. Write the artifact — ALWAYS these three sections

Use this exact top-level structure:

```
# <Branch name / feature title>

## What changed
<Implementation summary: the concrete changes, organized by file or by feature.
 What was added, modified, removed, and why. Reference specific classes,
 methods, fields, endpoints. This is the "what was done" part.>

## Concepts explained
<For EACH concept detected in step 2, a short beginner-friendly entry.
 See the rules below. This is the "learn the ideas" part — never omit it.>

## Interview questions
<Interview-style questions grounded in THIS diff. Questions only — no answers.
 See the rules below. This is the "test your understanding" part.>
```

**Rules for "What changed":**
- Organize by feature or by file, whichever is clearer for this diff.
- Be concrete: name the entities, fields, relationships, endpoints, and methods that were added or changed.
- Keep it factual and tied to the actual diff — don't invent changes that aren't there.

**Rules for "Concepts explained":**
- Only include concepts that actually appear in the changes (from step 2). If a concept is *installed but not used in this diff* (e.g. API Platform is present but no entity here carries an `#[ApiResource]` attribute), leave it out — and it's worth a one-line note saying why, so the reader isn't misled.
- Explain each in **very simple language**, as if to someone new to the framework. Avoid jargon; when a term is unavoidable, define it in the same breath.
- Give each concept real depth — aim for a short paragraph that (a) says what it is, (b) says what it does *in this specific change*, and (c) includes a one-line analogy or real-world comparison. Don't stop at a single sentence; a beginner should finish the entry actually understanding the idea.
- **Show code from this branch.** For each concept (or a tight group of related ones), include a minimal snippet lifted from the actual diff and point directly at where the concept lives in it ("here, `status` is the Enum; the `#[ORM\OneToMany]` line is the relationship"). Real code from their branch teaches far better than invented examples — never fabricate snippets.
- Prefer clarity over completeness, but err toward *more* explanation and *more* real code rather than less. The teaching section is the reason this artifact exists.

**Rules for "Interview questions":**
- Ground every question in *this* diff — the entities, fields, relationships, enum, and design choices that actually appear. A question a reader could answer without having seen these changes is too generic; cut it or make it specific ("Why is `onDelete: 'CASCADE'` set on `Task.project` in addition to the Doctrine-level `cascade`?" beats "What is cascade?").
- **Questions only — no answers.** This is a self-test. Do not include model answers, hints, or a solutions key.
- Mix three kinds and label them so the reader knows what's being tested:
  - **Conceptual** — the idea behind a concept used here ("What does a OneToMany relationship model, and why is it paired with a ManyToOne?").
  - **Code-specific** — tied to a concrete decision in the diff ("The `status` column defaults to `'active'` at the database level *and* the property defaults to `ProjectStatus::Active` in PHP — why set it in both places?").
  - **Follow-ups** — deeper "what if / how would you extend" probes ("How would you add a `Task`-level status enum without breaking existing rows?", "What migration would this relationship require, and what could go wrong running it against production data?").
- **Match difficulty to the diff.** Gauge how advanced the changes are and pitch questions to match — a simple CRUD entity gets fundamentals; a diff with cascades, custom queries, or tricky migrations earns senior-level follow-ups. Don't force a fixed number; a small diff might warrant 4–6 questions, a large one 10–12. Order them roughly easy → hard.
- Keep each question a single clear sentence. No multi-part compound questions stuffed into one.

### 5. Present, and only then handle git if asked

Present the artifact file to the user. If — and only if — the user also asked to commit and/or push, do that as a separate step after they've seen the artifact, unless they've clearly authorized doing it all in one go.

## Concept explanation examples (Symfony)

These show the target *tone and depth* — plain, friendly, analogy-first. Match this style for whatever framework you detect.

**Example — Entity:**
> An **Entity** is a PHP class that maps to a table in your database. Each object of the class is one row. So a `Task` entity means there's a `task` table, and every `Task` you create is a row in it. You work with normal PHP objects and Doctrine handles saving them as rows.

**Example — OneToMany / ManyToOne:**
> These describe a relationship between two entities. **OneToMany** means "one of these has many of those" — e.g. one `Project` has many `Task`s. **ManyToOne** is the same link seen from the other side — each `Task` belongs to one `Project`. They're two ends of the same connection.

**Example — Cascade:**
> **Cascade** tells Doctrine to carry an action across a relationship automatically. With `cascade: ['persist', 'remove']` on a `Project`'s tasks, saving the project also saves its new tasks, and deleting the project deletes its tasks too — you don't have to do each one by hand.

**Example — Enum:**
> An **Enum** is a fixed list of allowed values for a field. Instead of storing a free-text status where anyone could type "dnoe" by mistake, a `TaskStatus` enum limits it to a set like `TODO`, `IN_PROGRESS`, `DONE`. It prevents invalid values and makes the options explicit in code.

**Example — API Resource:**
> Marking an entity as an **API Resource** (API Platform) tells the framework to automatically create REST endpoints for it — GET, POST, PUT, DELETE — without you writing a controller. Add the attribute to your `Task` entity and you instantly get a `/api/tasks` API for reading and changing tasks.

## Interview question examples (Symfony)

These show the target style — specific to the diff, no answers, mixed kinds, ordered easy → hard.

> **(Conceptual)** What problem does a backed enum like `ProjectStatus` solve that a plain string column would not?
> **(Conceptual)** In a `OneToMany`/`ManyToOne` pair, which side owns the relationship, and how does Doctrine decide?
> **(Code-specific)** `Project::$tasks` uses `cascade: ['persist', 'remove']` while `Task::$project` uses `onDelete: 'CASCADE'` — what does each one do, and why have both?
> **(Code-specific)** Why does `addTask()` call `$task->setProject($this)` rather than just adding to the collection?
> **(Follow-up)** How would you add a `status` enum to `Task` and write a safe migration for existing rows?
> **(Follow-up)** If you later exposed `Project` as an API Resource, what would you do to avoid serializing every task in the collection on each request?

## Notes

- The teaching and interview sections are the whole point — if you find yourself only describing the implementation, stop and add the concepts and the questions.
- Explain and quiz only what's in the diff. A concept the branch didn't touch doesn't belong in the artifact, and neither does a question a reader could answer without seeing these changes.
- Interview questions carry **no answers** — it's a self-test.
- Keep the beginner framing consistent: someone who knows how to code but is new to *this* framework should come away understanding both the change and the ideas behind it.

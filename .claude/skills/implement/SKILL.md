---
name: implement
description: Implement a plan document from `docs/` or `docs/plans/` by delegating its batches to the php-developer agent. Takes the plan's title or file name, optionally followed by a scope (a phase, an item, or "all"). Only run when the user invokes /implement.
argument-hint: "<plan title> [next | phase <n> | all | <item text>]"
disable-model-invocation: true
---

# Implement a plan

Arguments: `$ARGUMENTS`

You are the coordinator. The `php-developer` agent does the coding; you resolve the plan, pick the work, delegate it one batch at a time, check the result, and report.

## 1. Resolve the plan document

- Plans live in `docs/` (e.g. `docs/CODE_QUALITY_PLAN.md`) and in `docs/plans/`, where the `product-owner` agent writes plans generated from the specs in `docs/specs/`. Specs are not plans; never match them.
- The first part of the arguments is the plan's title. Match it against the Markdown files in `docs/` and `docs/plans/` by file name (case-insensitive, ignoring `_`, `-`, spaces and the `.md` extension, e.g. `code quality` → `docs/CODE_QUALITY_PLAN.md`, `training plan` → `docs/plans/training-plan.md`) and by the document's `# H1` title.
- If exactly one file matches, use it. If several match, or none, list the candidates (file name and H1) and ask the user which one. Do not guess.
- If no arguments were given, list the plans in `docs/` and `docs/plans/` that contain checkboxes and ask which to implement.
- If the plan lists blocking "Open questions" that gate items in scope, stop before those items and ask the user.

## 2. Pick the scope

Read the whole plan, then work out the scope from the rest of the arguments:

| Scope | Meaning |
|---|---|
| *(none)* or `next` | The first unchecked (`[ ]` or `[~]`) item in the lowest unfinished phase. |
| `phase <n>` | Every unchecked item in that phase, in order. |
| `all` | Every unchecked item in the plan, phase by phase, in order. |
| any other text | The unchecked item(s) whose text matches it. Ask if it is ambiguous. |

- Skip items marked `[x]` (done) or `[-]` (deferred).
- Respect gates the plan states, such as phases that must not start until the user explicitly asks (e.g. Phase 7 in the code quality plan). If the scope reaches a gated item, stop before it and say so.
- Before delegating more than one batch, tell the user the list of batches you are about to run in one short message, then proceed.

## 3. Delegate each batch to php-developer

Run batches **sequentially**, one `php-developer` agent per batch, in the foreground — later batches often depend on earlier ones, and they share the working tree. Never run batches in parallel.

Give each agent a self-contained prompt that includes:

- The plan path. For `docs/CODE_QUALITY_PLAN.md` the agent already knows the rules from `CLAUDE.md`, so tell it not to re-read the plan beyond the item itself. For any other plan, tell it to read that plan's rules section first.
- The exact item to implement, quoted from the plan, with its phase heading.
- That it must do only that batch, follow the definition of done (Pint, affected tests and the full suite, `npm run build` if frontend files changed — from `CLAUDE.md` for the code quality plan, otherwise from the plan itself), and tick the item in the plan (`[x]`, or `[~]` with a short note if partial) and fix any drifted line numbers or counts.
- That new problems it finds go under the plan's "Open decisions" section rather than being fixed on the spot.
- That it must not commit, and must report: files changed, tests added or updated, test and build results, and anything it could not verify.

## 4. Check each result before continuing

After each agent returns:

- Confirm from its report that the suite passed and the plan item was updated. Run `git diff --stat` and re-read the plan item to verify, rather than trusting the report alone.
- If tests failed, the build failed, the item was left `[~]`, or the agent hit a decision that belongs to the user, **stop**. Do not start the next batch. Report what happened with the relevant output.
- Otherwise continue with the next batch in scope.

## 5. Report

End with a short summary:

- Each batch run and its outcome (done / partial / failed).
- Tests added or updated, and the final suite and build status.
- Anything added to "Open decisions" and anything that still needs the user.
- What the next unchecked item in the plan is.

Do not commit, push or open PRs unless the user asks.

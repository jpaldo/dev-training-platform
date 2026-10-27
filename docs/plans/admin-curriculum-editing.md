# Admin Curriculum Editing Plan

Source: user request of 2026-10-27, "allow admin to modify checklists and the weekly training session details". There is no spec; the **Requirements** section below is the spec of record until `docs/specs/admin-curriculum-editing.md` exists · Last updated: 2026-10-27

**Status legend:** `[x]` done · `[ ]` to do · `[~]` in progress · `[-]` deferred

## Goal

Admins can change a program's weekly content in the app instead of asking a developer to edit `L4TrainingProgramSeeder`. That content is each week's title and description, its activities and their submission fields and scoring dimensions, and the week's checklist with its items. Edits apply at once to every session of the program. Participants' answers and mentors' ticks stay attached to the items they were given for. Every change is audit-logged, and re-running the seeder no longer overwrites what an admin changed.

## Rules for every batch

- Follow the "Code Quality Rules" in `CLAUDE.md` for every new or touched line.
- Keep each batch small and independently shippable: one concern, its tests, green suite.
- Do only the batch you were given. Record new problems under "Open decisions" instead of fixing them on the spot.
- Definition of done: `vendor/bin/pint --dirty --format agent`, the full suite passing (`php artisan test --compact`), `npm run build` if CSS, JS or Tailwind classes changed, and the item ticked here (`[x]`, or `[~]` with a short note).
- `php` is not on the default PATH in agent shells. Use `~/.config/herd-lite/bin/php`.
- **Type values stay plain strings.** `gate_criteria.criterion_type` and a submission field's `configuration.type` are type discriminators. They stay plain strings (decided 2026-10-22 in `docs/CODE_QUALITY_PLAN.md`), listed once as constants on the model: the existing `GateCriterion::TYPE_*`, and a new `ActivityRequirement::FIELD_TYPES` added in 2.5. Do not create enums for them.
- **Writes go through services.** Each write runs in `DB::transaction()` with its `AdminAuditService::record()` row. If nothing changed, nothing is saved and no audit row is written (same as `TrainingSessionResourceService::rename()`).
- **Never replace a `configuration` column wholesale.** Merge into the stored array so keys this plan does not edit survive: `expected_files` (from `docs/plans/repository-ai-check.md` 1.2), `options` on `select` fields, and `deprecated`.
- **Never delete** activities, submission fields, or checklist items that a mentor has reviewed. They are retired so submissions, scores and ticks keep their rows (see Design).
- **No migrations.** This plan adds no table or column. If a batch seems to need one, stop and record it under Open decisions.
- After 1.1, 2.7 and 3.1, update the entries named in their **Do** in `docs/plans/developer-program-and-training-sessions.md` ("Resolved in admin-curriculum-editing X.Y: …"), keeping the original text struck through, as that plan does.

## Requirements

| ID | Requirement | Covered by |
|---|---|---|
| CUR-1 | An admin opens a **Curriculum** area from the sidebar. It lists the programs, then a program's weeks and the sessions that use it, then a week's details, activities and checklist, then an activity's details, fields and scoring. Coaches and participants get 403; guests are sent to login | 1.2, 1.3, 2.2 |
| CUR-2 | An admin edits a week's title and description. The week number, its position and whether it is active do not change | 2.1 |
| CUR-3 | An admin adds an activity to a week (title, description, objectives, instructions). It goes last | 2.3 |
| CUR-4 | An admin edits an activity's title, description, objectives and instructions | 2.2 |
| CUR-5 | An admin moves an activity up or down within its week | 2.4 |
| CUR-6 | An admin deactivates and reactivates an activity. Its submissions and scores are kept. The last active activity of a week cannot be deactivated | 2.4 |
| CUR-7 | An admin edits an activity's submission fields (label, help, type, required), adds fields, reorders them, removes them and restores them. A field's key never changes, so submitted answers keep their values | 2.5, 2.6 |
| CUR-8 | An admin edits an activity's scoring dimensions (labels and maxima, 1 to 9 dimensions, maxima summing to 100) until the activity has its first score. After that they are read-only, with the reason shown | 2.7 |
| CUR-9 | An admin edits a week's checklist title and description | 3.2 |
| CUR-10 | An admin adds checklist items, edits their type, title and description, and reorders them | 3.2, 3.3 |
| CUR-11 | An admin removes a checklist item and can restore it. Removing an item never moves a mentor's tick to another item. A checklist keeps at least one item and has at most one session-logs item and at most one weekly-video item | 3.4 |
| CUR-12 | A removed checklist item no longer counts anywhere. A removed weekly-video item no longer makes the week ask for a video, and the Gate board's "criteria met" counts only items still on the checklist | 3.1 |
| CUR-13 | Edits apply at once to every session of the program (upcoming, active and completed). The program, week and activity pages name the sessions that use the program and show a warning while any of them is active. The session dashboard's Activities tab links to the program's curriculum | 1.2, 1.3 |
| CUR-14 | Every change writes one `AdminAuditLog` row through `AdminAuditService`, with the program, week and item ids and the changed values before and after. A save that changes nothing writes no row | 2.1 to 2.7, 3.2 to 3.4 |
| CUR-15 | Re-running `L4TrainingProgramSeeder`, `app:ensure-development-baseline` or `db:seed` never overwrites or retires content of a program that already exists | 1.1 |
| CUR-16 | A failed save shows its errors beside the form that sent it, keeps the input and saves nothing. A week, activity, field or checklist item of another program, week or activity gives 404 | 2.1 to 2.7, 3.2 to 3.4 |

## Current state

- **Data.** `Program` → `TrainingWeek` (`week_number`, `title`, `description`, `position`, `is_active`; unique (`program_id`, `week_number`) and (`program_id`, `position`)) → `Activity` (`title`, `description`, `objectives`, `instructions`, `configuration` json with `scoring_dimensions`, `position`, `requires_coach_review`, `is_active`; **unique (`training_week_id`, `position`)**) → `ActivityRequirement` rows of `requirement_type` `submission_field`. Each field row keeps `configuration` `{key, label, type, required, help}` and mirrors it in `title` / `description` / `is_required`. A retired field has `configuration.deprecated = true` and `is_required = false`.
- A week's **checklist** is its active `TrainingGate` (`title`, `description`, `position`, `is_active`) with `GateCriterion` items (`criterion_type`, `title`, `description`, `is_required`, `position`, `configuration` json; index (`training_gate_id`, `position`), not unique). Mentor ticks are `gate_criterion_evaluations` keyed by `gate_criterion_id`. Participant answers are `submission_versions.field_values` keyed by field key. Scores are `activity_scores` (via `version()` → `SubmissionVersion::submission()` → `activity_id`) with `activity_score_dimensions` keyed `D1`..`D9` (`string(2)`).
- **Optional checklist items are invisible.** Every checklist reader filters `is_required = true`: `ParticipantGatePageService::criterionRows()`, `CoachGateReviewPageService`, `StoreGateReviewRequest::requiredCriteria()`, `ParticipantDashboardService::missingChecklistItems()`, `CoachGateOverviewService` (`criteria_total`) and `Program::weekNumbersWithoutChecklist()`. Two readers do not filter: the weekly-video rule (`GateCriterion::scopeWeeklyVideo()` → `TrainingGate::scopeAsksForWeeklyVideo()` → `TrainingWeek::asksForWeeklyVideo()` and `GateReadinessService`), and `CoachGateOverviewService`'s `criteria_met`, which counts every Met evaluation. So today `is_required = false` already means "retired", which is how the seeder retires items.
- **Field types** in use are `textarea`, `text`, `url`, `github_url` and `select` (with `options`). They are read by `StoreSubmissionDraftRequest`, `SubmissionWorkflowService`, `ActivitySubmissionPageService::fieldControl()` and `FieldLimits::forType()`. Activity text is rendered as plain text (`activities/show`, `weeks/show`; instructions use `whitespace-pre-line`).
- **Only `database/seeders/L4TrainingProgramSeeder.php` writes this content.** It upserts weeks by number, activities by position, fields by key and checklist items by position. Retired items are made optional or deactivated. It is run by `app:ensure-development-baseline`, `DatabaseSeeder`, `docs/AWS_DEPLOYMENT.md` ("After: seed the program") and about 20 test files. A re-run overwrites any admin edit and re-keys ticks when an item is removed from the middle of a list. Item 13.2 of `docs/plans/developer-program-and-training-sessions.md` (`[~]`, week 1 done) still delivers the remaining curriculum through that seeder.
- **Sessions share the content.** `TrainingSession::weeks()` queries the program's active weeks `1..weeks_count`, so a week edit shows in every session of the program, past and present.
- **Admin surface to follow.** The admin group in `routes/web.php` (`role:admin`, prefix `admin`, names `admin.`). Pages follow `AdminTrainingSessionController` + `AdminTrainingSessionPageService` (678 lines, so it gets nothing new beyond one URL) + `resources/views/admin/sessions/show.blade.php` (tabs through `x-ui.tab-list` / `x-ui.tab-panel`, redirects to `#tab` anchors). Inline CRUD follows `AdminTrainingSessionResourceController`, `StoreTrainingSessionResourceRequest` (`#[ErrorBag]`, `getRedirectUrl()`) and `UpdateTrainingSessionResourceRequest` (a per-row bag through `errorBagFor()` + `failedValidation()`). Also reuse `TrainingSessionResourceService` (transaction + audit), `TrainingSessionPolicy`, `AdminAuditService::record()`, the form classes at the top of `admin/sessions/form.blade.php`, and the admin sidebar group in `SidebarNavigationService` (lines ~96-101). Test style: `tests/Feature/AdminTrainingSessionResourceTest.php`.
- **Overlaps.** `docs/plans/repository-ai-check.md` 1.2 reads `activities.configuration.expected_files` and `gate_criteria.configuration.expected_files`, and its 1.3 adds them through the seeder (see Q2). `docs/CODE_QUALITY_PLAN.md` has no unchecked item on the files this plan touches.
- **Open decisions in the main plan that this plan touches:**
  - "Checklist items are matched by position": resolved by 1.1, because the seeder no longer updates existing content and admin edits address items by id.
  - "An optional weekly video item still asks for the video": resolved by 3.1.
  - "Nothing checks an activity's scoring dimensions": resolved for admin edits by 2.7. The seeder-side check stays out of scope, because the seeder now only creates.
  - "Changing an activity's dimensions leaves old dimension rows on rescoring": avoided for admin edits, because 2.7 locks the dimensions once a score exists. `ScoringController::store()` is unchanged (out of scope).
  - Not touched: "Gate-level video surfaces still read inactive Gates", "Session weeks skip inactive program weeks" and "`GateCriterionFactory` defaults to the legacy type".

## Design

### Retiring instead of deleting

| Thing | "Remove" does | Restore | Why |
|---|---|---|---|
| Activity | `is_active = false` (Deactivate) | Reactivate (`is_active = true`, same position) | Submissions cascade on delete |
| Submission field | `is_required = false`, `configuration.required = false`, `configuration.deprecated = true` (as the seeder does) | Clears `deprecated` and keeps it optional | Answers are keyed by field key; readers already skip `deprecated` |
| Checklist item never reviewed (no `gate_criterion_evaluations` row) | Deleted | Not applicable | Nothing refers to it |
| Checklist item that was reviewed | `is_required = false` (retired) | `is_required = true`, moved to the end of the list | Ticks are keyed by item id and stay with it. Every reader already hides `is_required = false` items (Q1) |

For checklist items, `is_required` therefore means "on the checklist". New code names this with `GateCriterion::scopeOnChecklist()` (`where('is_required', true)`) and `GateCriterion::isOnChecklist()`. Existing readers already filter the same column and are not rewritten.

### Routes (inside the `admin` group, in one `Route::scopeBindings()` group)

| Method | URI (under `/admin/programs`) | Name (`admin.` +) | Controller@action |
|---|---|---|---|
| GET | `/` | `programs.index` | `AdminCurriculumController@index` |
| GET | `/{program}` | `programs.show` | `AdminCurriculumController@show` |
| GET | `/{program}/weeks/{week}` | `programs.weeks.show` | `AdminCurriculumController@week` |
| PUT | `/{program}/weeks/{week}` | `programs.weeks.update` | `AdminTrainingWeekController@update` |
| POST | `/{program}/weeks/{week}/activities` | `programs.activities.store` | `AdminActivityController@store` |
| GET | `/{program}/weeks/{week}/activities/{activity}/edit` | `programs.activities.edit` | `AdminActivityController@edit` |
| PUT | `…/activities/{activity}` | `programs.activities.update` | `AdminActivityController@update` |
| PATCH | `…/activities/{activity}/move` | `programs.activities.move` | `AdminActivityController@move` |
| PATCH | `…/activities/{activity}/deactivate`, `/reactivate` | `programs.activities.deactivate`, `.reactivate` | `AdminActivityController@deactivate`, `@reactivate` |
| POST | `…/activities/{activity}/fields` | `programs.fields.store` | `AdminActivityFieldController@store` |
| PUT, PATCH, DELETE, PATCH | `…/fields/{requirement}`, `/move`, (delete), `/restore` | `programs.fields.update`, `.move`, `.destroy`, `.restore` | `AdminActivityFieldController` |
| PUT | `…/activities/{activity}/scoring` | `programs.scoring.update` | `AdminActivityScoringController@update` |
| PUT | `/{program}/weeks/{week}/checklist` | `programs.checklist.update` | `AdminChecklistController@update` |
| POST | `/{program}/weeks/{week}/checklist/items` | `programs.checklist-items.store` | `AdminChecklistItemController@store` |
| PUT, PATCH, DELETE, PATCH | `…/checklist/items/{checklistItem}`, `/move`, (delete), `/restore` | `programs.checklist-items.update`, `.move`, `.destroy`, `.restore` | `AdminChecklistItemController` |

How each scoped binding is checked:

- `{week}` is checked through `Program::weeks()`, `{activity}` through `TrainingWeek::activities()`, and `{requirement}` through `Activity::requirements()`.
- `{checklistItem}` is checked through a new `TrainingWeek::checklistItems()` (`hasManyThrough(GateCriterion::class, TrainingGate::class)`). Laravel guesses the relationship as the camel-case plural of the parameter name.
- Form Requests also return 404 for a `requirement` that is not a `submission_field`, and for a checklist item whose Gate is not the week's checklist.
- Moves take `direction` = `up` | `down`.

### Authorization

- New `App\Policies\ProgramPolicy` with `viewAny`, `view` and `update`, each allowed only for `hasAnyRole(UserRole::Admin)`. Laravel discovers it by name, as it does `TrainingSessionPolicy` (no `Gate::policy()` calls in `app/`).
- Page actions call `Gate::authorize('view', $program)`. Every Form Request's `authorize()` returns `can('update', $program)`, where the program comes from the route. The routes also sit behind `role:admin`.

### Services

- `CurriculumPageService`:
  - `indexData()`: programs with `withCount(['weeks', 'trainingSessions'])`.
  - `programData(Program)`: weeks with `withCount` of active activities and on-checklist items, plus the sessions.
  - `weekData(Program, TrainingWeek)`: tabs **Details**, **Activities** and **Checklist**, with form URLs and error-bag names.
  - `activityData(Program, TrainingWeek, Activity)`: tabs **Details**, **Submission fields** and **Scoring**.
  - `sessionsNotice(Program)`: `{sessions: list<{title, dates, status_label, status_tone, url}>, has_active: bool, message: string}`.
  - Views get prepared arrays only.
- `CurriculumOrderService::move(Model $item, Builder $siblings, string $direction): bool` swaps `position` with the neighbour in `$siblings`, ordered by position then id. Because `activities` has a unique (`training_week_id`, `position`) index, it parks the moving row on `max(position) + 1` first. Positions are `smallint unsigned`, so no negative values. It returns `false` at either end, and then nothing is saved or audited.
- `CurriculumWeekService`: `updateWeek`, `addActivity`, `updateActivity`, `moveActivity`, `deactivateActivity`, `reactivateActivity`, `updateScoringDimensions`.
- `ActivityFieldService`: `add`, `update`, `move`, `retire`, `restore`, `keyFor(Activity, string $label)`.
- `ChecklistService`: `updateChecklist`, `addItem`, `updateItem`, `moveItem`, `removeItem` (returns whether the item was deleted or retired), `restoreItem`.
- Audit actions:
  - `curriculum_week_updated`
  - `curriculum_activity_added`, `_updated`, `_moved`, `_deactivated`, `_reactivated`
  - `curriculum_field_added`, `_updated`, `_moved`, `_removed`, `_restored`
  - `curriculum_scoring_updated`
  - `curriculum_checklist_updated`
  - `curriculum_checklist_item_added`, `_updated`, `_moved`, `_removed` (metadata `deleted: bool`), `_restored`
  - Metadata always has `program_id` and `training_week_id`, plus `activity_id`, `activity_requirement_id` or `gate_criterion_id` where relevant, and `changes: array<string, array{from: mixed, to: mixed}>` for updates.

### Screens

- **Sidebar:** in the Admin group, after "Training Sessions", add `['label' => 'Curriculum', 'route' => 'admin.programs.index', 'active' => ['admin.programs.*']]`.
- **Programs index** (`admin/curriculum/index`): a table with name, Active / Inactive badge, number of weeks, number of sessions, and an "Open" link. Empty state: "No programs yet."
- **Program page** (`admin/curriculum/program`):
  - The program name and description (read-only).
  - The sessions notice (see below).
  - The weeks table: Week N, title, Active / Inactive badge, active activities, checklist items, and an "Edit week" link.
- **Week page** (`admin/curriculum/week`):
  - Header: eyebrow "ADMIN · CURRICULUM", the title "Week N: {title}", a back link to the program, and the sessions notice.
  - **Details** tab: the title and description form.
  - **Activities** tab: an ordered table of activities with an Active / Inactive badge, number of fields, Edit, Move up / Move down, and Deactivate / Reactivate. Below it, the "Add activity" form.
  - **Checklist** tab: the checklist title and description form, then one row per on-checklist item with its type select, title, description, Save, Move up / Move down and Remove. Then the "Add item" form, then "Removed items" with Restore.
  - A week without an active Gate shows "This week has no checklist." with no forms, because creating Gates is out of scope.
- **Activity page** (`admin/curriculum/activity`):
  - **Details** tab: title, description, objectives and instructions.
  - **Submission fields** tab: per-field forms, the add form, and removed fields with Restore.
  - **Scoring** tab: the dimension rows, or a read-only table with "Scored submissions exist, so the dimensions can no longer change."
  - "Back to week" link.
- **Sessions notice** (on the program, week and activity pages):
  - With sessions: an `x-ui.alert`, tone `warning` when a session is active and `info` otherwise. It reads: "Used by {n} training sessions ({active} active, {upcoming} upcoming, {completed} completed). Changes show to their participants and mentors right away, including in completed sessions." Each session is listed and links to `admin.sessions.show`.
  - Without sessions: "No training session uses this program yet."
- **Session dashboard:** the Activities tab gets an "Edit curriculum" `x-ui.button` (secondary) linking to `admin.programs.show` for the session's program, and the line "Changes apply to every session of {program}." `AdminTrainingSessionPageService::showData()` gains `curriculumUrl` only.
- **Pattern for every form:** errors appear beside the form that sent it, through `#[ErrorBag]` or a per-row `errorBagFor()`. A failed save returns to the right tab anchor with `old()` input. Success redirects to the tab with a `status` flash, e.g. `Saved "Week 2".` or `Removed "Session logs committed for this week".` Labels, focus states and `aria-label`s on row buttons follow `admin/sessions/show.blade.php`.

### What changes after an edit (all sessions of the program, immediately)

| Edit | Participants see | Mentors see | Existing records |
|---|---|---|---|
| Week title or description | Session page week tab, week page, dashboard | Week labels on their pages | No change |
| Activity text | Week and activity pages | Review and scoring pages | Submissions untouched |
| Activity added | It appears last in the week. The week is no longer "ready" until it is handed in. The hourly `notifications:sync-participants` adds reminders | Review queue once handed in | Completed checklist reviews stay Completed |
| Activity deactivated | It disappears from the week page and from readiness | No longer counted on the session dashboard Activities tab | Submissions and scores kept |
| Field label, help, type or required | Submission form at once | Answer labels | Values kept by key. A new type or "required" applies only from the next save or submit |
| Field removed | It leaves the form | Past answers still listed (`SubmissionAnswerPresenter`, workbook "historical" fields) | Values kept |
| Scoring dimensions | Rubric on the activity page | Scoring form and AI suggestion prompt | Only possible before the first score |
| Checklist item added | "Awaiting Review" on their checklist | A new box on the review page | A Completed review stays Completed until the mentor reviews again |
| Checklist item edited | New title and description | Same | Ticks stay with the item |
| Checklist item removed | It leaves their checklist and "missing items" | Review page and board count | Ticks kept. An Incomplete review stays Incomplete (and keeps locking per 11.1) until the mentor saves a new review |
| Weekly-video item removed (after 3.1) | Week stops asking for the video | Video box disappears | Chosen videos kept |

## Assumptions

- A1. Only admins edit curriculum. Coaches and participants cannot. (The request says "admin".)
- A2. Edits are program-wide and apply to every session, including completed ones. There are no per-session copies or versions, because `TrainingSession::weeks()` reads the program directly and versioning would need a new data model (follow-up).
- A3. **The seeder only creates (CUR-15).** `L4TrainingProgramSeeder` seeds its full definition only when no program named `L4 Accelerator Training` exists. Otherwise it changes nothing and its `run()` returns early, writing one line through `$this->command?->info()`. This is the simplest way the seeder and admin editing can coexist without a new "edited" flag, and it ends position matching on existing data. Consequences:
  - The rest of 13.2 (weeks 2 to 4, the real scoring dimensions) is entered through these admin pages on each database that already has the program.
  - Updating the seeder's definition only changes what new databases (local, tests) start with.
  - Assumes no shared database holds an outdated copy of the program. If one does, run the current seeder there once before deploying 1.1.
- A4. **Checklist items have no "required" toggle.** Optional items are shown nowhere today (Current state), so the item form offers Remove and Restore instead. Remove deletes an item that was never reviewed and retires one that was, so ticks never move (Q1).
- A5. A checklist has at most one session-logs item and at most one weekly-video item among its on-checklist items. Readers take the first weekly-video item, and `docs/plans/repository-ai-check.md` reads "the week's session-logs item".
- A6. A checklist keeps at least one item, and a week keeps at least one active activity. `Program::weekNumbersWithoutChecklist()` requires a required item per active week, and readiness with no activities would be met at once.
- A7. **A new activity** gets:
  - `requires_coach_review = true`.
  - The default rubric `Activity::DEFAULT_SCORING_DIMENSIONS` (D1 Deliverable 40, D2 Code quality 30, D3 Testing 20, D4 Reflection 10). These are the seeder's placeholders, moved to the model and reused by the seeder.
  - One optional "Notes" `textarea` field (key `notes`, help "Anything your mentor should know about this deliverable."), as every seeded activity has.
  - The last position in the week, counting inactive activities.
- A8. **Field keys never change.** A new field's key is `Str::snake(Str::ascii($label))`, limited to `[a-z0-9_]` and 40 characters (falling back to `field`). It is made unique within the activity, counting removed fields, with `_2`, `_3`, and so on.
- A9. **Field types offered:** `textarea` (Long text), `text` (Short text), `url` (Link) and `github_url` (GitHub link). An existing `select` field keeps its type and `options` and shows the type read-only. No L4 field is a `select`.
- A10. **Scoring dimensions** can be edited until the first `activity_scores` row exists for any version of the activity. The form allows 1 to 9 rows, each with a label (≤ 60 characters) and a maximum (integer 1 to 100), and the maxima must sum to 100. Keys are reassigned `D1`..`Dn` in row order on save, which is safe because no score refers to them yet. A stale AI suggestion for an unscored version is left as it is; it is regenerated on request.
- A11. Activity and week text stays plain text, because that is how the pages render it. There is no Markdown preview.
- A12. Week numbers, week positions, week activation, program fields, `requires_coach_review`, evidence requirements (`evidence_requirements`) and `expected_files` are not edited here. Week numbering stays contiguous because nothing adds, removes or renumbers weeks.
- A13. Reordering uses Move up / Move down buttons, which are plain forms that need no JS. There is no drag and drop.
- A14. While a session is active, edits are allowed and only warned about, not blocked (Q3).
- A15. Restoring a retired checklist item puts it at the end of the list, so other items keep their positions. Restoring a field keeps its old position and leaves it optional.

## Open questions

- **Q1 (affects 3.2–3.4; the default A4 is planned):** Do admins need *optional* checklist items, meaning items shown to the mentor and participant that do not block "Completed"?
  - Option A: no. Remove and Restore as planned, with no schema change.
  - Option B: yes. Add `gate_criteria.is_active` for retirement, make `is_required` a real toggle, and teach the six readers to show optional items with an "Optional" badge. That needs a migration and one more batch before 3.2.
  - Recommendation: A, because no spec asks for optional items and every current screen hides them.
  - If B is chosen before 3.2 starts, revise 3.1–3.4 first.
- **Q2 (coordination with `docs/plans/repository-ai-check.md` 1.3; does not block this plan):** That batch adds `expected_files` to existing databases by re-running the seeder, which 1.1 here stops.
  - Option A: run repository-check 1.3 before 1.1 of this plan.
  - Option B: after 1.1, expected files reach new databases only, and existing ones get them through a follow-up "Expected files" section on the activity and checklist-item forms.
  - Option C: a one-off data migration in repository-check 1.3.
  - Recommendation: B. Production is not seeded yet (the first session is 9 Nov 2026), and the curriculum editor is where expected files belong long-term. Record the choice in both plans.
- **Q3 (non-blocking; default A14):** Should some edits be blocked while a session is active? For example, removing a checklist item of a week that is already open, or adding an activity to a week whose reviews are done.
  - Recommendation: no. Warn only, so admins can fix mistakes during a session. The "What changes after an edit" table is the help text.

## Phase 1 — Seeder and the Curriculum area

- [x] **1.1 Make the L4 seeder create the program only when it does not exist** (CUR-15)
  - **Do:**
    - In `L4TrainingProgramSeeder::run()`, return before the transaction when `Program::query()->where('name', self::PROGRAM_NAME)->exists()`, writing "L4 Accelerator Training already exists; edit it on the admin Curriculum pages." through `$this->command?->info()`.
    - Simplify the seeding methods to create rather than update. Keep the empty-checklist `RuntimeException`, and remove `deactivateUndefinedRecords()` and the retire branches, which can no longer run.
    - Move the D1–D4 rubric into `Activity::DEFAULT_SCORING_DIMENSIONS` and use it in the seeder (A7).
    - Update the class PHPDoc.
    - In `docs/AWS_DEPLOYMENT.md` "After: seed the program", say that the program seeder leaves an existing program alone so admin edits stay.
    - In `docs/plans/developer-program-and-training-sessions.md`:
      - Add to 13.2's note that the remaining curriculum is entered through the Curriculum pages (this plan) on databases that already have the program.
      - Mark "Checklist items are matched by position" resolved by this batch.
  - **Acceptance:**
    - On an empty database the seeder creates the same program as today: 4 weeks, 13 activities, 4 gates, 19 items and 27 fields.
    - After an admin-style change (rename week 1, retire a field, edit an item title) a second run leaves every row and id unchanged.
    - `app:ensure-development-baseline` still succeeds twice in a row.
  - **Tests:** Update `tests/Feature/L4TrainingProgramSeederTest.php`.
    - Replace the update-in-place cases (`test_running_twice_updates_every_record_in_place_without_duplicates`, `test_running_again_renames_the_old_weekly_video_item_in_place`, `test_running_without_a_week_…`, `…_an_activity_…`, `…_a_field_…`) with "a second run changes nothing, even after edits" and "a second run leaves a submission and a review untouched".
    - Keep the creation, empty-checklist and helper cases. The empty-checklist case keeps its anonymous-subclass definition hook.
    - Run the suite to find other tests that relied on re-seeding to update content (grep `seed(L4TrainingProgramSeeder::class)` callers that seed twice).
- [ ] **1.2 Curriculum area: programs list, program page, sidebar and session link** (CUR-1, CUR-13)
  - **Do:**
    - Add `ProgramPolicy` and the routes `admin.programs.index` and `admin.programs.show` (`AdminCurriculumController@index` / `@show`).
    - Add `CurriculumPageService::indexData()`, `programData()` and `sessionsNotice()`, and the views `admin/curriculum/index` and `admin/curriculum/program` built from `x-ui.*` components (Design › Screens).
    - Add the sidebar link "Curriculum".
    - Add the session dashboard Activities tab's "Edit curriculum" button and line (`curriculumUrl` in `AdminTrainingSessionPageService::showData()`).
    - The program page's week rows link to `admin.programs.weeks.show` once 1.3 adds it. Until then, show the week rows without a link.
  - **Acceptance:**
    - An admin sees the list and the program page with weeks in number order, the counts of active activities and on-checklist items, and the sessions with status badges.
    - The notice is `warning` when a session is active, and reads "No training session uses this program yet." when there are none.
    - Coaches and participants get 403, and guests are redirected to login.
    - The sidebar marks "Curriculum" active on `admin.programs.*`.
    - The session dashboard's Activities tab links to its program.
    - The page runs a fixed number of queries whatever the number of weeks (`withCount`, no per-row queries).
  - **Tests:** New `tests/Feature/AdminCurriculumPagesTest.php`:
    - role access (admin 200, coach 403, participant 403, guest redirect)
    - list and program contents
    - notice tones and empty state
    - an inactive week shows "Inactive"
    - Extend `AdminTrainingSessionDashboardTest` for the link and `tests/Feature/AppLayoutTest.php` (sidebar) for the new link.
- [ ] **1.3 Week page (read-only)** (CUR-1, CUR-13)
  - **Do:**
    - Add the route `admin.programs.weeks.show` with scoped bindings, and `AdminCurriculumController@week`.
    - Add `TrainingWeek::checklist()` (`hasOne(TrainingGate::class)->where('is_active', true)->orderBy('position')`), `TrainingWeek::checklistItems()` (hasManyThrough) and `GateCriterion::scopeOnChecklist()` / `isOnChecklist()`.
    - Add `CurriculumPageService::weekData()` and the view `admin/curriculum/week` with the three tabs as read-only lists: details; activities in position order with Active / Inactive badges; checklist title, description and on-checklist items with a type label; retired items under "Removed items".
    - Show "This week has no checklist." when the week has no active Gate.
    - Link the program page's week rows here.
  - **Acceptance:**
    - The page shows exactly the week's data.
    - A week of another program gives 404.
    - Item types show as "Deliverable", "Session logs" and "Weekly video". Put the labels in a `GateCriterion::TYPE_LABELS` constant next to the type constants.
    - Retired items appear only under "Removed items".
    - Eager loading keeps the query count flat.
  - **Tests:** Extend `AdminCurriculumPagesTest`: contents, the 404 for a foreign week, the no-checklist state, and that retired items are listed apart.
  - **Depends on:** 1.2.

## Phase 2 — Weekly details

- [ ] **2.1 Edit a week's title and description** (CUR-2, CUR-14, CUR-16)
  - **Do:**
    - Add `UpdateTrainingWeekRequest` (`#[ErrorBag('week')]`, title required string max 255, description nullable string max 5000, redirect to `#details`).
    - Add `AdminTrainingWeekController@update` and `CurriculumWeekService::updateWeek()`, which writes `curriculum_week_updated` with `changes`.
    - Make the Details tab editable.
  - **Acceptance:**
    - The participant week page and session page show the new title.
    - A blank title shows "The title field is required." under the field, keeps the typed description and saves nothing.
    - An unchanged save writes no audit row.
    - `week_number`, `position` and `is_active` cannot be changed through extra input.
    - Non-admins get 403.
  - **Tests:** New `tests/Feature/AdminCurriculumWeekTest.php` covering:
    - the update and audit row
    - the participant week page shows the new title
    - validation in the `week` bag
    - an unchanged save
    - extra fields ignored
    - 403 for coaches and participants, and 404 for a week of another program
  - **Depends on:** 1.3.
- [ ] **2.2 Activity page and editing an activity's text** (CUR-1, CUR-4, CUR-14, CUR-16)
  - **Do:**
    - Add the routes `admin.programs.activities.edit` / `.update`, `AdminActivityController@edit` / `@update`, `UpdateActivityRequest` (`#[ErrorBag('activity')]`; title required max 255; description and objectives nullable max 5000; instructions nullable max 20000) and `CurriculumWeekService::updateActivity()` (`curriculum_activity_updated`).
    - Add `CurriculumPageService::activityData()` and the view `admin/curriculum/activity`: the Details tab is editable, and the Submission fields and Scoring tabs are read-only lists for now.
    - Add the sessions notice.
    - The week page's activity rows link to this page.
  - **Acceptance:**
    - The participant activity page shows the new objectives and instructions.
    - `configuration` (dimensions, `expected_files`) is untouched.
    - An activity of another week in the URL gives 404.
    - Validation errors appear in the `activity` bag with input kept.
  - **Tests:** New `tests/Feature/AdminCurriculumActivityTest.php`:
    - edit page contents
    - update with audit, and that `configuration` survives (seed one with an extra `expected_files` key)
    - validation
    - 404 for a mismatched week or activity
    - role access
  - **Depends on:** 1.3.
- [ ] **2.3 Add an activity** (CUR-3, CUR-14, CUR-16)
  - **Do:**
    - Add `StoreActivityRequest` (`#[ErrorBag('addActivity')]`, same rules as 2.2), `AdminActivityController@store` and `CurriculumWeekService::addActivity()`, which applies the A7 defaults in one transaction and writes `curriculum_activity_added`.
    - Add the "Add activity" form on the Activities tab.
    - Redirect to the new activity's edit page with "Added "{title}"."
  - **Acceptance:**
    - The new activity is last, active, has the default rubric and the optional Notes field.
    - It appears on the participant week page of every session of the program.
    - Failed adds show their errors on the Activities tab and create nothing.
  - **Tests:** `AdminCurriculumActivityTest`:
    - add with defaults and position after an inactive activity
    - shown on a participant's week page
    - validation (nothing created)
    - role access
  - **Depends on:** 2.2.
- [ ] **2.4 Reorder, deactivate and reactivate activities** (CUR-5, CUR-6, CUR-14, CUR-16)
  - **Do:**
    - Add `CurriculumOrderService` (Design › Services) and `MoveCurriculumItemRequest` (`direction` required in `up,down`; authorizes `update` on the route's program; reused by 2.6 and 3.3).
    - Add `AdminActivityController@move`, `@deactivate` and `@reactivate`.
    - Add `DeactivateActivityRequest`, whose `after()` rejects the week's last active activity with "A week needs at least one active activity." in the `activities` bag.
    - Add `CurriculumWeekService::moveActivity()`, `deactivateActivity()` and `reactivateActivity()` with their audit actions.
    - Add Move up / Move down / Deactivate / Reactivate buttons on the Activities tab, with `aria-label`s naming the activity and the first and last rows' disabled buttons. The Deactivate confirmation reads "Participants keep their submissions, but the activity leaves the week for every session."
  - **Acceptance:**
    - Moving swaps exactly two positions with no unique-index error on SQLite or MySQL.
    - Moving the first activity up changes nothing and writes no audit row.
    - A deactivated activity disappears from the participant week page and from readiness (`GateReadinessService`), and its submissions remain.
    - Reactivating restores it at its old position.
    - The last active activity cannot be deactivated.
  - **Tests:** `AdminCurriculumActivityTest`:
    - move up and down, and the ends
    - deactivate hides it from the participant week page and a participant who handed in every other activity becomes ready
    - reactivate
    - the last-activity guard
    - the audit rows
    - a feature case for `CurriculumOrderService` with three activities to prove the unique index holds
  - **Depends on:** 2.2.
- [ ] **2.5 Edit an activity's submission fields** (CUR-7, CUR-14, CUR-16)
  - **Do:**
    - Add `ActivityRequirement::FIELD_TYPES` (value → label, A9) and `ActivityRequirement::isSubmissionField()`.
    - Add `UpdateActivityFieldRequest`:
      - rules: label required max 255; help nullable max 1000; type in `FIELD_TYPES`, or unchanged `select` for a select field; required boolean
      - a per-field bag `errorBagFor()`
      - 404 for a non-`submission_field` requirement or a retired one
      - redirect to `#fields`
    - Add `AdminActivityFieldController@update` and `ActivityFieldService::update()`. It updates `configuration` (merging and keeping `key`, `options` and other keys) and the mirrored `title`, `description` and `is_required` columns together, and writes `curriculum_field_updated`.
    - Make the Submission fields tab editable for active fields.
  - **Acceptance:**
    - The participant submission form shows the new label, help and control at once.
    - A saved draft keeps its value under the same key.
    - Changing `textarea` to `github_url` makes the next save of a non-GitHub value fail with the existing `GitHubUrl` message.
    - Making a field required blocks the next submit without it.
    - A requirement of another activity gives 404.
  - **Tests:** New `tests/Feature/AdminCurriculumFieldTest.php` covering:
    - label, help and required changes shown on `training.submission.edit`
    - draft value kept after a label change
    - a type change enforced on the next draft save
    - `configuration` extra keys kept
    - validation in the field's bag
    - 404 for a foreign or non-field requirement
    - role access
  - **Depends on:** 2.2.
- [ ] **2.6 Add, reorder, remove and restore submission fields** (CUR-7, CUR-14, CUR-16)
  - **Do:**
    - Add `StoreActivityFieldRequest` (`#[ErrorBag('addField')]`, same rules as 2.5 without `select`) and `AdminActivityFieldController@store`, `@move`, `@destroy` (retire) and `@restore`.
    - Add `ActivityFieldService::add()` (key per A8, last position), `move()` (via `CurriculumOrderService` over the activity's active `submission_field` rows), `retire()` and `restore()`, with their audit actions.
    - Removed fields are listed under "Removed fields" with Restore.
  - **Acceptance:**
    - A new field with label "Pull request link" gets key `pull_request_link`. Adding the same label again gives `pull_request_link_2`.
    - A removed field leaves the participant form, but its stored answer still shows on the submission page and the mentor review page.
    - Restoring brings it back optional at its old position, and the old answer is in the form again.
    - Moving swaps two fields.
  - **Tests:** `AdminCurriculumFieldTest`:
    - add with key generation and uniqueness, counting retired keys
    - retire keeps the answer visible on `training.submission.show`
    - restore
    - move
    - audit rows
    - validation (nothing created)
  - **Depends on:** 2.4, 2.5.
- [ ] **2.7 Edit scoring dimensions until the activity is scored** (CUR-8, CUR-14, CUR-16)
  - **Do:**
    - Add `UpdateActivityScoringRequest` (`#[ErrorBag('scoring')]`):
      - nine `dimensions.{i}.label` / `.max` rows; blank rows are ignored
      - at least one row
      - label max 60; max integer 1 to 100
      - an `after()` check that the maxima sum to 100 ("The maxima add up to {sum}; they must add up to 100.")
      - an `after()` check that rejects the save when the activity has a score
    - Add `Activity::hasScores()`, a single `exists` query through `submissions.versions.activityScore`; check the relation names.
    - Add `AdminActivityScoringController@update` and `CurriculumWeekService::updateScoringDimensions()`. It writes `configuration.scoring_dimensions` (merged) with keys `D1`..`Dn` in row order, and writes `curriculum_scoring_updated` with before and after.
    - Scoring tab: nine rows pre-filled with the current dimensions, a running note "Maxima must add up to 100", or the locked read-only table.
    - Update the main plan's "Nothing checks an activity's scoring dimensions" and "Changing an activity's dimensions leaves old dimension rows on rescoring" entries (Current state › Open decisions).
  - **Acceptance:**
    - New dimensions appear on the mentor scoring form (`ScoringPageService`) and the participant activity page rubric.
    - A sum of 90 fails with the message and saves nothing.
    - After any `activity_scores` row exists, the tab is read-only and a forged PUT fails with "Scored submissions exist, so the dimensions can no longer change."
    - Ten rows or a label over 60 characters fail validation.
  - **Tests:** New `tests/Feature/AdminCurriculumScoringTest.php`:
    - save and re-key
    - shown on the scoring page for a submitted version
    - sum validation
    - empty set
    - locked after a score (factory score on a version of the activity)
    - an unchanged save writes no audit row
    - role access
  - **Depends on:** 2.2.

## Phase 3 — Checklists

- [ ] **3.1 Removed checklist items stop counting** (CUR-12)
  - **Do:**
    - `GateCriterion::scopeWeeklyVideo()` adds `where('is_required', true)`, which carries through `TrainingGate::scopeAsksForWeeklyVideo()`, `TrainingWeek::asksForWeeklyVideo()` and `GateReadinessService`. `GateCriterion::isWeeklyVideo()` stays type-only; its callers already work on required items. Check that `CoachGateReviewPageService` line ~51 filters `$requiredCriteria`.
    - `CoachGateOverviewService` `criteria_met` counts only Met evaluations whose item is on the checklist (filter by the Gate's on-checklist criterion ids, already loaded).
    - Update the main plan's "An optional weekly video item still asks for the video" entry.
  - **Acceptance:**
    - A week whose only `weekly_video` item has `is_required = false` no longer shows "Choose Weekly Video" on the week page, dashboard or Gate page, and readiness does not require a video.
    - The Gate board shows "4 / 4 criteria met", not "5 / 4", for a review that ticked a since-retired item.
  - **Tests:**
    - Add cases to `tests/Feature/WeeklyVideoRecordingPageTest.php` (retired video item → 404 on the choose page) and `tests/Feature/GateReadinessTest.php` (no video needed).
    - Add a case to `tests/Feature/CoachGateBoardPageTest.php` for the count.
    - Fix any existing test that relied on an optional video item asking for the video.
- [ ] **3.2 Edit the checklist and its items** (CUR-9, CUR-10, CUR-14, CUR-16)
  - **Do:**
    - Add `UpdateChecklistRequest` (`#[ErrorBag('checklist')]`; title required max 255; description nullable max 2000; 404 when the week has no checklist), `AdminChecklistController@update` and `ChecklistService::updateChecklist()` (`curriculum_checklist_updated`).
    - Add `UpdateChecklistItemRequest`:
      - type in `GateCriterion::TYPE_*`; title required max 255; description nullable max 2000
      - per-item `errorBagFor()`
      - 404 for a retired item or one outside the week's checklist
      - an `after()` check for A5: "This checklist already has a session logs item." and "… a weekly video item."
    - Add `AdminChecklistItemController@update` and `ChecklistService::updateItem()` (merges `configuration`; `curriculum_checklist_item_updated`).
    - Make the Checklist tab's forms editable.
  - **Acceptance:**
    - The participant Gate page, the week page summary and the mentor review page show the new titles.
    - An existing tick on the item still shows as Met.
    - Changing a deliverable item to `weekly_video` while another weekly-video item exists fails with the message and saves nothing.
    - Changing the only weekly-video item to `deliverable` makes the week stop asking for the video.
  - **Tests:** New `tests/Feature/AdminCurriculumChecklistTest.php` covering:
    - checklist update and audit
    - item edit shown on `training.gates.show` with the tick kept
    - type guard for both types
    - the video-question effect of a type change
    - 404 for a retired item, an item of another week, or a week without a checklist
    - validation bags
    - role access
  - **Depends on:** 1.3, 3.1.
- [ ] **3.3 Add and reorder checklist items** (CUR-10, CUR-14, CUR-16)
  - **Do:**
    - Add `StoreChecklistItemRequest` (`#[ErrorBag('addChecklistItem')]`, same rules and A5 check as 3.2) and `AdminChecklistItemController@store` / `@move` (`MoveCurriculumItemRequest`).
    - Add `ChecklistService::addItem()` (last position, `is_required = true`, `configuration` null) and `moveItem()` (via `CurriculumOrderService` over on-checklist items).
    - Add the "Add item" form and the Move buttons.
  - **Acceptance:**
    - A new item appears last on the participant checklist as "Awaiting Review" and on the mentor review page as an unticked box.
    - An existing Completed review stays Completed.
    - Saving a new review without ticking it gives "Incomplete".
    - Moving changes the order on the participant Gate page, and ticks stay with their items.
  - **Tests:** `AdminCurriculumChecklistTest`:
    - add shown to the participant and mentor
    - a Completed review unchanged
    - move keeps ticks (assert `gate_criterion_evaluations` by id)
    - the A5 guard on add
    - audit
  - **Depends on:** 2.4, 3.2.
- [ ] **3.4 Remove and restore checklist items** (CUR-11, CUR-14, CUR-16)
  - **Do:**
    - Add `RemoveChecklistItemRequest`, whose `after()` rejects removing the last on-checklist item with "A checklist needs at least one item." in the `checklist` bag.
    - Add `RestoreChecklistItemRequest` (A5 check on restore).
    - Add `AdminChecklistItemController@destroy` / `@restore`, and `ChecklistService::removeItem()` (delete when it has no `evaluations`, otherwise `is_required = false`) and `restoreItem()` (`is_required = true`, position `max + 1`).
    - Add the Remove button. Its confirmation reads "Mentors' earlier ticks are kept; the item leaves the checklist of every session." Add the "Removed items" list with Restore.
    - Flash "Removed …" or "Deleted …" to match what happened.
  - **Acceptance:**
    - A never-reviewed item is deleted.
    - A reviewed item is retired: it leaves the participant and mentor checklists and the participant dashboard's missing items, and its `gate_criterion_evaluations` rows are unchanged. No other item's tick changes.
    - Restoring puts it back last with its old ticks showing.
    - The last item cannot be removed.
    - `Program::weekNumbersWithoutChecklist()` stays empty after any allowed removal.
  - **Tests:** `AdminCurriculumChecklistTest`:
    - delete vs retire
    - other ticks unchanged (by id)
    - hidden from the participant Gate page and dashboard missing items
    - restore position and ticks
    - the last-item guard
    - restore blocked by the A5 guard
    - audit rows with `deleted`
  - **Depends on:** 3.3.

## Out of scope / follow-ups

- Creating, deleting, renumbering, activating or deactivating programs and weeks. Editing program name and description (the main plan's "The dashboard's training overview is static copy" entry).
- Creating a checklist (Gate) for a week that has none, and managing several Gates per week.
- Editing `expected_files` on activities and checklist items (Q2 option B), evidence requirements, `requires_coach_review`, and `select` field options.
- Per-session curriculum copies or content versioning, and a history view of the audit log.
- Optional checklist items shown with a badge (Q1 option B).
- A seeder-side validation of scoring dimensions, and deleting leftover dimension rows on rescoring in `ScoringController::store()` (main plan open decisions).
- Drag-and-drop ordering and Markdown in activity text.

## Open decisions

Problems found during implementation that need a decision. Starts empty.

- **13.2's acceptance in the main plan still describes an updating seeder** (found in 1.1). `docs/plans/developer-program-and-training-sessions.md` 13.2 says "Re-running the seeder replaces placeholder content in place, deactivates removed placeholders", which 1.1 ended; its note now points to the Curriculum pages. Decide whether 13.2's **Do**/**Acceptance** are rewritten (seed new databases only; enter the rest through the Curriculum pages) when the training team delivers the curriculum, or now.
- **`ActivityFactory::withScoringDimensions()` keeps its own copy of the D1–D4 rubric** (found in 1.1). It could read `Activity::DEFAULT_SCORING_DIMENSIONS`; left as is because the factory was not in 1.1's scope.

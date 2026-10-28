# Admin Curriculum Editing Plan

Source: user request of 2026-10-27, "allow admin to modify checklists and the weekly training session details" (a week's details; the session model this plan was written against is superseded, see below). There is no spec; the **Requirements** section below is the spec of record until `docs/specs/admin-curriculum-editing.md` exists · Last updated: 2026-10-28

**Since `docs/plans/curriculum-sessions-merge.md` (2026-10-28):** the program is the curriculum people join; its participants, mentors and resources hang off it and its weeks carry their own dates. Where this plan says an edit applies to "every session of the program", read "the curriculum's participants and mentors". CUR-1 and CUR-13 are reworded (that plan's A10), the session parts of Design › Services and Screens are struck through, and the batch notes below are history.

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
- **No migrations.** This plan adds no table or column. If a batch seems to need one, stop and record it under Open decisions. (The rule is this plan's own: `docs/plans/curriculum-sessions-merge.md` added `training_weeks.starts_on` / `ends_on`, the `program_participants`, `program_mentors` and `program_resources` tables and dropped the session tables under its own rules; batches of this plan still add none.)
- After 1.1, 2.7 and 3.1, update the entries named in their **Do** in `docs/plans/developer-program-and-training-sessions.md` ("Resolved in admin-curriculum-editing X.Y: …"), keeping the original text struck through, as that plan does.

## Requirements

| ID | Requirement | Covered by |
|---|---|---|
| CUR-1 | An admin opens a **Curriculum** area from the sidebar. It lists the curricula with their status and dates, then a curriculum's page with its weeks (and, since curriculum-sessions-merge, its participants, mentors, resources, activities and schedule), then a week's details, activities and checklist, then an activity's details, fields and scoring. Coaches and participants get 403; guests are sent to login | 1.2, 1.3, 2.2 |
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
| CUR-13 | Edits apply at once to the curriculum's participants and mentors, whatever its status (unscheduled, upcoming, active or completed). The program, week and activity pages show the curriculum's status and dates and a warning while it is active. The Activities tab sits on the curriculum's own page (curriculum-sessions-merge 2.1) | 1.2, 1.3 |
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
- **Admin surface to follow.** The admin group in `routes/web.php` (`role:admin`, prefix `admin`, names `admin.`). Pages follow `AdminTrainingSessionController` + `AdminTrainingSessionPageService` (680 lines after 1.2, so it gets nothing new beyond the `curriculumUrl` 1.2 added) + `resources/views/admin/sessions/show.blade.php` (tabs through `x-ui.tab-list` / `x-ui.tab-panel`, redirects to `#tab` anchors). Inline CRUD follows `AdminTrainingSessionResourceController`, `StoreTrainingSessionResourceRequest` (`#[ErrorBag]`, `getRedirectUrl()`) and `UpdateTrainingSessionResourceRequest` (a per-row bag through `errorBagFor()` + `failedValidation()`). Also reuse `TrainingSessionResourceService` (transaction + audit), `TrainingSessionPolicy`, `AdminAuditService::record()`, the form classes at the top of `admin/sessions/form.blade.php`, and the admin sidebar group in `SidebarNavigationService` (lines ~95-103). Test style: `tests/Feature/AdminTrainingSessionResourceTest.php`.
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
  - `indexData()`: programs with ~~`withCount(['weeks', 'trainingSessions'])`~~ `withCount(['weeks', 'participants'])` and `withScheduleBounds()` (superseded by curriculum-sessions-merge 1.3 / 2.1).
  - `programData(Program)`: weeks with `withCount` of active activities and on-checklist items~~, plus the sessions~~, plus the status, dates and Schedule tab (superseded by curriculum-sessions-merge 1.3 / 2.1).
  - `weekData(Program, TrainingWeek)`: tabs **Details**, **Activities** and **Checklist**, with form URLs and error-bag names.
  - `activityData(Program, TrainingWeek, Activity)`: tabs **Details**, **Submission fields** and **Scoring**.
  - ~~`sessionsNotice(Program)`: `{sessions: list<{title, dates, status_label, status_tone, url}>, has_active: bool, tone: string, message: string}` (`tone` is the `x-ui.alert` tone, `warning` or `info`, so the view holds no logic).~~ **Superseded by curriculum-sessions-merge 2.1:** a schedule notice `{status, tone, message}` from the curriculum's status, rendered by `admin/curriculum/schedule-notice`.
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

- **Sidebar:** in the Admin group, ~~after "Training Sessions",~~ (superseded by curriculum-sessions-merge 2.1: that link is gone) add `['label' => 'Curriculum', 'route' => 'admin.programs.index', 'active' => ['admin.programs.*']]`.
- **Programs index** (`admin/curriculum/index`): a table with name, Active / Inactive badge, number of weeks, ~~number of sessions,~~ and an "Open" link (superseded by curriculum-sessions-merge 2.1: the columns are Curriculum, Offered, Schedule, Dates, Weeks, Participants and Actions). Empty state: "No programs yet."
- **Program page** (`admin/curriculum/program`):
  - The program name and description (read-only).
  - ~~The sessions notice (see below).~~ The schedule notice (superseded by curriculum-sessions-merge 2.1, see below).
  - The weeks table: Week N, title, Active / Inactive badge, active activities, checklist items, and an "Edit week" link.
- **Week page** (`admin/curriculum/week`):
  - Header: eyebrow "ADMIN · CURRICULUM", the title "Week N: {title}", a back link to the program, and the ~~sessions~~ schedule notice (superseded by curriculum-sessions-merge 2.1).
  - **Details** tab: the title and description form.
  - **Activities** tab: an ordered table of activities with an Active / Inactive badge, number of fields, Edit, Move up / Move down, and Deactivate / Reactivate. Below it, the "Add activity" form.
  - **Checklist** tab: the checklist title and description form, then one row per on-checklist item with its type select, title, description, Save, Move up / Move down and Remove. Then the "Add item" form, then "Removed items" with Restore.
  - A week without an active Gate shows "This week has no checklist." with no forms, because creating Gates is out of scope.
- **Activity page** (`admin/curriculum/activity`):
  - **Details** tab: title, description, objectives and instructions.
  - **Submission fields** tab: per-field forms, the add form, and removed fields with Restore.
  - **Scoring** tab: the dimension rows, or a read-only table with "Scored submissions exist, so the dimensions can no longer change."
  - "Back to week" link.
- ~~**Sessions notice** (on the program, week and activity pages):~~
  - ~~With sessions: an `x-ui.alert`, tone `warning` when a session is active and `info` otherwise. It reads: "Used by {n} training sessions ({active} active, {upcoming} upcoming, {completed} completed). Changes show to their participants and mentors right away, including in completed sessions." Each session is listed and links to `admin.sessions.show`.~~
  - ~~Without sessions: "No training session uses this program yet."~~
- ~~**Session dashboard:** the Activities tab gets an "Edit curriculum" `x-ui.button` (secondary) linking to `admin.programs.show` for the session's program, and the line "Changes apply to every session of {program}." `AdminTrainingSessionPageService::showData()` gains `curriculumUrl` only.~~
- **Superseded by curriculum-sessions-merge 2.1:** the program, week and activity pages show `admin/curriculum/schedule-notice`, an `x-ui.alert` (tone `warning` while the curriculum is Active, `info` otherwise) built from its status: "Not scheduled: participants cannot open any week yet.", "Upcoming {dates}: participants can open the first week on {date}.", "Active {dates}: changes show to its participants and mentors right away." or "Completed {dates}: changes still show to its participants and mentors when they look back." The Activities tab is part of the curriculum page, so it needs no link.
- **Pattern for every form:** errors appear beside the form that sent it, through `#[ErrorBag]` or a per-row `errorBagFor()`. A failed save returns to the right tab anchor with `old()` input. Success redirects to the tab with a `status` flash, e.g. `Saved "Week 2".` or `Removed "Session logs committed for this week".` Labels, focus states and `aria-label`s on row buttons follow `admin/sessions/show.blade.php` (deleted by curriculum-sessions-merge 2.1; its panels now live in `admin/curriculum/tabs/*`).

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
- [x] **1.2 Curriculum area: programs list, program page, sidebar and session link** (CUR-1, CUR-13) — done 2026-10-27. `TrainingWeek::checklistItems()` was added here, because the program page's on-checklist count needs it for `withCount`; 1.3 adds the rest.
  - **Do:**
    - Add `ProgramPolicy` and the routes `admin.programs.index` and `admin.programs.show` (`AdminCurriculumController@index` / `@show`).
    - Add `CurriculumPageService::indexData()`, `programData()` and `sessionsNotice()`, and the views `admin/curriculum/index` and `admin/curriculum/program` built from `x-ui.*` components (Design › Screens).
    - Add the sidebar link "Curriculum".
    - Add the session dashboard Activities tab's "Edit curriculum" button and line (`curriculumUrl` in `AdminTrainingSessionPageService::showData()`).
    - The program page's week rows link to `admin.programs.weeks.show` once 1.3 adds it. Until then, show the week rows without a link.
  - **Acceptance:**
    - An admin sees the list and the program page with weeks in number order, the counts of active activities and on-checklist items, and the sessions with status badges.
    - The notice is `warning` when a session is active, and reads "No training session uses this program yet." when there are none. (Superseded by curriculum-sessions-merge 2.1: the schedule notice.)
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
- [x] **1.3 Week page (read-only)** (CUR-1, CUR-13) — done 2026-10-27. The sessions notice moved to the include `admin/curriculum/sessions-notice` (program and week pages; 2.2 reuses it). The program page's week rows link here by the week label and an "Open" button; 2.1 renames the button "Edit week" once the page edits. `GateCriterion::typeLabel()` reads `TYPE_LABELS` and falls back to the headline of the stored type (the factory's legacy `activity_completion`). The Activities tab has no "number of fields" column yet (Design › Screens); it needs a count of active submission fields (not `deprecated`) and is left to the batch that makes the tab editable (2.3/2.4).
  - **Do:**
    - Add the route `admin.programs.weeks.show` with scoped bindings, and `AdminCurriculumController@week`.
    - Add `TrainingWeek::checklist()` (`hasOne(TrainingGate::class)->where('is_active', true)->orderBy('position')`) and `GateCriterion::scopeOnChecklist()` / `isOnChecklist()`. (`TrainingWeek::checklistItems()` (hasManyThrough) already exists from 1.2.)
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

- [x] **2.1 Edit a week's title and description** (CUR-2, CUR-14, CUR-16) — done 2026-10-27. `weekData()`'s `details` carries the form's `update_url` and `error_bag`; the week page shows the `status` flash. The program page's week-row button is now "Edit Week" (title case, like the other buttons), and `AdminCurriculumPagesTest` asserts it. A description left out of the request is kept; an empty one is saved as null.
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
- [x] **2.2 Activity page and editing an activity's text** (CUR-1, CUR-4, CUR-14, CUR-16) — done 2026-10-27. The week page's Activities tab links each title to the activity page and has an Actions column with an "Edit" button (`aria-label` "Edit {title}"); 2.4 adds its buttons there. "Back to Week" returns to the week's `#activities` tab. The Submission fields tab lists the active `submission_field` rows (label, type, Required / Optional, help, key) and, apart, the `deprecated` ones under "Removed fields"; other requirement types are not shown. Its type badge is `Str::headline()` of the stored type ("Github Url") until 2.5 adds `ActivityRequirement::FIELD_TYPES` labels and swaps it in `CurriculumPageService::submissionFieldRows()`. The Scoring tab is a read-only table with the total. Text left out of the request is kept, as in 2.1. Both `UpdateActivityRequest` and `UpdateTrainingWeekRequest` now turn `\r\n` and lone `\r` into `\n` in their multi-line inputs (`prepareForValidation()`, a private `withUnixLineEndings()` in each; see Open decisions).
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
- [x] **2.3 Add an activity** (CUR-3, CUR-14, CUR-16) — done 2026-10-27. Route `admin.programs.activities.store`. `addActivity()` locks the week row (`lockForUpdate()`) while it takes `max(position) + 1`, so two adds at once cannot hit the unique (`training_week_id`, `position`) index. The Notes field is `CurriculumWeekService::NOTES_FIELD`, a copy of the seeder's (see Open decisions); the test compares it with a seeded activity's Notes row. `curriculum_activity_added` metadata holds the ids, `title` and `position`. `weekData()` gains `addActivity` (`store_url`, `error_bag`). The line-ending helper is now the trait `App\Http\Requests\Concerns\NormalizesLineEndings`, used by `UpdateTrainingWeekRequest`, `UpdateActivityRequest` and `StoreActivityRequest`. The Activities tab's "number of fields" column (1.3's note) is still open and left to 2.4.
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
- [x] **2.4 Reorder, deactivate and reactivate activities** (CUR-5, CUR-6, CUR-14, CUR-16) — done 2026-10-27. Routes `admin.programs.activities.move`, `.deactivate` and `.reactivate` (PATCH). An activity moves among every activity of its week, active or not, so the Move buttons sit on every row and only the table's first and last rows have a disabled button; `moveActivity()` locks the week row (as `addActivity()` does) and reloads the activity before the swap. `curriculum_activity_moved` metadata holds the ids, `direction` and `changes.position`; `_deactivated` / `_reactivated` hold the ids and `changes.is_active`. Deactivating an inactive activity, reactivating an active one, or moving past either end saves and records nothing (a move at an end flashes no status). Reactivate has no input, so it authorizes with `Gate::authorize('update', $program)` in the controller, like the session dashboard's remove actions. The confirmation names the activity: "Deactivate {title}? Participants keep their submissions, but the activity leaves the week for every session." (built in `CurriculumPageService::activityRows()`). The guard's error shows as an `x-ui.alert` (error) at the top of the Activities tab. The Activities tab now has 1.3's "Fields" column: active submission fields per activity, counted in PHP from one eager-loaded query (the `deprecated` flag lives in JSON, so no `withCount`).
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
- [x] **2.5 Edit an activity's submission fields** (CUR-7, CUR-14, CUR-16) — done 2026-10-27. Route `admin.programs.fields.update` (PUT `…/activities/{activity}/fields/{requirement}`). `ActivityRequirement` gains `FIELD_TYPES`, `isSubmissionField()`, `isRemovedField()` (moved from `CurriculumPageService`, which now calls it), `fieldType()` (`textarea` when none is stored, as the submission form reads it), `fieldTypeLabel()` (FIELD_TYPES label, else the headline, so `select` shows "Select") and `fieldSettings()` (label, help, type, required from `configuration`, falling back to the mirrored columns); the Submission fields tab's badges now show these labels ("GitHub link", "Long text"), and `AdminCurriculumActivityTest`'s page assertion was updated to them. `UpdateActivityFieldRequest` returns 404 from `authorize()` (before the policy, as `StoreGateReviewRequest` does), uses `NormalizesLineEndings` for `help`, puts errors in `field{id}` and exposes `settings()`; the form always sends `required` (hidden 0 + checkbox), and help or `required` left out of a request is kept. `ActivityFieldService::update()` compares the four settings and saves nothing when none changed; it writes them into `configuration` with `array_replace` and mirrors `title`, `description` and `is_required`. `curriculum_field_updated` metadata holds the ids (with `activity_requirement_id`) and `changes` keyed `label`, `help`, `type`, `required`. Each active field is a card with its own form (Label, Type select or, for a `select` field, the read-only type with a hidden `type=select`, Help, Required checkbox, "Save Field" with `aria-label` "Save Field {label}"); success flashes `Saved "{label}".` and returns to `#fields`.
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
- [x] **2.6 Add, reorder, remove and restore submission fields** (CUR-7, CUR-14, CUR-16) — done 2026-10-27. Routes `admin.programs.fields.store` (POST `…/activities/{activity}/fields`), `.move` and `.restore` (PATCH) and `.destroy` (DELETE). `move` reuses `MoveCurriculumItemRequest`; move, destroy and restore return 404 from the controller for a requirement that is not a submission field (move also for a removed one), and destroy and restore authorize with `Gate::authorize('update', $program)` as 2.4's reactivate does. Removing a removed field or restoring a field on the form saves and records nothing; moving past either end flashes no status. `ActivityFieldService::add()` and `move()` lock the activity row (as 2.3/2.4 lock the week); a new field goes after every requirement of the activity (removed fields and other requirement types included), so a restored field's old position stays free. `keyFor()` lowercases the ASCII label before `Str::snake()` (A8), so "GitHub URL" gives `github_url` rather than `git_hub_u_r_l`, and a suffixed key is cut to fit 40 characters. `move()` swaps among the activity's fields still on the form; removed fields keep their positions. Audit metadata: `_added` holds the ids, `key`, `label`, `type`, `required` and `position`; `_moved` the ids, `direction` and `changes.position`; `_removed` the ids and `changes.removed` (plus `changes.required` when it was required); `_restored` the ids and `changes.removed`. Each field card has Move Up / Move Down (first and last disabled) and Remove (`data-confirm` "Remove {label}? Participants' answers to it are kept, but the field leaves the form for every session."), all with `aria-label`s naming the field; the "Add field" form sits below the list and each removed field has Restore. Also done here, per the coordinator's decision on 2.5's open decision: any stored type outside `FIELD_TYPES` (`ActivityRequirement::hasFixedFieldType()`) stays read-only like `select`.
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
- [x] **2.7 Edit scoring dimensions until the activity is scored** (CUR-8, CUR-14, CUR-16) — done 2026-10-27. Route `admin.programs.scoring.update` (PUT `…/activities/{activity}/scoring`). `Activity` gains `MAX_SCORING_DIMENSIONS` (9, for the `string(2)` key column), `SCORING_TOTAL` (100) and `hasScores()` (`submissions()->whereHas('versions.activityScore')->exists()`, so a draft score locks too). `UpdateActivityScoringRequest` drops rows whose label and maximum are both blank in `prepareForValidation()`, keeping the others under their row numbers so each error shows beside its row; it exposes `LOCKED_MESSAGE`, `LABEL_MAX_LENGTH` and `dimensions()`. Messages: "Add at least one dimension." (no rows), "An activity has at most 9 dimensions.", and the attributes "label" / "maximum" for row errors. The lock check runs first and returns; the sum check runs only when every row is valid. `updateScoringDimensions()` compares the new `D1`..`Dn` list with `scoringDimensions()` (keys included) and saves nothing when they match; `curriculum_scoring_updated` metadata holds the ids and `changes.scoring_dimensions` (`from`/`to` lists). `CurriculumPageService::scoringData()` gives the tab its nine rows, or `is_locked` with the read-only table and the reason (an `x-ui.alert`, info). The form shows a static "Maxima must add up to 100." (no live sum, no JS); its rows are a three-column grid (key, label, maximum) with `sr-only` labels "Dimension N label" / "Dimension N maximum", per-row errors under the row, and set-level errors (sum, lock, count) in an error alert above the rows. Success flashes `Saved the scoring dimensions of "{title}".` and returns to `#scoring`. `AdminCurriculumActivityTest`'s page test now asserts the form instead of the read-only table's "Total". The main plan's two entries are marked resolved. The participant activity page shows no rubric, so that half of the acceptance could not be checked (see Open decisions).
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

- [x] **3.1 Removed checklist items stop counting** (CUR-12) — done 2026-10-27. `scopeWeeklyVideo()` chains `onChecklist()`. Every other video reader goes through it: `asksForWeeklyVideo()` (week page, dashboard, video page, notifications, missing-submission report) and `GateReadinessService` (readiness, the video state on the Gate, review and board pages). `CoachGateReviewPageService` line 51 and `StoreGateReviewRequest::requiredCriteria()` already filter `is_required`, so `isWeeklyVideo()` stays type-only. The board's `criteria_total` now uses the same on-checklist ids as `criteria_met`. No existing test relied on an optional video item.
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
- [x] **3.2 Edit the checklist and its items** (CUR-9, CUR-10, CUR-14, CUR-16) — done 2026-10-27. Routes `admin.programs.checklist.update` (PUT `/{program}/weeks/{week}/checklist`) and `admin.programs.checklist-items.update` (PUT `…/checklist/items/{checklistItem}`, bound through `TrainingWeek::checklistItems()`). `GateCriterion` gains `ONE_PER_CHECKLIST_TYPES` (session logs, weekly video) and `TrainingGate` gains `hasItemOfTypeOnChecklist($type, except: $item)`; `UpdateChecklistItemRequest` exposes `errorBagFor()` (`checklistItem{id}`), `details()` and `duplicateTypeMessage()` (the A5 message, built from `TYPE_LABELS`), which 3.3 and 3.4 can reuse. Both requests return 404 from `authorize()` before the policy (no checklist; an item removed or not on the week's checklist, e.g. of an inactive Gate of the week), use `NormalizesLineEndings` for `description`, and return to `#checklist`. The A5 check ignores the item itself and removed items. `ChecklistService::updateItem()` writes only `criterion_type`, `title` and `description`, so `configuration` is never written and every stored key (`expected_files`) survives, as do the id, position and ticks. Audit metadata holds `program_id`, `training_week_id`, `training_gate_id` (added so a row names the Gate) and, for items, `gate_criterion_id`, plus `changes` keyed `title` / `description` (and `type` for items). A description left out of the request is kept. The Checklist tab now has the checklist form ("Save Checklist") and one card per on-checklist item with Title, Type select, Description and "Save Item" (`aria-label` "Save Item {title}"); removed items stay read-only. Success flashes `Saved "{title}".` No existing test needed changing.
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
- [x] **3.3 Add and reorder checklist items** (CUR-10, CUR-14, CUR-16) — done 2026-10-27. Routes `admin.programs.checklist-items.store` (POST `…/checklist/items`) and `.move` (PATCH `…/checklist/items/{checklistItem}/move`). `StoreChecklistItemRequest` returns 404 from `authorize()` for a week without a checklist (as `UpdateChecklistRequest` does), exposes `checklist()` and `details()` (a description left out is null), and reuses `UpdateChecklistItemRequest::duplicateTypeMessage()`; `move` reuses `MoveCurriculumItemRequest` and returns 404 from the controller for an item removed from the checklist or not on the week's checklist (as 2.6's field move does). `ChecklistService::addItem()` and `moveItem()` lock the Gate row (`lockForUpdate()`); a new item goes after every item of the Gate, removed ones included (so 3.4's restore at `max + 1` stays last), with `is_required = true` and `configuration` null. `moveItem()` swaps among the items on the checklist only, so removed items keep their positions (`gate_criteria` has no unique position index, so parking on the on-checklist `max + 1` is safe even when a removed item holds that number). Moving past either end saves, records and flashes nothing. Audit metadata: `_added` holds the ids (with `gate_criterion_id`), `type`, `title` and `position`; `_moved` the ids, `direction` and `changes.position`. Success flashes `Added "{title}".` / `Moved "{title}" up.` and returns to `#checklist`. `checklistData()` gives each item `move_url`, `can_move_up` / `can_move_down`, `has_fixed_type` and `fixed_type_note`, and the checklist `add_item` (`store_url`, `error_bag`, `default_type` deliverable). Each item card has Move Up / Move Down (`aria-label` "Move {title} up/down", first and last disabled) above its form; the "Add item" form (Title, Type, Description, "Add Item") sits between the items and "Removed items". Also done here, per the coordinator's decision on 3.2's open decision: an item whose stored type is not in `TYPE_LABELS` (`GateCriterion::hasFixedType()`) shows its type as text with a hidden input, and `UpdateChecklistItemRequest` accepts that unchanged stored type. `AdminCurriculumChecklistTest`'s page test now also asserts the Move buttons and the add form, and its role test covers store and move. The A5 check on add still runs before the Gate lock (3.2's race entry under Open decisions still applies).
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
- [x] **3.4 Remove and restore checklist items** (CUR-11, CUR-14, CUR-16) — done 2026-10-27. Routes `admin.programs.checklist-items.destroy` (DELETE `…/checklist/items/{checklistItem}`) and `.restore` (PATCH `…/restore`). `RemoveChecklistItemRequest` returns 404 from `authorize()` for an item already removed or not on the week's checklist (as 3.2's update does); `RestoreChecklistItemRequest` returns 404 for an item of another Gate than the week's checklist, and restoring an item already on the checklist saves and records nothing (as 2.6's field restore). Both put their errors in the `checklist` bag under `item`, shown as an `x-ui.alert` (error, `data-checklist-error`) at the top of the Checklist tab; the checklist form now refills from `old()` only on its own `title` / `description` errors, so a failed remove keeps the stored title. `gate_criterion_evaluations.gate_criterion_id` cascades on delete, so `ChecklistService::removeItem()` locks the Gate row and then the item row (a tick saved at that moment waits for the lock) and decides under the locks: no evaluation row → deleted, otherwise `is_required = false`. **The last-item race is resolved for checklist removal:** `removeItem()` re-checks "A checklist needs at least one item." (`ChecklistService::LAST_ITEM_MESSAGE`, also used by the request) under the lock and throws a `ValidationException`, which the controller sends to the `checklist` bag and the `#checklist` tab; the activity guard (2.4's open decision) is unchanged. `removeItem()` returns whether it deleted; an item already off the checklist under the lock stays as it is. `restoreItem()` locks the Gate row and sets `is_required = true` and position `max + 1` over every item of the Gate; its A5 check still runs before the lock (3.2's open decision). Audit metadata: `_removed` holds the ids (with `gate_criterion_id`), `deleted`, `type`, `title` and `position` (the row may be gone); `_restored` the ids and `changes.removed` and `changes.position`. Flashes `Deleted "{title}".`, `Removed "{title}".` or `Restored "{title}".` and returns to `#checklist`. Each item card has Remove next to its Move buttons (`aria-label` "Remove {title}", `data-confirm` "Remove {title}? Mentors' earlier ticks are kept; the item leaves the checklist of every session.", built in `checklistData()`), and each removed item has Restore (`aria-label` "Restore {title}"). `AdminCurriculumChecklistTest`'s page test now also asserts Remove and Restore, and its role test covers destroy and restore.
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

- ~~Creating,~~ deleting, renumbering, activating or deactivating programs and weeks. Editing program name and description (the main plan's "The dashboard's training overview is static copy" entry). **Lifted in part by curriculum-sessions-merge 5.1:** an admin creates a curriculum with its weeks (name, description, number of weeks, optional start date); the rest stays out of scope.
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
- **Textarea line endings can make an unchanged save look changed** (found in 2.1). Browsers submit textarea newlines as `\r\n`. Text saved through these forms keeps `\r\n` and round-trips unchanged, but multi-line text stored with `\n` (a future seeder definition, or Tinker) is saved and audited once when an admin saves it untouched, against "a save that changes nothing writes no row" (CUR-14). The L4 seeder's text is single-line today, so nothing is affected yet. Decide whether the curriculum Form Requests normalize `\r\n` to `\n` in `prepareForValidation()` (from 2.2, whose instructions render with `whitespace-pre-line`, and back-filled into `UpdateTrainingWeekRequest`). **Decided 2026-10-27:** normalized to `\n` in the curriculum Form Requests from 2.2 on (2.1's request updated in 2.2).
- **The line-ending normalization is a private helper in each curriculum Form Request** (found in 2.2). `withUnixLineEndings()` is repeated in `UpdateTrainingWeekRequest` and `UpdateActivityRequest`, and 2.3 (`StoreActivityRequest`), 2.5/2.6 (field help) and 3.2/3.3 (checklist and item descriptions) need it too. `app/Http/Requests` has no `Concerns` folder; decide whether to add a small trait there (a new folder) or keep the per-request helper. **Decided 2026-10-27:** trait `App\Http\Requests\Concerns\NormalizesLineEndings`, added in 2.3.
- **Two simultaneous deactivations can leave a week with no active activity** (found in 2.4). `DeactivateActivityRequest::after()` checks "the last active activity" before `CurriculumWeekService::deactivateActivity()` runs and without the week lock, so two admins deactivating a week's last two active activities at the same moment both pass. Decide whether `deactivateActivity()` re-checks under the week row lock (as `moveActivity()` locks it), or whether the rare race is accepted.
- **A disabled `x-ui.button` has both `cursor-pointer` and `cursor-not-allowed`** (found in 2.4). The component adds `cursor-not-allowed opacity-55` when disabled but keeps `cursor-pointer` in its base classes, and the built CSS puts `.cursor-pointer` after `.cursor-not-allowed`, so a disabled button still shows the pointer. Not fixed here (shared component); the disabled Move buttons on the Activities tab are its first `<button>` users in the curriculum pages.
- **The new activity's Notes field is defined twice** (found in 2.3). `CurriculumWeekService::NOTES_FIELD` copies the Notes field that `L4TrainingProgramSeeder::activity()` appends to every seeded activity; `AdminCurriculumActivityTest` fails if the two drift apart. Decide whether to move it to one place both use (for example an `ActivityRequirement` constant next to 2.5's `FIELD_TYPES`, as 1.1 did for `Activity::DEFAULT_SCORING_DIMENSIONS`), which touches the seeder.
- **A field with a stored type that is neither offered nor `select` loses it on the next save** (found in 2.5). `ActivitySubmissionPageService::fieldControl()` also renders a `date` type, but `UpdateActivityFieldRequest` accepts only `ActivityRequirement::FIELD_TYPES` and an unchanged `select` (A9). Such a field's Type select has no selected option, so the browser submits the first one ("Long text") and the save changes its type. No L4 field uses another type today. Decide whether any unoffered stored type is kept read-only like `select`, or whether `date` joins `FIELD_TYPES`. **Decided 2026-10-27:** unoffered stored types stay read-only like `select`; done in 2.6.
- **A field's mirrored columns are only resynced when a setting changes** (found in 2.5). `ActivityFieldService::update()` compares the settings participants get (`configuration`, falling back to `title` / `description` / `is_required`), so a field whose columns disagree with its `configuration` (as factory-made fields in some tests do) keeps the stale columns after an unchanged save. Nothing reads those columns for display while `configuration.label` is set (`SubmissionAnswerPresenter` falls back to `title` only without one), so this is left as is.
- **The participant's submission page labels a removed field's answer from its key** (found in 2.6). `ActivitySubmissionPageService::showData()` takes labels from `SubmissionWorkflowService::fieldDefinitions()`, which skips removed fields, so the answer still shows but under the key in title case (`reflection` → "Reflection") instead of the field's label. The mentor review page (`SubmissionAnswerPresenter::fields()`) reads every submission field and shows the real label. The same was true for fields the seeder retired. Decide whether `showData()` should read labels from every submission field, as the presenter does.
- **An activity can lose all its submission fields** (found in 2.6). Nothing stops an admin removing the last field on the form, unlike a week's last active activity (A6). The participant form then has no fields. Decide whether an activity keeps at least one field.
- **The full suite intermittently ran out of PHP memory** (found in 2.4). With 2.4's tests the suite crossed PHP's 128M default and crashed in `ParticipantWorkflowTest::test_authorized_evidence_view_is_inline_and_download_is_attachment` (mime detection of a 7MB file) in two of three runs. Peak memory was the same with and without 2.4, so 2.4 does not leak; the suite was already at the limit. **Resolved 2026-10-27 (commit ce88a75):** `phpunit.xml` sets `memory_limit` to 512M; the suite then passed three runs in a row.
- **The participant activity page has no rubric** (found in 2.7). 2.7's acceptance and "What changes after an edit" say new dimensions show in the "participant activity page rubric", but `activities/show` lists no scoring dimensions; participants see only a published score's total there, and `submissions/show` lists a published score's dimension rows by key (`D1`), without labels or maxima. The new dimensions do show on the mentor scoring form (tested), the coach scoring criteria page and the AI suggestion prompt. Decide whether participants should see the rubric (labels and maxima) on the activity page, which would be a new batch.
- **A score saved during an admin's scoring save is not re-checked** (found in 2.7). `UpdateActivityScoringRequest::after()` checks `Activity::hasScores()` before `CurriculumWeekService::updateScoringDimensions()` runs, and `ScoringController::store()` does not lock the activity, so a mentor's first score saved in that instant could be followed by a dimension change. Decide whether the rare race is accepted (as for 2.4's deactivation) or the service re-checks inside its transaction.
- **An item with a stored type outside `GateCriterion::TYPE_*` loses it on the next save** (found in 3.2). Only factory-made items have one (`GateCriterionFactory` defaults to the legacy `activity_completion`; see the main plan's open decision); seeded items use the three types. Such an item's Type select has no selected option, so the browser submits "Deliverable" and an untouched save changes and audits its type. A legacy item already behaves like a deliverable, so nothing breaks. Decide whether an unoffered type stays read-only, as 2.6 did for fields, or whether fixing the factory default is enough. **Decided 2026-10-27:** unoffered stored types stay read-only, as for fields in 2.6; done in 3.3.
- **Two simultaneous type changes can give a checklist two session-logs or weekly-video items** (found in 3.2). `UpdateChecklistItemRequest::after()` checks A5 before `ChecklistService::updateItem()` runs and without a lock, so two admins changing two items to `weekly_video` at the same moment both pass. Readers take the first weekly-video item, so the week still asks for one video. Decide whether `updateItem()` (and 3.3's `addItem()`, 3.4's `restoreItem()`) re-check under a Gate row lock, or whether the rare race is accepted (as for 2.4 and 2.7). 3.4's `restoreItem()` also checks A5 before its Gate lock; 3.4 did re-check its own last-item guard under the lock, which shows the pattern if the A5 check moves there too.
- **A tick saved while its item is being deleted fails** (found in 3.4). `removeItem()` locks the item row, so on MySQL a mentor's review that ticks the item at that moment waits, and once the item is deleted its insert fails on the foreign key and the mentor gets an error page instead of a validation message. `GateReviewService::record()` takes no lock and `StoreGateReviewRequest` checks the items before it runs. Rare (an admin deletes an unticked item in the same instant a mentor first ticks it), and nothing is lost: the review can be saved again. Decide whether the review save should lock the Gate row (as the curriculum writes do) or whether this is accepted.

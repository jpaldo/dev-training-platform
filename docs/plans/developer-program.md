# Developer Program Plan

Source spec: `docs/specs/developer-program.md` · Related plan: `docs/plans/training-plan.md` (Training Sessions, "TS" below) · Last updated: 2026-10-23

**Status legend:** `[x]` done · `[ ]` to do · `[~]` in progress · `[-]` deferred

## Goal

Turn the platform from a single QA course into a developer training platform. Remove the QA program, its data and its QA-only tools; make the remaining features work for any program and any week; seed the 4-week *L4 Accelerator Training* with placeholder content, GitHub-link deliverables and a weekly mentor checklist; and seed demo developers and mentors for local and staging.

## Rules for every batch

- Read `CLAUDE.md` **as it is on disk** before starting (enums are the convention for statuses and roles; type discriminators such as `gate_criteria.criterion_type` stay plain strings, decided 2026-10-22 in `docs/CODE_QUALITY_PLAN.md`). Follow its "Code Quality Rules" for every new or touched line.
- Keep each batch small and independently shippable: one concern, its tests, green suite. Behavior changes only where the item says so.
- Do only the batch you were given. Record new problems under "Open decisions" instead of fixing them on the spot.
- Definition of done: `vendor/bin/pint --dirty --format agent`, the full suite passing (`php artisan test --compact`), `npm run build` if CSS, JS or Tailwind classes changed, and the item ticked here (`[x]`, or `[~]` with a short note).
- `php` is not on the default PATH in agent shells: use `~/.config/herd-lite/bin/php`.
- **Removal batches delete, they do not refactor.** Delete the feature's routes, controllers, Form Requests, policies, services, presenters, models, enums, factories, views, components, mail, config keys, sidebar links and tests, and drop its tables in a new migration. In code that stays, remove only the branches that served the feature. Never edit an old migration except to stop it referencing a deleted class (see 3.2).
- When a deleted feature leaves a kept test without purpose, delete the test case; when it leaves a kept test asserting the feature's absence is useful (route gone → 404), keep one such case.
- **Order with the TS plan.** Run TS Phase 1 first (3.3 here needs TS 1.1; 1.2 here needs TS 1.5). Then run Phases 1 to 3 here: **together they clear TS Gate A** (batches 1.1 to 3.4). 3.5 (migration squash) is **not** part of Gate A. Phases 4 and 5 can run any time after Gate A, but 4.3 must land before TS 6.1. Phase 6 waits for the TS batches named on each item.
- **Gate C — curriculum.** 6.4 waits for the training team's curriculum (Q1).
- **Gate D — migration squash.** 3.5 waits until every existing database has run every current migration (its Precondition). Until that is recorded on 3.5, skip it and continue with Phase 4; come back when it is. Migrations written before 3.5 runs are squashed with the rest; migrations written after it are normal new files on top of it, and no later batch (here or in the TS plan) edits the squashed files.
- New user-facing copy says "training", "participant" and "mentor"; never "QA", "tester" or "coach".

## Requirements

| ID | Requirement (from spec) | Covered by |
|---|---|---|
| REM-1 | Remove the *AI-Assisted QA Level 3* program and its seeder | 1.1 (data), 3.2 (seeder) |
| REM-2 | Remove the Validation Lab (pages, routes, models, tables, tests) and the submission-version link to it | 1.2 |
| REM-3 | Remove Week 2 gate rules, Week 3 workspace, reusable asset library, Week 4 mock interviews (pairs, feedback, waivers, mentor booking, availability calendar) | 1.5, 1.6, 1.7, 1.8, 1.10 |
| REM-4 | Remove QA PDF guides and their routes | 1.9 |
| REM-5 | No QA wording in pages, emails, exports or legal text; generic text says training, participant, mentor | 5.1, 5.2, 5.3 |
| REM-6 | `/` sends guests to login and signed-in users to their dashboard | 1.2 |
| REM-7 | Remove daily logs completely (pages, reports, exports, reminders, counts, gate criteria, model, table, tests) | 1.3, 1.4 |
| DAT-1 | Admin exports QA data before deletion; deployment notes include the step | 3.4 |
| DAT-2 | A migration deletes all QA training data and the files it references | 1.1 |
| DAT-3 | Keep admin and coach accounts; delete QA participant accounts | 1.1 |
| DAT-4 | Deletion is FK-safe on MySQL and SQLite and tested; the app works with no training data (empty states) | 1.1, 2.3 |
| GEN-1 | No behavior tied to a week number or activity position; features switched by type and configuration | 1.5 to 1.7, 1.10, 2.1 |
| GEN-2 | Program weeks are numbered 1..length | 2.1, 3.1 |
| GEN-3 | Scoring criteria, dimensions and AI prompts come from program data | 2.2 |
| GEN-4 | Active-program lookups handle "none"; replaced by the session later | 2.3 (TS 4.5, 5.x, 7.3 do the replacement) |
| DEV-1 | Seed *L4 Accelerator Training* (active, 4 weeks; activities, fields, evidence, a gate with criteria, scoring dimensions per week) in `L4TrainingProgramSeeder` | 3.1 |
| DEV-2 | Placeholder activities labelled *Placeholder* until the real curriculum; swapping it means editing the seeder | 3.1, 6.4 |
| DEV-3 | Same flow as today (activities, drafts, evidence, review, scoring, weekly video); weeks unlock by date | 3.1 (flow kept), TS 6.1 (date unlocking) |
| DEV-4 | Seed the first session *L4 Accelerator Training — November 2026*, 9 Nov to 6 Dec 2026 | 6.1 |
| DEV-5 | Deliverables are `https://github.com/...` links; other values fail validation; mentor review shows the links | 4.1 |
| DEV-6 | The seeder is re-runnable without duplicates or touching submissions | 3.1 |
| LOG-1 | Session logs live in the participant's GitHub repo; the platform never stores them | 4.2 (checklist item), no platform storage by design |
| LOG-2 | Repository URL per session (`https://github.com/<owner>/<repo>` only), changeable on the session page, shown to mentors | 6.3 |
| LOG-3 | Each week's gate is a mentor checklist: one item per required deliverable plus "Session logs committed for this week"; nothing auto-ticked | 3.1, 4.2 |
| LOG-4 | *Completed* only when every required item is ticked; *incomplete* records the missing items; outcomes drive TS WK-2/WK-3 | 4.3 |
| DMO-1 | 3 demo developers and 3 demo mentors with employee ID, first/last name, email, known password; participants' job role Developer | 3.3 |
| DMO-2 | Demo participants join the first session, each with a demo mentor | 6.2 |
| DMO-3 | Demo users only in local and staging, via `DevelopmentAccountProvisioner`, never production | 3.3 |

3.5 (replace the old migrations) is a maintenance batch the user requested on 2026-10-23; it is not a spec requirement and changes no behavior.

## Current state

- **Working tree:** `database/seeders/L4TrainingProgramSeeder.php` exists, untracked and **empty** (0 bytes); there are no other uncommitted code changes. Nothing in this plan is already done.
- **QA program content** lives in `database/seeders/QaTrainingProgramSeeder.php` (weeks 2 to 4, gates with `weekly_video` criteria). It is run by `DatabaseSeeder`, by `app:ensure-development-baseline` (`EnsureDevelopmentBaselineCommand`, used by `scripts/dev.ps1`) and by about 25 tests. The old migration `database/migrations/2026_09_10_160000_add_week3_activity_fields.php` instantiates it (`new QaTrainingProgramSeeder` for `fieldGuidance()`), so deleting the seeder breaks fresh migrations unless that line is removed.
- **QA features to remove**, with their footprint (non-migration files referencing them):
  - Validation Lab: `ValidationLabController`, `ValidationLabService`, `ValidationSubmission` (+ policy, 3 enums, 2 Form Requests), views `home`, `artifact`, `checklist`, `result`, `lab/*`, routes `/`, `/lab/*`, `/start`, `/checklist`, `/result/*`, `/lab/records/*`; `submission_versions.validation_submission_id`; `Activity::isValidationChecklist()`, the Lab prerequisite in `ActivitySubmissionService` / `TrainingActivityController` / `ActivitySubmissionController`; Lab files in `SyncTrainingDataJob` and workbook headers; the QA practice app card on the participant dashboard (`services.qa_application.url`, `$qaMembershipFeatures`).
  - `WeekTwoGateService` (gate readiness: daily logs, activities handed in, evidence, weekly video) is used by 11 services and `GateReviewController`.
  - Week 3 workspace: 4 models, `WeekThreeWorkspaceService`, `WeekThreeWorkspacePresenter`, controller, request, `submissions/week-three` view, branches in submission, scoring, review and workbook services; `WeekProgressionService::ensurePriorWeekThreeActivities()`.
  - Week 4 mock interviews: 5 models, 3 services, 3 controllers, 4 Form Requests, `MentorMockInterviewScheduled` mail, `coach/mentor-availability` and `coach/week-four-questionnaire` views, 4 enums, `config/training.php` `week_four_*` keys, `GoogleCalendarService` (only other users: `AdminSettingsStatusService`, `GoogleDriveConfigurationService`), branches in submission, review, dashboard and admin services.
  - Reusable asset library: controller, 2 services, `FilterReusableAssetsRequest`, views, `EvidenceFilePolicy::reusableAsset*`, sidebar links.
  - Guides: `TrainingGuideController`, `training-guides/*.pdf`, dashboard "Training Guide" card.
  - Daily logs: `DailyLog` (+ policy, factory, 5 Form Requests, `ParticipantNotificationType::DailyLogs`), 3 controllers, `DailyLogPageService`, `DailyLogReportPageService`, `CoachParticipantDailyLogPageService`, views `daily_logs/*`, `coach/daily-log-report`, `coach/daily-logs-details`, `coach/participant-daily-logs`, plus counts in dashboards, gate board/review, coach participants, coach and missing-submission reports, notifications, exports and workbooks; `config/training.php` `daily_logs_per_week`, `general_training_daily_logs_expected`.
- **Hardcoded weeks** (about 53 matches): most disappear with the removals; the rest are `CoachGateBoardPageService::WEEK_OPTIONS`, `CoachGateOverviewService`, `CoachReviewQueuePageService::REVIEWED_WEEKS`, `ScoringPageService::SCORED_WEEKS`, `ScoringService::summaries()`, `MissingSubmissionReportService`, `ParticipantNotificationService` (`[2, 3, 4]`), `GateReviewController::guard()`, `ActivitySubmissionPageService` (week 2 check), `TrainingWorkbookService`, the participant dashboard view.
- **Scoring** uses the fixed `ScoringService::DIMENSIONS` (D1 to D5 with QA labels, max 100 total) in `StoreActivityScoreRequest`, `ScoringPageService`, `ScoringSuggestionService`, `ActivitySubmissionPageService`, `ParticipantDashboardService`; `activity_score_dimensions.dimension` is `string(2)`.
- **Active-program `firstOrFail()`**: `ParticipantDashboardController`, `ScoringReportController::participantSummary`, `ScoringPageService` (line ~231).
- **Gate review** (`GateReviewController`, `StoreGateReviewRequest`, `CoachGateReviewPageService`, `coach/gate_review` view): decisions `GateEvaluationStatus::decisions()` = Passed, NeedsRevision, Failed; Passed requires every required criterion Met and empty readiness; criteria saved as `GateCriterionStatus` Met / NotMet.
- **Submission fields** come from `activity_requirements` with `requirement_type` `submission_field` and `configuration.type` (`text`, `textarea`, `url`, `select`, `date`, `participant_selector`); drafts are validated in `StoreSubmissionDraftRequest`, submissions in `SubmissionWorkflowService`.
- **Demo accounts**: `DevelopmentAccountProvisioner` reads `config/development_accounts.php` (one dev coach, one dev admin) and refuses outside `local` / `testing`.
- **QA wording** appears in about 52 app and view files (many deleted by Phase 1).
- **Deploys** (`scripts/atomic-release.sh`, `docs/ATOMIC_DEPLOYMENT.md`) never run migrations: `prepare` extracts the release, links the shared `.env` and storage, installs Composer dependencies, then `health_check` runs `php artisan migrate:status` against the live database and dies on any `Pending` line ("Pending migrations block activation"). Migrations are applied by hand, expand/migrate/contract. Seeders are run by hand.
- **Migrations:** 81 files in `database/migrations` (Laravel's three `0001_01_01_*` files plus 78 dated 2026-09-03 to 2026-10-06). 15 of them are data migrations that rewrite QA activities, requirements and gate criteria by title (e.g. `2026_09_07_030000_align_week2_working_template_fields.php`, `2026_09_18_160000_align_current_activity_submission_definitions.php`); they match no rows on a fresh database. `2026_09_10_160000_add_week3_activity_fields.php` instantiates `QaTrainingProgramSeeder` (handled in 3.2). Two migrations exist only to fix SQLite foreign keys (`2026_09_14_121000_…`, `2026_09_16_160000_…`). None inserts rows on a fresh database.
- **TS plan dependencies:** TS Gate A waits for Phases 1 to 3 here. TS 1.1 adds `employee_id`, `first_name`, `last_name`, `job_role` (needed by 3.3). TS 1.5 extracts the role → landing-route mapping (used by 1.2). TS 2.x creates `TrainingSession` and its participants; TS 5.1 creates `MentorAssignmentService`; TS 4.5 creates the participant session page. TS 6.1 to 6.6 read the checklist outcomes from 4.3 here.

### Overlaps with `docs/CODE_QUALITY_PLAN.md`

- The open "Production values" check (blocks deploying Phase 7) lists `mentor_interview_availabilities` and `week_four_mentor_interview_schedules` queries; those tables are dropped in 1.7. Note it in that plan when 1.7 lands; the check still applies to the remaining columns.
- Many open decisions there concern removed features (Validation Checklist lock, Mentor Booking Calendar, Week 4 guide badges, daily-log report filters, unused `TrainingExportService::dailyLogs()`, weekly-video literals in `WeekTwoGateService`). Close them with "removed by developer program n.n" when the batch lands; do not fix them first.
- "Weekly Video Recording treated as required whatever the Gate criteria" (batch 8t) is resolved by 1.10 here (video required only when the week's checklist has a `weekly_video` item).

## Design

### Gate readiness (1.10)

`GateReadinessService` replaces `WeekTwoGateService` with only generic rules, keyed by gate and criterion type: every active activity of the week has a handed-in attempt; its required evidence is present; a weekly video exists when the gate has a `weekly_video` criterion. Same public shape as today (`readiness()`, `readinessForGates()`, `readinessForParticipants()`, `weeklyVideo()`, `weeklyVideosForParticipants()`), so callers only change the type they inject. `weekly_loom` legacy handling goes (DAT-2 deletes the data it served).

### Weekly checklist (3.1, 4.2, 4.3)

- Each program week has exactly one active gate, the week's checklist. Its items are `gate_criteria` rows: one `deliverable` item per required deliverable, one `session_logs` item titled "Session logs committed for this week", and optionally a `weekly_video` item. Items belong to the **week** (the gate), not to an activity (answered 2026-10-23).
- Every active week of an active program must have at least one required checklist item. The L4 seeder throws if it would leave a week without one, and a test asserts it for the seeded program.
- Criterion type strings are listed once as constants on `GateCriterion` (e.g. `GateCriterion::TYPE_DELIVERABLE`, `TYPE_SESSION_LOGS`, `TYPE_WEEKLY_VIDEO`, `VIDEO_TYPES`), replacing the scattered literals.
- Outcomes keep their stored values: **Completed** = `GateEvaluationStatus::Passed`, **Incomplete** = `GateEvaluationStatus::NeedsRevision`. `label()` becomes "Completed" / "Incomplete"; `decisions()` becomes `[Passed, NeedsRevision]` (no new Failed reviews). The missing items of an incomplete review are its criterion evaluations with `NotMet`. TS 6.1 reads exactly these.

### Scoring dimensions (2.2)

Per activity in `activities.configuration['scoring_dimensions']`: an ordered list of `{key, label, max}`, keys `D1`..`D9` (fits `string(2)`), maxima summing to 100 so the existing bands (`ScoringService::band()`) still apply. `Activity::scoringDimensions()` returns them. AI prompts take the program name, week title, activity title, description and these dimensions; no QA text in code.

### GitHub links (4.1, 6.3)

- `App\Rules\GitHubUrl`: `https://github.com/` followed by at least an owner segment; used for submission fields with `configuration.type` `github_url` (draft save and submit).
- `App\Rules\GitHubRepositoryUrl`: exactly `https://github.com/<owner>/<repo>` (optional trailing slash; `.git` rejected); used for `training_session_participants.repository_url` (nullable string, 255).

### Seeders

- `L4TrainingProgramSeeder` (DEV-1, DEV-2, DEV-6): `updateOrCreate` keyed by program name, (`program_id`, `week_number`), (`training_week_id`, `position`), requirement `configuration.key`, (`training_gate_id`, `position`). Records that the seeder no longer defines are set `is_active = false`, never deleted (deleting would cascade into submissions). Sets L4 active.
- `L4FirstSessionSeeder` (DEV-4): `firstOrCreate` the November 2026 session through TS `TrainingSessionService`.
- Demo users live in `config/development_accounts.php`, provisioned by `DevelopmentAccountProvisioner` (local, staging, testing; throws in production). Demo enrollment (DMO-2) is a second provisioner step.

## Assumptions

- **QA data scope.** DAT-2 deletes every row in the training tables (not only rows linked to the QA program), since the QA program is the only one with data; it deletes the QA program by name (*AI-Assisted QA Level 3*). Files on Google Drive are not deleted; only local storage files are. — Simplest correct reading of "all QA training data".
- **DAT-1 coverage.** The existing exports (CSV reports, attendance CSV, per-participant Drive sync) do not bundle uploaded files, so the release checklist adds a database dump and an archive of `storage/app/private` taken by whoever runs the deploy, next to the CSV downloads. — Confirm with the user; building a bulk export UI is out of scope.
- **QA participant accounts** = every user with role `participant`, including the seeded `test@example.com`. Admin and coach accounts stay, with their audit rows.
- **Google Calendar.** With mentor interview booking gone, `GoogleCalendarService` and the Google Calendar status and verification on the admin settings pages are removed too, if no other caller remains when 1.7 runs (public interview scheduling uses external booking links). — Confirm; it is part of the mentor booking feature.
- **The QA practice app card** on the participant dashboard (`services.qa_application.url`, membership features list) belongs to the Validation Lab and is removed in 1.2.
- **Ordered Week 3 activities** (`ensurePriorWeekThreeActivities`) were part of the Week 3 workspace and go with it; no generic "complete activities in order" setting replaces it. — GEN-1 does not ask for one.
- **Readiness.** Until 4.3, gate readiness still blocks a *Passed* review as today; from 4.3, only the checklist decides (LOG-4), and readiness is shown to the mentor as information.
- **Weekly video in the checklist.** DEV-3 keeps weekly videos, but LOG-3 lists only deliverables and session logs. The placeholder checklists include a required `weekly_video` item so the flow stays "same as today"; the real curriculum can drop it per week. — Confirm with the user.
- **Incomplete needs a missing item.** Marking a week *incomplete* with every required item ticked fails validation ("Leave at least one item unticked to mark the week incomplete."). — Otherwise WK-4 would show an empty missing list.
- **Deliverables are submission fields** of type `github_url` (one per placeholder activity), not evidence links.
- **Placeholder content:** 2 activities per week, each with a description ("Placeholder — objective and instructions to come"), a required `github_url` deliverable field, an optional notes field, no required evidence, and dimensions D1 to D4 (40/30/20/10). Program description is a placeholder sentence.
- **"Coach" becomes "Mentor" in user-facing text** (REM-5 "generic text says … mentor"): sidebar, headings, emails, exports, role label. Route names, URLs (`/coach`), the `UserRole::Coach` case and its stored value `coach` stay. — Isolated in 5.3 so it can be dropped if the user disagrees.
- **Repository URL (LOG-2)** is asked on the TS session page after a one-click Join (answered 2026-10-23); a member without one sees a prompt there, but it does not block submissions. Mentors see "No repository yet". — The spec does not say it is required before submitting.
- **Root-level QA artefacts** (`QA_LAB_MASTER_PLAN.md`, `QA_Training_Working_Template.xlsx`, `training-plans/*.pptx`, `training-apps/membership-app`) are not app code and are left alone; listed under follow-ups.
- **Seeding in production.** The L4 program and first session are seeded by running their seeders during the release (`php artisan db:seed --class=… --force`), recorded in the release checklist; demo users never are.

- **Migration squash scope (3.5).** It replaces every migration file present when it runs: the 81 above, the ones added by 1.1 to 3.4, TS 1.1, and any TS Phase 2 or 3 migrations already merged (TS Phase 2 has no gate). The databases that must have run them are production, staging and any shared database; developers' local databases are rebuilt with `migrate:fresh --seed` or adopt the new names with the same command.
- **Adopting the new migrations** is done by a one-time artisan command shipped in the squash release (not hand-run SQL), so it can check the database before changing it and is testable on SQLite. It only rewrites rows in the `migrations` table; it never runs migration code.
- **Framework tables** (`password_reset_tokens`, `sessions`, `cache`, `cache_locks`, `jobs`, `job_batches`, `failed_jobs`) also get one file each, written by hand like the rest.
- **Rolling back across the squash.** After adoption, the previous release's `migrate:status` shows its old files as pending. Activating it again with `atomic-release.sh rollback` still works (rollback runs no migration check), but nobody may run `php artisan migrate` from a pre-squash release afterwards.

## Open questions

- **Q1 (blocking 6.4):** The training team has not supplied the developer curriculum (week themes, activities with objective and deliverable, mentor review, checklist items, scoring dimensions, program description, resources). 3.1 ships placeholders; 6.4 swaps in the real content. Needed: the filled "Developer curriculum" table from the spec. No recommendation; it is an input.

## Phase 1 — Remove QA data and tools (clears TS Gate A with Phases 2 and 3)

- [ ] **1.1 Delete the QA training data and its files** (DAT-2, DAT-3, DAT-4, REM-1 data)
  - **Do:** One migration that, in FK-safe order with the query builder (no models): collects the local storage paths of `submission_evidence`, `weekly_video_recordings` and Validation Lab records; deletes rows from Drive export/sync tables, notifications, recovery requests, scores (and dimensions, history, suggestions), reviews, interactions, evidence, versions, submissions, gate criterion evaluations, gate evaluations, daily logs, attendance, weekly videos, Validation Lab records, Week 3 and Week 4 tables, `coach_participant_assignments` of deleted participants; deletes training sessions of the QA program if `training_sessions` exists (TS 2.1 may have run); deletes the QA program (weeks, activities, requirements, gates cascade); deletes every `participant` user; then deletes the collected files. `down()` is a no-op with a comment. Works on MySQL and SQLite.
  - **Acceptance:** On a database seeded with the QA seeder plus participants, submissions with files, videos, logs, gate evaluations, notifications and Drive records, running the migration leaves: no participant users, no QA program, empty training tables, no referenced files on the fake disk, and admin/coach users and their audit rows intact. Running it on an empty database does nothing.
  - **Tests:** New `tests/Feature/DeleteQaTrainingDataMigrationTest.php` (`Storage::fake('local')`, require the migration file and call `up()`): full cleanup, accounts kept, files deleted, idempotent on empty data.

- [ ] **1.2 Remove the Validation Lab and open `/` on login or the dashboard** (REM-2, REM-6)
  - **Do:** Delete the Lab (see Current state), its routes and tests; drop `validation_submissions` and `submission_versions.validation_submission_id` (migration). Remove Lab branches from `Activity`, `SubmissionVersion`, `User`, `ActivitySubmissionService`, `ActivitySubmissionController`, `TrainingActivityController`, `ActivitySubmissionPageService`, `TrainingActivityPageService`, `CoachSubmissionReviewPageService`, `SubmissionWorkflowService`, `ParticipantDashboardService`, `SyncTrainingDataJob`, `TrainingWorkbookService`, the submissions and review views, and the QA practice app card from `ParticipantDashboardController` / `participant/dashboard` (plus `services.qa_application` config). `/` (`home`) becomes a small controller or closure: guests → `login`, signed-in users → their role landing page through the shared mapping from TS 1.5. Keep the brand link in `layouts/navigation` pointing at `home`.
  - **Acceptance:** `/` redirects a guest to `/login` and a coach to `/coach`; `/lab/login`, `/checklist`, `/result/1` return 404; the activity and submission pages render with no Lab prerequisite.
  - **Tests:** Delete `ValidationLab*Test`; replace `HomePageTest` / `HomeNavigationTest` cases with the redirects; update tests that referenced the Lab (`TrainingActivityPageTest`, `ParticipantWorkflowTest`, `CoachSubmissionReviewPageTest`, …).
  - **Depends on:** 1.1, TS 1.5.

- [ ] **1.3 Remove daily logs from reports, dashboards, notifications and exports** (REM-7)
  - **Do:** Remove daily-log counts, columns, filters and reasons from `WeekTwoGateService` (log readiness), `ParticipantDashboardService`, `AdminDashboardPageService`, `CoachGateOverviewService`, `CoachGateBoardPageService`, `CoachGateReviewPageService`, `ParticipantGatePageService`, `CoachParticipantListPageService`, `CoachParticipantPageService`, `CoachReportService`, `MissingSubmissionReportService` / `MissingSubmissionReportPageService` / `FilterMissingSubmissionsRequest`, `ParticipantNotificationService` (and the `DailyLogs` notification type; delete leftover rows of type `daily_logs` in a migration before removing the case), `TrainingExportCoordinator`, `TrainingExportService`, `TrainingWorkbookService`, the `ParticipantProgressObserver` hook, `x-ui.report-nav`, and their views.
  - **Acceptance:** No page, report, CSV, workbook or notification mentions daily logs; gates no longer require logs; the daily-log pages still work (removed in 1.4).
  - **Tests:** Update the affected page, report, notification, export and Drive tests; remove daily-log assertions.
  - **Depends on:** 1.2.

- [ ] **1.4 Remove the daily-log pages, model and table** (REM-7)
  - **Do:** Delete `DailyLogController`, `DailyLogExportController`, `DailyLogReportController`, `CoachParticipantController@dailyLogs`, their routes, Form Requests, `DailyLogPolicy`, `DailyLog`, `DailyLogFactory`, page services, views, sidebar links, `User::dailyLogs()`, `Activity::dailyLogs()`, config keys; drop `daily_logs`.
  - **Acceptance:** `/daily-logs`, `/coach/reports/daily-logs`, `/coach/participants/1/daily-logs` return 404; `grep -rni "daily.log\|DailyLog" app resources routes config database/factories` finds nothing.
  - **Tests:** Delete `DailyLogPagesTest`, `DailyLogReportPageTest`, `CoachParticipantDailyLogPageTest`, `ParticipantDailyLogsTableTest`; keep one 404 case in `AuthenticationAuthorizationTest`.
  - **Depends on:** 1.3.

- [ ] **1.5 Remove the Week 3 workspace** (REM-3, GEN-1)
  - **Do:** Delete the 4 models, service, presenter, controller, request, route, `submissions/week-three` view and the `week_three_snapshot` handling in `ActivitySubmissionService`, `ActivitySubmissionPageService`, `SubmissionAnswerPresenter`, `CoachSubmissionReviewPageService`, `ScoringPageService`, `TrainingWorkbookService`, the submission/review/scoring views and `WeekProgressionService::ensurePriorWeekThreeActivities()`; drop the Week 3 tables; remove `User::weekThreeWorkspaces()` and `TrainingPath::weekThreeTestPaths()`.
  - **Acceptance:** Activities in any week 3 behave like any other activity; the workspace route returns 404.
  - **Tests:** Delete `WeekThree*Test`; update tests asserting Week 3 snapshots.
  - **Depends on:** 1.4.

- [ ] **1.6 Remove Week 4 peer interviews** (REM-3, GEN-1)
  - **Do:** Delete pairing, partner feedback, waivers and the questionnaire: `WeekFourInterviewPair`, `WeekFourPartnerFeedback`, `WeekFourPeerInterviewWaiver`, `WeekFourInterviewService`, `WeekFourPeerInterviewController`, `AssignWeekFourPairRequest`, `WaiveWeekFourPeerInterviewRequest`, `InterviewPairStatus`, `WeekFourReviewPresenter`, `WeekFourSubmissionPresenter`, `x-week-four-review` component, `submissions/week-four-guidance`, `coach/week-four-questionnaire`, `participant_selector` handling and the week 4 activity 2 branches in `SubmissionWorkflowService`, `StoreSubmissionDraftRequest`, `ActivitySubmissionPageService`, `CoachSubmissionReviewPageService`, `ParticipantDashboardService`, `ParticipantDashboardController`, `CoachParticipantListPageService`, `AdminDashboardPageService`; `config/training.php` `week_four_interview_questions`; drop the tables.
  - **Acceptance:** A week 4 activity 2 behaves like any other activity; the pairing and questionnaire routes return 404; admin and participant dashboards render without the Week 4 sections.
  - **Tests:** Delete `WeekFourWorkflowTest`, `WeekFourReviewComponentTest`, `CoachWeekFourQuestionnairePageTest`; update dashboard and review tests.
  - **Depends on:** 1.5.

- [ ] **1.7 Remove mentor interview booking and the availability calendar** (REM-3)
  - **Do:** Delete `WeekFourMentorInterviewSchedule`, `MentorInterviewAvailability`, `MentorInterviewSchedulingService`, `MentorInterviewAvailabilityService` (if present), `MentorBookingCalendarPageService`, `WeekFourMentorInterviewController`, `MentorBookingCalendarController`, `ScheduleMentorInterviewRequest`, `StoreMentorBookingCalendarRequest`, the two schedule/availability enums, `MentorMockInterviewScheduled` and its email view, `ParticipantNotificationType::MentorInterviewScheduled` (delete its rows first), `EmailConfigurationService::isMentorInterviewEmailEnabled()`, `config/training.php` `week_four_mentor_interview_*`, the factory, routes, sidebar links and dashboard parts; then `GoogleCalendarService` and the calendar status/verification in admin settings if nothing else calls them (see Assumptions); drop the tables.
  - **Acceptance:** Booking, cancel and calendar routes return 404; admin settings render without calendar items (or with them, if a caller remains, noted under Open decisions); `/interviews` public scheduling still works.
  - **Tests:** Delete `MentorInterviewSchedulingTest`, `MentorBookingCalendarPageTest`; update `AdminSettingsPageTest`, `InterviewSchedulingTest`, `AppLayoutTest`.
  - **Depends on:** 1.6.

- [ ] **1.8 Remove the reusable asset library** (REM-3)
  - **Do:** Delete `ReusableAssetLibraryController`, both services, `FilterReusableAssetsRequest`, views, routes, sidebar and admin dashboard links, and `EvidenceFilePolicy::reusableAssetRules()` / `reusableAssetAcceptAttribute()` (check `StoreSubmissionEvidenceRequest` still validates with the general rules).
  - **Acceptance:** `/training/reusable-assets` returns 404; evidence upload still validates as before.
  - **Tests:** Delete `ReusableAssetLibrary*Test`; update `AppLayoutTest`, `AdminDashboardPageTest`.
  - **Depends on:** 1.7.

- [ ] **1.9 Remove the QA PDF guides** (REM-4)
  - **Do:** Delete `TrainingGuideController`, its routes, the dashboard "Training Guide" card, sidebar entries and the `training-guides/` directory.
  - **Acceptance:** `/training/guides/2` returns 404; the dashboard week panels render without the guide card.
  - **Tests:** Delete `TrainingGuideTest`; update `ParticipantDashboardControllerTest`.
  - **Depends on:** 1.8.

- [ ] **1.10 Replace the Week 2 gate rules with generic gate readiness** (REM-3, GEN-1)
  - **Do:** Create `GateReadinessService` (Design) and switch every `WeekTwoGateService` caller to it (`GateReviewController`, `CoachGateBoardPageService`, `CoachGateOverviewService`, `CoachGateReviewPageService`, `CoachParticipantPageService`, `MissingSubmissionReportService`, `ParticipantDashboardService`, `ParticipantGatePageService`, `ParticipantNotificationService`, `TrainingWeekPageService`); delete `WeekTwoGateService` and `weekly_loom` legacy handling. Add the `GateCriterion` type constants (Design) and replace the scattered `['weekly_video', 'weekly_loom']` literals. Missing-submission and notification surfaces require a video only when the week's gate has a `weekly_video` criterion.
  - **Acceptance:** In a program with weeks 1 to 3, each week's gate readiness lists unsubmitted activities, missing required evidence and (only with a video criterion) a missing video; a week without a video criterion never reports a missing video.
  - **Tests:** Replace `WeekTwoGateTest` with `tests/Feature/GateReadinessTest.php` on a factory-built 3-week program; update gate board, gate review, participant gate and notification tests.
  - **Depends on:** 1.9.

## Phase 2 — Generalize what stays (clears TS Gate A with Phases 1 and 3)

- [ ] **2.1 Remove the remaining week-number and position checks** (GEN-1, GEN-2)
  - **Do:** Replace `[2, 3, 4]` and similar with "every active week of the program" in `CoachGateBoardPageService`, `CoachGateOverviewService`, `CoachReviewQueuePageService`, `ScoringPageService`, `ScoringService::summaries()`, `MissingSubmissionReportService`, `ParticipantNotificationService`, `GateReviewController::guard()` (any week of the participant's program), `ActivitySubmissionPageService`, `TrainingWorkbookService`, `participant/dashboard`. Week options in filters come from the program's weeks. Confirm with `grep -rnE "week_number(\s*)(===|!==|==)\s*[0-9]|\[2, 3, 4\]|position(\s*)(===|!==)\s*[0-9]" app resources` that nothing remains.
  - **Acceptance:** With a factory program of weeks 1 to 4, week 1 work appears in the review queue, gate board, scoring overview, missing-submission report and notifications, and week 1's gate can be reviewed.
  - **Tests:** Add a week-1 case to `CoachReviewQueuePageTest`, `CoachGateBoardPageTest`, `CoachGateReviewPageTest`, `ScoringOverviewPageTest`, `MissingSubmissionReportPageTest`, `ParticipantNotificationsTest`.
  - **Depends on:** 1.10.

- [ ] **2.2 Take scoring dimensions and AI prompts from the activity** (GEN-3)
  - **Do:** Add `Activity::scoringDimensions()` (Design); remove `ScoringService::DIMENSIONS`; `StoreActivityScoreRequest` validates the submitted dimensions against the version's activity (keys present, `0..max`), with readable attribute names (the label); `ScoringPageService`, `ScoringSuggestionService`, `ActivitySubmissionPageService`, `ParticipantDashboardService` (score max), `scoring/edit` and `scoring/criteria` read them; AI providers build the prompt from program, week, activity text and dimensions with no QA wording. An activity without dimensions cannot be scored (scoring page explains it).
  - **Acceptance:** An activity with dimensions D1 to D3 (50/30/20) shows those three on the scoring form; posting D4 or D1 = 60 fails validation; the AI prompt contains the activity's dimension labels and no "QA".
  - **Tests:** Update `ScoringTest`, `ScoringEditPageTest`, `ScoringCriteriaPageTest`, `AiProviderArchitectureTest`; add the validation cases.
  - **Depends on:** 2.1.

- [ ] **2.3 Handle "no active program" everywhere** (GEN-4, DAT-4)
  - **Do:** Replace the three `firstOrFail()` lookups (`ParticipantDashboardController`, `ScoringReportController`, `ScoringPageService`) with nullable lookups and empty states ("No training program is active yet."), and check the other active-program readers (`AdminDashboardPageService`, `CoachDashboardPageService`, `CoachReportService`, `MissingSubmissionReportService`, `ParticipantNotificationService`, `ExportParticipantScoreSummaryRequest`) return empty results without errors.
  - **Acceptance:** With no programs and no training data, the participant, coach and admin dashboards, reports, scoring pages and CSV exports return 200 with empty states.
  - **Tests:** New `tests/Feature/EmptyTrainingDataTest.php` hitting each page and CSV as the right role with no program.
  - **Depends on:** 2.2.

## Phase 3 — L4 program, demo users and release notes (clears TS Gate A with Phases 1 and 2)

- [ ] **3.1 Seed L4 Accelerator Training with placeholder content** (DEV-1, DEV-2, DEV-3, DEV-6, GEN-2, LOG-3 seeding)
  - **Do:** Fill `database/seeders/L4TrainingProgramSeeder.php` (Design, Assumptions): program "L4 Accelerator Training", active; weeks 1 to 4 (`week_number` = `position`); 2 placeholder activities per week with "Placeholder" in the title, a required deliverable field (type `url` until 4.1 switches it to `github_url`), a notes field, and scoring dimensions; one active gate per week with one `deliverable` item per activity, a `session_logs` item and a `weekly_video` item, all required. The seeder throws if any active week ends with no required checklist item. Re-running updates in place and deactivates records it no longer defines.
  - **Acceptance:** Running it twice yields one program, 4 weeks numbered 1 to 4, 8 activities, 4 gates and no duplicates; a submission made between runs is untouched; every week has at least one required checklist item; L4 is active.
  - **Tests:** New `tests/Feature/L4TrainingProgramSeederTest.php`: structure, week numbering, re-run idempotence, submission preserved, deactivation of a removed record, checklist guard (a modified definition with an empty week throws).
  - **Depends on:** 2.3.

- [ ] **3.2 Remove the QA seeder** (REM-1)
  - **Do:** Delete `QaTrainingProgramSeeder`; remove its use from `database/migrations/2026_09_10_160000_add_week3_activity_fields.php` (inline nothing; drop the `guidance` lookup so the loop, which matches no activities on new installs, still runs); 3.5 later deletes that file with the other old migrations; `DatabaseSeeder` calls `L4TrainingProgramSeeder` and stops creating `test@example.com` with a path; `EnsureDevelopmentBaselineCommand` runs the L4 seeder ("Ensured the L4 training baseline."). Move the remaining tests that seeded the QA program (`CoachOperationsTest`, `ScoringTest`, `DataIntegrityTest`, `AttendancePagesTest`, `AttendanceTest`, `ParticipantFieldGuidanceTest`, `ActivityMarkdownRenderingTest`, `ParticipantNotificationsTest`, `GoogleDriveSyncTest`, `GoogleDriveExportTest`, `TrainingDomainTest`, `CoachReportsTest`, `DraftFieldLimitTest`, `InterviewSchedulingTest`, …) to factories or the L4 seeder. If more than about 8 files need real rewrites, split this batch by test file and note it here.
  - **Acceptance:** `migrate:fresh --seed` works on SQLite and MySQL; `grep -rn QaTrainingProgramSeeder` finds only this plan and old docs; the suite passes.
  - **Tests:** The moved tests; `DevelopmentAccountProvisioningTest` baseline case.
  - **Depends on:** 3.1.

- [ ] **3.3 Provision demo developers and mentors in local and staging** (DMO-1, DMO-3)
  - **Do:** `config/development_accounts.php`: 3 participants (job role Developer) and 3 coaches, each with employee ID (`DEV-0001`…), first and last name, `@example.test` email and a known password, next to the existing dev coach and admin. `DevelopmentAccountProvisioner` writes those fields and allows `local`, `staging` and `testing` only (throws in `production`); keep `app:ensure-development-accounts`.
  - **Acceptance:** Provisioning in local or staging creates or updates the 8 accounts idempotently with names, employee IDs and job roles; in production it throws and creates nothing; the demo participants can log in.
  - **Tests:** Update `DevelopmentAccountProvisioningTest`: fields, idempotence, staging allowed, production refused.
  - **Depends on:** 3.2, TS 1.1.

- [ ] **3.4 Write the release checklist** (DAT-1)
  - **Do:** Add a "Developer program release" section to `docs/ATOMIC_DEPLOYMENT.md`: before deploying, an admin downloads every existing export (coach progress, reviews, gates, scores, participant summary, missing submissions, attendance, daily logs; runs a Drive sync per participant) and the operator takes a database dump and an archive of `storage/app/private`; then deploy (the 1.1 migration deletes the data); then run `php artisan db:seed --class=L4TrainingProgramSeeder --force`; never run the demo provisioner in production.
  - **Acceptance:** The section exists and lists the steps in that order. (Docs only; written while the daily-log export still exists in the released version, so note that the export must be taken from the version running before this release.)
  - **Tests:** None (documentation).
  - **Depends on:** 3.3.

- [ ] **3.5 Replace the old migrations with fresh hand-written ones** (maintenance, user request 2026-10-23; not part of Gate A) — **Gate D**
  - **Precondition (record it here before starting):** every database that will run this code has applied every migration file present on `main` when the batch starts, including 1.1 (QA data deletion) and the table drops in 1.2 to 1.10. That means the release containing them has been deployed and migrated on **production and staging** (deploying `main` is currently blocked by the code quality plan's Phase 7 "Production values" check, so this waits for that too). A reviewer checks it by running, from the currently live release on each environment, `php artisan migrate:status --no-ansi` (no `Pending` line) and comparing its "Ran" list with the files in `database/migrations` at the batch's starting commit (same names, same count). Paste the date, environment, commit and result under this item.
  - **Do:**
    - **Capture the reference schema** from the starting commit, before deleting anything: run `migrate:fresh` on a scratch MySQL database and a scratch SQLite file, then export `php artisan db:show --json` and `php artisan db:table <table> --json` for every table (columns with types, nullability, defaults; indexes; foreign keys with on-delete/on-update) and row counts. Do the same read-only export from production and staging (to catch drift). Keep the exports in the session scratchpad, not in the repo.
    - **Write one migration per table** (`php artisan make:migration create_<table>_table --no-interaction`, then hand-edit), all with the same date prefix and increasing sequence numbers in foreign-key order (`users` first, then `programs`, `training_weeks`, …), reproducing the reference schema exactly: same column names, order, types, lengths, nullability, defaults, index names, unique keys and foreign keys. Framework tables included (Assumptions). Simple `down()` that drops the table. No data inserts, no model or seeder imports, no `DB::statement` unless a reference index cannot be expressed otherwise (note it).
    - **Delete every old migration file**, including Laravel's `0001_01_01_*` files and the data migrations (their only targets, QA rows, were deleted by 1.1 and they match nothing on a fresh database). Do not use `schema:dump`/`--prune`; no `database/schema` dump is committed.
    - **Add `App\Console\Commands\AdoptSquashedMigrationsCommand`** (`app:adopt-squashed-migrations`, options `--pretend`, `--force` required outside local) holding two constants: the replaced migration names and the new ones. In a transaction it: refuses (exit 1, no change) unless every replaced name is recorded in `migrations` and no row outside both lists exists; does nothing ("Already adopted.") when every new name is recorded and no replaced name is; otherwise deletes the replaced rows and inserts the new names with `batch = max(batch) + 1`. `--pretend` prints the rows it would delete and insert. It never runs a migration.
    - **Document the one-time release step** in `docs/ATOMIC_DEPLOYMENT.md`, section "One-time: adopting the squashed migrations", fitting the existing flow: (1) confirm the Precondition on that environment; (2) run `atomic-release.sh prepare` for the squash release, which stops at "unexpected pending migrations" after extracting and installing the release (expected: the new files are not recorded yet); (3) from that release directory run `php artisan app:adopt-squashed-migrations --pretend`, check the output, then run it with `--force`; (4) re-run `prepare` (it re-extracts and now passes the pending-migration check); (5) `activate` as usual; (6) never run `php artisan migrate` from a pre-squash release afterwards. Add the same note for developers' local databases (adopt or `migrate:fresh --seed`).
  - **Acceptance:**
    - `migrate:fresh` with the new files yields the same `db:table --json` output as the reference for every table on MySQL and on SQLite (ignoring only the `migrations` table's rows), and matches the production and staging exports; any difference is listed and explained under this item (e.g. drift found on production).
    - The reference fresh database has zero rows in every table except `migrations`, so no seed rows are lost.
    - `migrate:fresh --seed` works on SQLite and MySQL; the full suite passes on in-memory SQLite (`phpunit.xml`) unchanged.
    - On a copy of the staging database, `app:adopt-squashed-migrations --pretend` lists every old name and every new one; the real run leaves `migrate:status` with no `Pending` line; a second run prints "Already adopted."; running it on a database missing one old migration refuses and changes nothing.
  - **Tests:** New `tests/Feature/AdoptSquashedMigrationsCommandTest.php` (SQLite): adopt (replace rows, new batch number), already adopted (no-op), missing replaced row (refuse, unchanged), unknown extra row (refuse), `--pretend` (no change). The schema comparison is a one-time check recorded on this item, not a committed test (the old files are gone).
  - **Depends on:** 3.4, TS 1.1 (and any TS migrations already merged), the Precondition.

## Phase 4 — GitHub deliverables and the weekly checklist (after Gate A; 4.3 before TS 6.1)

- [ ] **4.1 Accept only GitHub links as deliverables** (DEV-5)
  - **Do:** `App\Rules\GitHubUrl`; submission field type `github_url`: `StoreSubmissionDraftRequest` applies the rule when filled, `SubmissionWorkflowService` applies it on submit; the field renders as `type="url"` with a `https://github.com/` placeholder; `SubmissionAnswerPresenter` and the review/scoring views render the value as a link opening in a new tab. Switch the L4 seeder's deliverable fields to `github_url`.
  - **Acceptance:** `https://github.com/ana/l4/pull/3` saves and shows as a link to the mentor; `http://github.com/x`, `https://gitlab.com/x` and plain text fail with "Enter a link to your public GitHub repository (https://github.com/…)."; other field types are unaffected.
  - **Tests:** New `tests/Unit/GitHubUrlRuleTest.php`; extend `SubmissionFormPageTest` and `CoachSubmissionReviewPageTest`.
  - **Depends on:** 3.4.

- [ ] **4.2 Turn each week's gate into the mentor checklist** (LOG-1, LOG-3)
  - **Do:** `CoachGateReviewPageService` / `coach/gate_review`: show the week's checklist (deliverable items, "Session logs committed for this week", weekly video) as checkboxes the mentor ticks (stored as `GateCriterionStatus` Met / NotMet), with the participant's deliverable links for the week listed beside it for checking by hand; nothing is pre-ticked from readiness or submissions. Participant gate page and week page show the checklist read-only with each item's status. Add a check in `GateReadinessService` or a small `Program` helper used by the seeder test that every active week of an active program has an active gate with at least one required item.
  - **Acceptance:** A mentor opening week 2's checklist sees every item unticked until they tick it, even when all activities are submitted; the participant sees the same items read-only; a program week without a checklist item is reported by the helper.
  - **Tests:** Update `CoachGateReviewPageTest`, `ParticipantGatePageTest`, `TrainingWeekPageTest`; extend `L4TrainingProgramSeederTest` with the helper.
  - **Depends on:** 4.1.

- [ ] **4.3 Mark weeks completed or incomplete from the checklist** (LOG-4)
  - **Do:** `GateEvaluationStatus`: labels "Completed" / "Incomplete" for `Passed` / `NeedsRevision`, `decisions()` = `[Passed, NeedsRevision]`. `GateReviewController::update` / `StoreGateReviewRequest`: *Completed* requires every required item ticked (readiness no longer blocks; it is shown as information); *Incomplete* requires at least one required item unticked and saves the unticked items as `NotMet`. Admins can do both. Update labels on the gate board, participant pages and notifications (`GatePassed` / `GateIncomplete` texts).
  - **Acceptance:** Completing with one item unticked fails with "Tick every checklist item to mark the week completed."; incomplete with all ticked fails (Assumptions); an incomplete review stores the unticked items as not met; an admin can mark a week completed; `Failed` is no longer offered.
  - **Tests:** Update `CoachGateReviewPageTest`, `CoachGateBoardPageTest`, `ParticipantGatePageTest`, `ParticipantNotificationTriggersTest`; add the validation cases.
  - **Depends on:** 4.2. **Needed by:** TS 6.1, 6.3, 6.5, 6.6.

## Phase 5 — Developer wording (any time after Phase 1)

- [ ] **5.1 Reword participant pages, emails and legal pages** (REM-5)
  - **Do:** Replace QA wording in participant-facing views and components (dashboard, weeks, activities, submissions, gate, video, notifications, auth), email views and `legal/privacy-policy`, `legal/terms-of-service`; generic "training" and "participant". Keep exact-markup test expectations in sync.
  - **Acceptance:** `grep -rniE "\bQA\b|tester" resources/views` finds nothing in these areas; pages render.
  - **Tests:** Update text assertions in the affected page tests; `LegalPagesTest`.
  - **Depends on:** 1.10.

- [ ] **5.2 Reword coach and admin pages, exports and service text** (REM-5)
  - **Do:** Same for coach, admin and scoring views, CSV headers and filenames, workbook sheets, and strings built in services and controllers (`AttendanceController`, `MissingSubmissionReportController`, `ScoringReportController`, `TrainingActivityController`, `AdminUserPageService`, `CoachParticipantListPageService`, `ActivitySubmissionService`, `SubmissionWorkflowService`, `EnsureDevelopmentBaselineCommand`, …).
  - **Acceptance:** `grep -rniE "\bQA\b|tester" app resources/views` finds nothing except enum/value names that are not shown to users (list any kept under Open decisions).
  - **Tests:** Update header and text assertions.
  - **Depends on:** 5.1.

- [ ] **5.3 Say "mentor" instead of "coach" in user-facing text** (REM-5)
  - **Do:** `UserRole::Coach->label()` → "Mentor"; sidebar ("Mentor Dashboard"), page titles, buttons, emails, CSV headers and messages that show "Coach". Route names, URLs, enum case and stored value stay.
  - **Acceptance:** No visible "Coach" text remains (`grep -rn "Coach" resources/views` only in non-visible names); `/coach` still works.
  - **Tests:** Update text assertions (`AppLayoutTest`, coach page tests, `ProfilePageTest`).
  - **Depends on:** 5.2.

## Phase 6 — First session, demo enrollment, repositories and curriculum (after the named TS batches)

- [ ] **6.1 Seed the first L4 session** (DEV-4)
  - **Do:** `L4FirstSessionSeeder`: `firstOrCreate` "L4 Accelerator Training — November 2026", 4 weeks, Mon 9 Nov 2026 to Sun 6 Dec 2026, on the L4 program, via `TrainingSessionService::create()`; called from `DatabaseSeeder` and the baseline command after the L4 seeder; add the production seed command to the release checklist.
  - **Acceptance:** Running it twice leaves one session with those dates; it passes the TS SES-1 rules.
  - **Tests:** New `tests/Feature/L4FirstSessionSeederTest.php`.
  - **Depends on:** 3.1, TS 2.2.

- [ ] **6.2 Enroll the demo participants with demo mentors** (DMO-2, DMO-3)
  - **Do:** `DevelopmentAccountProvisioner` (local, staging, testing only) adds the 3 demo participants and 3 demo mentors to the first session and assigns demo mentor *n* to demo participant *n* through TS `TrainingSessionService` and `MentorAssignmentService`; skips with a message when the session does not exist.
  - **Acceptance:** After provisioning, each demo participant sees the session under My trainings and has a current mentor; re-running changes nothing; production still refuses.
  - **Tests:** Extend `DevelopmentAccountProvisioningTest`.
  - **Depends on:** 6.1, 3.3, TS 5.1.

- [ ] **6.3 Ask for the repository URL on the session page** (LOG-2)
  - **Do:** Migration adding `repository_url` (nullable string 255) to `training_session_participants`; `App\Rules\GitHubRepositoryUrl`; `UpdateSessionRepositoryRequest` (member only); a form on the TS participant session page (prompt "Add your GitHub repository for this session" until set, then the link with a Change action); show the URL (or "No repository yet") on the mentor's submission review, scoring and gate checklist pages for that participant and session.
  - **Acceptance:** A member saves `https://github.com/ana/l4-accelerator` and sees it; `https://github.com/ana`, `https://github.com/ana/repo/tree/main` and non-GitHub URLs fail; a non-member gets 403; the mentor sees the link on review, scoring and checklist pages; Join stays one click.
  - **Tests:** New `tests/Unit/GitHubRepositoryUrlRuleTest.php`, `tests/Feature/SessionRepositoryUrlTest.php`; extend `CoachGateReviewPageTest`.
  - **Depends on:** 4.2, TS 4.5 (session page; submissions and gate reviews already carry their session from TS 4.3 and 4.4).

- [ ] **6.4 Swap in the real developer curriculum** (DEV-2) — **Blocked by Q1**
  - **Do:** Replace the placeholder definitions in `L4TrainingProgramSeeder` with the supplied curriculum (description, activities, deliverable fields, checklist items, scoring dimensions per activity); run it on staging; record which session resources (guides, VS Code hook setup) the admins must upload.
  - **Acceptance:** Re-running the seeder replaces placeholder content in place, deactivates removed placeholders, keeps existing submissions, and every week still has at least one checklist item.
  - **Tests:** Update `L4TrainingProgramSeederTest` expectations.
  - **Depends on:** 3.1, Q1.

## Out of scope / follow-ups

- The AI checker for GitHub deliverables, private repositories, the VS Code session-log hooks and any automatic session-log check (spec Out of scope).
- Program, week and activity editing UI; content for other job roles; bringing back mock interviews or an asset library (spec Out of scope).
- Root-level QA artefacts not used by the app: `QA_LAB_MASTER_PLAN.md`, `QA_Training_Working_Template.xlsx`, `training-plans/*.pptx`, `training-apps/membership-app`. Delete or archive by hand if the user wants.
- A bulk admin export of all uploads (DAT-1 relies on an ops archive instead).
- Linking checklist items to individual activities (TS Q2 trade-off).

- Deleting `AdoptSquashedMigrationsCommand` once production, staging and every shared database have adopted the squashed migrations.

## Open decisions

Problems found during implementation that need a decision. Starts empty.

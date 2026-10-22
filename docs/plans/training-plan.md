# Training Sessions Plan

Source spec: `docs/specs/training-plan.md` · Related spec: `docs/specs/developer-program.md` · Last updated: 2026-10-23

**Status legend:** `[x]` done · `[ ]` to do · `[~]` in progress · `[-]` deferred

## Goal

Admins schedule **training sessions** (dated runs of a program), staff them with participants, mentors and resources, and see a dashboard per session. Participants register with their employee ID, join any open session, and work through each session's weeks, which open by date and lock only after an *incomplete* mentor review. Today's single, global, active-program training becomes many concurrent sessions, each with its own submissions, scores and mentor scope.

## Rules for every batch

- Read `CLAUDE.md` **as it is on disk** before starting. It is newer than older copies you may have in context: enums in `app/Enums` are the convention for stored statuses and roles (code quality Phase 7 is done), Preflight is on, and the legacy CSS classes are gone. Follow its "Code Quality Rules" for every new or touched line.
- Keep each batch small and independently shippable: one concern, its tests, green suite. Behavior changes only where the item says so.
- Do only the batch you were given. Record new problems under "Open decisions" instead of fixing them on the spot.
- Definition of done: `vendor/bin/pint --dirty --format agent`, the full suite passing (`php artisan test --compact`), `npm run build` if CSS, JS or Tailwind classes changed, and the item ticked here (`[x]`, or `[~]` with a short note).
- `php` is not on the default PATH in agent shells: use `~/.config/herd-lite/bin/php`.
- **Gate A — developer program step 1.** Phases 1 and 2 can start now. Do not start Phase 3 or later until developer program plan batches 1.1 to 3.4 (its Phases 1 to 3: REM-1 to REM-4, REM-6, REM-7, DAT-1 to DAT-4, GEN-1 to GEN-4, DEV-1, DEV-2, DMO-1, DMO-3) are merged. Its REM-5 wording batches (5.1 to 5.3) and its migration squash (3.5) are not part of Gate A. Those batches delete most of the QA code that Phases 3 to 6 would otherwise have to rewrite (Validation Lab, Week 2 to 4 tools, daily logs, hardcoded week checks), and MIG-1 relies on its data deletion.
- **Gate B — open questions.** No item is blocked today (Q1 to Q3 were answered on 2026-10-23; see Assumptions). If a new question blocks an item, mark it "Blocked by Qn", skip it, and take the next unblocked item in the same phase.
- New migrations must run on MySQL (production) and SQLite (tests). See `database/migrations/2026_09_16_160000_fix_google_drive_evidence_export_sqlite_foreign_key.php` (in git history once developer program 3.5 deletes it) for the SQLite foreign-key trap this codebase has already hit.
- Developer program 3.5 replaces all existing migrations with one hand-written file per table. It squashes whatever migrations exist when it runs, including any from this plan's Phases 1 to 3 already merged. Migrations written after it are ordinary new files; never edit or delete the squashed files.
- New admin actions on sessions, participants, mentors and resources write an `AdminAuditLog` row through `AdminAuditService` (created in 2.2).
- New user-facing copy says "training session", "participant" and "mentor"; never "QA" or "coach" in new text.

## Requirements

Spec assumptions without an ID in the spec are listed as SA-1 to SA-4.

| ID | Requirement (from spec) | Covered by |
|---|---|---|
| SES-1 | Admin creates a session: program, title, weeks, start and end; end = start + weeks; weeks ≤ program weeks; overlaps allowed | 2.1, 2.2 |
| SES-2 | Admin edits title, weeks, dates under SES-1 rules; program locked once participants joined | 2.3 |
| SES-3 | Admin adds and removes participants; removal hides the session, keeps submissions and scores | 2.5, 4.2 |
| SES-4 | Admin adds, renames and removes resources (file or link), per week or session-wide | 2.6 |
| MEN-1 | Admin adds and removes session mentors | 5.1, 5.2 |
| MEN-2 | Admin assigns each participant one session mentor for the whole session; unassigned allowed | 5.1, 5.3 |
| MEN-3 | Admin changes a mentor mid-session; new mentor sees all work, previous loses access, history kept, past reviews stay attributed | 5.1, 5.3, 5.4 |
| MEN-4 | A mentor sees and reviews only participants currently assigned to them, in those sessions | 5.4 to 5.9 |
| ADM-1 | Admin dashboard lists 5 most recent sessions (title, program, dates, status, participant count), rows open the session dashboard, "View all sessions" paginated | 2.2, 2.7 |
| ADM-2 | Session dashboard with Overview, Participants, Mentors, Activities, Resources tabs, each label with its count; overview scoped to the session | 2.4, 7.1, 7.2, 7.3 |
| ADM-3 | Participants tab: name, employee ID, job role, current mentor, joined date; add and remove | 2.4, 2.5, 5.3 |
| ADM-4 | Mentors tab: manage mentors, assign and change mentors | 5.2, 5.3 |
| ADM-5 | Resources tab grouped by week with upload and remove | 2.6 |
| REG-1 | Register with employee ID, first and last name, job role, email, password; optionally join open sessions; job role informational and extensible | 1.1, 1.2, 4.8 |
| REG-2 | Unique `employee_id` on every user; required at registration; admins set or correct it; used in admin lists, searches, exports | 1.1, 1.2, 1.4, 1.6 |
| REG-3 | Login stays email and password | 1.2, 1.5 (regression tests) |
| REG-4 | No training path anywhere; one track; every requirement applies to everyone | 3.1, 3.2, 3.3, 3.4 |
| PAR-1 | Landing page: My trainings, Available trainings (with Join), Past trainings | 4.5, 4.7 |
| PAR-2 | Join is open to all, immediate, idempotent; not for completed sessions | 4.7 |
| PAR-3 | Several sessions at once; submissions, scores and progress tracked per session | 4.1, 4.3, 4.4, 4.9, 4.10 |
| PAR-4 | Session details page: one tab per week plus a Resources tab | 4.2, 4.5, 4.6, 6.6 |
| PAR-5 | Completed sessions stay viewable, submissions closed, except activities a mentor or admin reopens | 6.4, 6.6 |
| PAR-6 | Participants open only sessions they joined; other session pages and files return 403 | 4.2, 4.6 |
| WK-1 | Week N opens on start + (N − 1) weeks, with its activities, deliverables and resources, without passing earlier gates | 6.1, 6.6 |
| WK-2 | An *incomplete* week review locks every later week for that participant | 6.1 |
| WK-3 | Later weeks unlock when the mentor (or an admin) marks the week *completed*; mentors see a list of locked weeks with new submissions | 6.1, 6.5 |
| WK-4 | Locked and unopened weeks still show as tabs and explain why ("Opens on …", missing deliverables, "Waiting for your mentor …"); earlier weeks stay open | 6.2, 6.3 |
| MIG-1 | No QA data to migrate; admin and coach accounts kept | Gate A, 4.1 (empty-table guard) |
| MIG-2 | Remove training paths from users, requirements, screens, filters and exports | 3.1 to 3.4 |
| MIG-3 | Existing users without an employee ID must add one at next login; admins can fill it in | 1.4, 1.5 |
| SA-1 | Sessions reuse a program's weeks and activities; no program editing UI | 2.1, Out of scope |
| SA-2 | Only admins manage sessions; mentors see their sessions read-only | 2.2 (policy), 5.9 |
| SA-3 | Uploads: PDF, Office, images, MP4, ≤ 100 MB; other videos as links | 2.6 |
| SA-4 | Admin changes to sessions, participants, mentor assignments and resources go to the admin audit log | 2.2, 2.3, 2.5, 2.6, 5.2, 5.3 |

## Current state

- **One global program.** `Program` (`app/Models/Program.php`, `is_active`) has `TrainingWeek`s (`week_number`, `position`), each with `Activity`s and `TrainingGate`s. About 15 places load "the" active program with `Program::query()->where('is_active', true)` (e.g. `ParticipantDashboardController`, `AdminDashboardPageService`, `CoachDashboardPageService`, `CoachReportService`, `MissingSubmissionReportService`, `ScoringPageService`, `ScoringReportController`, `ExportParticipantScoreSummaryRequest`, `ParticipantNotificationService`).
- **Per-user, not per-session, progress.** `activity_submissions` (`activity_id`, `user_id`, `attempt_number`), `gate_evaluations` (unique `training_gate_id` + `user_id`) and `weekly_video_recordings` (unique `user_id` + `training_week_id`) have no session. Versions, evidence, reviews, scores and recovery requests hang off `activity_submissions`. Attendance (`attendance_sessions`) is a global check-in/check-out per user.
- **Gate-chain unlocking.** `WeekProgressionService::canAccessWeek()` opens a week only when every earlier week's gates are passed. It also drives "current week" and the attendance requirement (`requiresAttendanceForActivity`).
- **Participant dashboard = today's "session page".** `ParticipantDashboardController` + `ParticipantDashboardService` + `resources/views/participant/dashboard.blade.php` render one tab per week (`x-ui.tab-list` / `x-ui.tab-panel`, `initializePageTabs` in `resources/js/app.js`) with activities, gate and video cards.
- **Routes are week-scoped, not session-scoped**: `training/weeks/{trainingWeek}/…` for weeks, activities, submissions, evidence upload, gate page, video; `training/coach/weeks/{trainingWeek}/participants/{participant}/gates/{trainingGate}` for gate review. `bootstrap/app.php` matches `training/weeks/*/video-recording` for its `PostTooLargeException` handler.
- **Global coach assignments.** `coach_participant_assignments` (`CoachParticipantAssignment`, `User::assignedCoaches()` / `assignedParticipants()`), edited on the admin user form (`AdminUserController@assignments`, `UpdateParticipantAssignmentsRequest`). `User::canManageParticipant()` gates every coach write (policies `SubmissionVersionPolicy`, `GateEvaluationPolicy`; Form Requests `StoreSubmissionReviewRequest`, `StoreActivityScoreRequest`, `StoreSecondReaderScoreRequest`, `StoreGateReviewRequest`, `DecideRecoveryRequestRequest`, `ImproveScoringFeedbackRequest`; `GateReviewController`, `ScoringController`, `WeeklyVideoRecordingController`). Coach list pages show **every** participant, marking "Assigned to me".
- **Users.** `users` has `name`, `email`, `role` (`UserRole`), `training_path` (`TrainingPath`), `drive_link`, `is_active`. No employee ID, first/last name or job role. Registration (`RegisteredUserController`, `StoreRegisteredUserRequest`, `auth/register`) asks name, email, password, training path. Admin user form maps an "access type" (`admin`, `coach`, `qa_manual`, `qa_automation`, `qa_automation_manual`) to role + path. Login (`AuthenticatedSessionController`) redirects by role.
- **Training paths** are referenced in ~60 app, view, factory and seeder files and ~50 tests (`grep -rln "training_path\|TrainingPath\|participant_path" app resources database tests`), many inside QA features that developer program step 1 deletes.
- **Audit log.** `admin_audit_logs` (`actor_id`, nullable `target_user_id`, `action`, JSON `metadata`). Written inline by `AdminUserController::audit()`, `AdminAiSettingsController`, `AdminGoogleDriveController`, `BootstrapAdminCommand`.
- **Files.** Private `local` disk; size and type limits in `config/training.php`; evidence/video serving in `SubmissionEvidenceController` and `WeeklyVideoRecordingController`; rules in `App\Services\EvidenceFilePolicy`.
- **Drive sync.** `SyncTrainingDataJob` and `TrainingWorkbookService` build one workbook per `week_number` across all activities with that number, so two sessions would mix.
- **Sidebar** links come from `App\Services\SidebarNavigationService`.

### Dependency on the developer program spec

- That spec is planned in `docs/plans/developer-program.md` ("DP" below); none of it is implemented yet (QA features, daily logs and `QaTrainingProgramSeeder` are all present; `database/seeders/L4TrainingProgramSeeder.php` is an empty, untracked file).
- Its order of work is: (1) removal and generalization, (2) this spec, (3) first session and demo enrollment. This plan follows that order through Gate A, with one exception: **DMO-1 (demo users with employee ID, first/last name, job role) needs this plan's Phase 1**, so run Phase 1 before developer program step 1.
- This plan owns training-path removal (its MIG-2, which the developer program spec delegates here) and replacing active-program lookups with the session being viewed (developer program GEN-4, second sentence).
- WK-2 / WK-3 use the week review outcomes *completed* and *incomplete* that developer program LOG-3 / LOG-4 (weekly checklist) define. LOG-1 to LOG-4 and DEV-5 are not placed in that spec's order of work; the DP plan schedules them as DP 4.1 to 4.3 (DEV-5, LOG-1, LOG-3, LOG-4) and DP 6.3 (LOG-2). Until DP 4.3 lands, this plan maps the outcomes onto today's gate review (see Assumptions).
- Developer program LOG-2 (repository URL "when a participant joins a session"): Join stays one click here (Q1); the developer program plan asks for the URL afterwards, on the session page. This plan adds no repository column.
- **Every week needs a checklist.** WK-2 / WK-3 locking, the WK-4 "missing deliverables" list and early resource unlocking (Q3) all read the week's checklist review, so developer program LOG-3 must give every program week at least one checklist item (one active gate with at least one required criterion). The spec does not state it; the DP plan enforces it (DP 3.1 seeder guard, DP 4.2 check). A week without a checklist can never be marked incomplete or completed, so it never locks later weeks and never unlocks the next week's resources early.
- Developer program DEV-4 (seed the first session, DP 6.1) needs 2.2 here; DMO-2 (demo enrollment with mentors, DP 6.2) needs 5.1; LOG-2 (DP 6.3) needs 4.5. DMO-1 (DP 3.3) needs 1.1, and DP 1.2 (home page redirect) uses the role landing mapping from 1.5.

### Overlaps with `docs/CODE_QUALITY_PLAN.md`

- Phase 7 "Production values" check is still open and blocks deploying main; this plan's work ships behind it too.
- Open decisions about training-path text (" QA" suffix), the daily-log week filter, Week 4 and Lab items become moot once Phase 3 and developer program step 1 land; tick or close them then, do not fix them here.
- "Coach who is not assigned sees the Gate review read only" and "COMPLETED tile unlinked" are changed by 5.4, 5.7 and 5.9 (unassigned mentors get 403). Note the outcome in that plan when those batches land.
- "Review queue participant filter is a select of every participant" also applies to the add-participant control in 2.5; keep the select for now (see Out of scope).

## Design

### Data model

| Table | Columns | Notes |
|---|---|---|
| `users` (changed) | `employee_id` string(50) nullable unique; `first_name`, `last_name` string nullable; `job_role` string nullable (`JobRole`) | `name` stays and is kept equal to "first last" whenever first or last name changes, so every existing screen, sort and export keeps working. `training_path` dropped in 3.4. |
| `training_sessions` | `id`, `program_id` FK (restrict), `title`, `weeks_count` unsigned small int, `starts_on` date, `ends_on` date, `created_by` FK users nullable (null on delete), timestamps; index `starts_on` | Status is derived, never stored. |
| `training_session_participants` | `id`, `training_session_id` FK (cascade), `user_id` FK (cascade), `joined_at`, `added_by` FK users nullable (null = joined themselves), timestamps; unique (`training_session_id`, `user_id`); index `user_id` | Removal deletes the row; work rows keep their `training_session_id`, so re-adding restores access. |
| `training_session_mentors` | `id`, `training_session_id`, `user_id`, timestamps; unique pair | Coaches only. |
| `mentor_assignments` | `id`, `training_session_id`, `participant_id`, `mentor_id`, `assigned_by` nullable, `started_at`, `ended_at` nullable, timestamps; index (`training_session_id`, `participant_id`, `ended_at`), (`mentor_id`, `ended_at`) | Current assignment = `ended_at` null; at most one per participant per session, enforced in `MentorAssignmentService` inside a transaction. Ended rows are the history. |
| `training_session_resources` | `id`, `training_session_id` FK (cascade), `week_number` nullable, `title`, `type` (`TrainingSessionResourceType`: `file`, `link`), `url` nullable text, `disk`, `path`, `original_filename`, `mime_type`, `size_bytes` (all nullable, set for files), `uploaded_by` nullable, timestamps; index (`training_session_id`, `week_number`) | `week_number` null = whole session. |
| `activity_submissions`, `gate_evaluations`, `weekly_video_recordings` (changed) | + `training_session_id` FK (restrict) not null | Unique keys become (`training_session_id`, `training_gate_id`, `user_id`) and (`training_session_id`, `user_id`, `training_week_id`); submissions indexed (`training_session_id`, `user_id`, `activity_id`, `attempt_number`). Versions, evidence, reviews, scores and recovery requests inherit the session through the submission. |

Enums (`app/Enums`, made with `php artisan make:enum … --string`): `JobRole` (`ProjectManager` = `project_manager` "Project Manager (PM)", `QaTester` = `qa_tester` "QA Tester", `Developer` = `developer` "Developer"; `label()`, `options()`; adding UI/UX Designer later is one case). `TrainingSessionStatus` (`Upcoming`, `Active`, `Completed`; derived, with `label()` and `tone()` for `x-ui.badge`). `TrainingSessionResourceType` (`File`, `Link`).

`TrainingSession` model: relations `program`, `participants` (belongsToMany `User`, pivot `joined_at`, `added_by`), `mentors`, `mentorAssignments`, `resources`; `status()`, `isCompleted()`; scopes `notCompleted()` (`ends_on >= today`) and `completed()`; `weeks()` (the program's weeks with `week_number <= weeks_count`); `includesWeek(TrainingWeek)`; `weekOpensOn(int $weekNumber)` (`starts_on + (N − 1) weeks`). "Today" is `today()` in the app timezone (`Asia/Manila`).

### Rules

- `ends_on = starts_on + weeks_count × 7 − 1 day` (4 weeks from Mon 2 Nov 2026 → Sun 29 Nov 2026; 4 weeks from Mon 9 Nov → Sun 6 Dec).
- Status: *Upcoming* if `starts_on > today`, *Completed* if `ends_on < today`, else *Active*.
- Week *N* is open from `weekOpensOn(N)`. It is locked for a participant when an earlier week of the same session has an *incomplete* review that has not since become *completed* (Phase 6).

### Roles and permissions

- `TrainingSessionPolicy`: `viewAny`, `create`, `update`, `manage` (participants, mentors, resources) → admin. `view` → admin; a participant in the session; a coach who is a mentor of the session (from 5.4; until then any coach, as today). `join` → active participant, not in the session, session not completed.
- `TrainingSessionResourcePolicy::view` → `view` on its session (plus the date-or-previous-checklist rule for week resources from 6.6).
- Mentor scope (5.4): `User::mentorsParticipantInSession(User $participant, TrainingSession $session)` → admin, or a coach holding the current `mentor_assignments` row. Records carry their session (`ActivitySubmission`, `GateEvaluation`, `WeeklyVideoRecording`), so every coach check resolves the session from the record.

### Routes (names keep their current form where they exist)

- Admin (`role:admin`, prefix `admin`): `admin.sessions.index|create|store|show|edit|update` (`admin/sessions…`); `admin.sessions.participants.store|destroy`; `admin.sessions.mentors.store|destroy`; `admin.sessions.assignments.update` (`PUT admin/sessions/{trainingSession}/participants/{participant}/mentor`); `admin.sessions.resources.store|update|destroy`.
- Account: `account.employee-id.edit|update` (`account/employee-id`).
- Participant: `dashboard` (landing), `training.sessions.show` (`training/sessions/{trainingSession}`), `training.sessions.join` (POST), `training.sessions.resources.show` (`…/resources/{resource}`, also used by mentors and admins).
- Existing `training/weeks/{trainingWeek}/…` routes move under `training/sessions/{trainingSession}/weeks/{trainingWeek}/…`; coach gate review moves to `training/coach/sessions/{trainingSession}/weeks/{trainingWeek}/participants/{participant}/gates/{trainingGate}`. A small middleware (alias e.g. `session.week`) returns 404 unless `$trainingSession->includesWeek($trainingWeek)`, and `can:view,trainingSession` guards the group.

### Main services and screens

- `AdminAuditService::record(User $actor, string $action, ?User $target = null, array $metadata = [])`; session actions put `training_session_id` in `metadata`. Actions: `training_session_created`, `training_session_updated`, `session_participant_added`, `session_participant_removed`, `session_mentor_added`, `session_mentor_removed`, `session_mentor_assigned`, `session_resource_added`, `session_resource_renamed`, `session_resource_removed`.
- `TrainingSessionService` (create, update, add/remove participants, join), `MentorAssignmentService`, `TrainingSessionResourceService`, `AdminTrainingSessionPageService` (list, dashboard tabs, form), `ParticipantTrainingSessionPageService` (landing lists, session details).
- Controllers: `AdminTrainingSessionController`, `AdminTrainingSessionParticipantController`, `AdminTrainingSessionMentorController`, `AdminTrainingSessionResourceController`, `TrainingSessionController` (participant show, join), `TrainingSessionResourceController` (file/link), `EmployeeIdController`.
- Views: `resources/views/admin/sessions/{index,form,show}.blade.php`, `resources/views/training-sessions/show.blade.php` (participant details), `resources/views/participant/dashboard.blade.php` (landing), `resources/views/account/employee-id.blade.php`. Use `x-ui.*` components and theme tokens; tabs via `x-ui.tab-list` / `x-ui.tab-panel` inside a `data-page-tabs` container; redirects after tab actions return to the tab's hash (e.g. `#resources`).

## Assumptions

- **First and last name** are new columns; `name` stays as their combination, kept in sync on save. Existing users are backfilled by splitting `name` at the first space (one-word names → last name empty). — REG-1 asks for both; keeping `name` avoids touching every screen and sort.
- **Employee ID** is free text, trimmed, max 50 characters, unique, no format rule. Users cannot change it themselves once set; admins can. — The spec gives no format; tighten later if the company format is known.
- **Admin-created users** must have an employee ID; on admin edit it stays optional (so legacy users can still be edited) but unique. — REG-2 "every user has one"; MIG-3 covers legacy accounts.
- **The employee ID prompt (MIG-3) applies to every role**, including admins and coaches. — REG-2 says every user.
- **Job role** is required at registration for participants, optional and admin-editable for coaches and admins, and read-only on the profile. — REG-1 says it is informational.
- **End date** is inclusive: start + weeks × 7 − 1 day. — Matches the spec's example; the literal "start plus weeks" would end on a Monday.
- **No weekday rule** on the start date, and **past dates are allowed** when creating or editing. — The spec sets none; admins may record past runs.
- **Weeks** count the program's active weeks; session week *N* is the program week with `week_number` N. Lowering a session's weeks hides later weeks but keeps their data. — Developer program GEN-2 numbers weeks from 1.
- **Programs offered** in the create form are active programs only.
- **Mentors are coaches only**; admins act on every session without being mentors. — Spec Terms.
- **Removing a mentor** from a session ends that mentor's current assignments there; those participants become unassigned. **Removing a participant** ends their current assignment.
- **Admins can add participants to any session**, including completed ones; only self-joining is limited to non-completed sessions. — PAR-2 restricts participants, not admins.
- **Attendance stays global** (one check-in per user, not per session), and the attendance card stays on the participant landing page with its heading renamed from "TRAINING SESSION" to "ATTENDANCE" to avoid a clash with the new term.
- **Review outcomes.** Until developer program LOG-4 (DP 4.3) changes the gate review, *completed* = `GateEvaluationStatus::Passed` and *incomplete* = `NeedsRevision` or `Failed`; an admin marks a week completed through the same gate review form (WK-3). DP 4.3 keeps these stored values, relabels them "Completed" / "Incomplete" and stops offering `Failed`; revisit in 6.1 only if that changed.
- **"Marks an activity as incomplete" (PAR-5)** is the existing review decision *Needs revision* (`SubmissionStatus::NeedsRevision`). In a completed session only attempts in that status can be revised and resubmitted.
- **Activities tab** lists the activities of the session's weeks with per-activity counts for the session's participants (not started, awaiting review, needs revision, approved). Its tab count is the number of activities. — The spec names the tab but not its content.
- **Overview tab** shows today's admin dashboard training and review counts, scoped to the session (participants, mentors, participants with work per week, awaiting review, needs revision, pending gates). Week 4 interview and daily-log counts are gone after developer program step 1.
- **Mentors see their sessions** as a "My sessions" card on the coach dashboard plus a session filter on coach pages; there is no separate mentor session page. — SA-2 asks only that they see them.
- **Exports** add an "Employee ID" column next to the name; session-scoped exports (Phase 5) also add a "Training session" column.
- **No session deletion.** Not in the spec; sessions are only created and edited.

### Answered questions (2026-10-23)

- **Q1 — Join stays one click (option A).** PAR-2 and REG-1 work as written: Join and registration add the membership immediately, with no extra form. The developer program plan asks for the GitHub repository URL later, on the session page (LOG-2). Affects 4.7, 4.8.
- **Q2 — Checklist items belong to their week, not to an activity.** "Missing deliverables" are the week's required checklist items marked not met in the *incomplete* review, listed by title and linking to that week (its tab and activities). "Submitted after an incomplete review" means **at least one** activity version in that week, in that session, submitted after the review's `evaluated_at`. A new incomplete review resets the signal. **Trade-off:** resubmitting *any* activity in the week counts, even one unrelated to the missing item, so "Waiting for your mentor" and the mentor's waiting list can appear before the real gap is fixed; the mentor still verifies by hand before marking the week completed. This relies on every session week having at least one activity (the user confirmed it does) and at least one checklist item (dependency on developer program LOG-3, see "Dependency on the developer program spec"). Affects 6.3, 6.5.
- **Q3 — Week resources unlock by date or by the previous week's checklist.** In a session that is not completed (active or upcoming), a participant sees a week's resources once that week has opened by date (WK-1) **or** once their review of the previous week is *completed*; the second case can unlock them before the week's start date. Week 1 follows its open date only. Session-wide resources (no week) are always visible. In a completed session every resource is visible (PAR-5). Mentors and admins always see every resource. Affects 4.6, 6.6.
  - **Interim:** 4.6 ships "show every resource to members" and 6.6 adds the week rule on top. This is the simpler order: 4.6 needs no week-state logic, and 6.6 only tightens one policy method and the tab rendering once 6.1 provides the week states. Gate A and the deploy block mean participants should not see the gap. If the app does reach participants between 4.6 and 6.6, week resources are visible early.
  - **Assumption from this answer:** the resource rule is date-or-checklist only, so a week whose date has passed keeps its resources visible even while an *incomplete* review of an earlier week locks its activities (WK-2). Locking hides the week's activities and deliverables, not its resources. Confirm with the user if locked weeks should also hide resources.
  - **Assumption from this answer:** unlocking resources early does not unlock the week's activities early; activities still follow WK-1 dates.

## Open questions

None open.

## Phase 1 — User identity (no gate; run before developer program DMO-1, DP 3.3)

- [ ] **1.1 Add employee ID, first and last name, and job role to users** (REG-1, REG-2; prerequisite for 1.2 to 1.6 and developer program DMO-1, DP 3.3)
  - **Do:** Migration adding `employee_id` (string 50, nullable, unique), `first_name`, `last_name` (nullable strings), `job_role` (nullable string) to `users`, and backfilling first/last name from `name` (split at the first space, query builder, chunked). Create `App\Enums\JobRole` (see Design). On `User`: fillable, `job_role` cast, a `saving` hook that sets `name` to the trimmed "first last" when either is dirty. `UserFactory`: fill the new fields (unique `employee_id`, `job_role` Developer) without changing existing tests' behavior.
  - **Acceptance:** Migrating up and down works on SQLite and MySQL. A user saved with first "Ana" and last "Cruz" has `name` "Ana Cruz"; changing only `name` leaves first/last untouched. Two users with the same `employee_id` cannot be saved; two users with none can. Existing `name`-based screens are unchanged.
  - **Tests:** New `tests/Feature/UserIdentityTest.php`: name sync, unique employee ID (database error), backfill split ("Ana Maria Cruz" → "Ana" / "Maria Cruz"; "Prince" → "Prince" / empty), `JobRole::options()` order and labels.

- [ ] **1.2 Ask employee ID, names and job role at registration** (REG-1, REG-2, REG-3)
  - **Do:** `StoreRegisteredUserRequest`: add `employee_id` (required, string, max 50, unique), `first_name`, `last_name` (required, max 255), `job_role` (required, `Rule::enum(JobRole::class)`); replace `name`. Keep `training_path` until 3.2. `RegisteredUserController`: pass `JobRole::options()`; store the validated fields. `resources/views/auth/register.blade.php`: new fields with labels and errors, old input kept.
  - **Acceptance:** A guest registering with all fields lands where they do today with `name` "First Last" and the job role stored. A taken employee ID shows "The employee ID has already been taken." and creates nothing. Missing employee ID or job role fails validation. Login with email and password is unchanged.
  - **Tests:** Update `AuthPagesTest` / `AuthenticationAuthorizationTest` registration cases; add cases for duplicate employee ID, missing job role, invalid job role; keep one email+password login regression case.

- [ ] **1.3 Edit first and last name on the profile; show employee ID and job role** (REG-1, REG-2)
  - **Do:** `UpdateProfileRequest`: replace `name` with `first_name`, `last_name` (keep email and, until 3.2, `training_path` rules identical). `ProfileController` / `ProfilePageService` / `resources/views/profile/show.blade.php`: first/last name inputs; employee ID and job role shown read-only ("Not set" when empty).
  - **Acceptance:** Saving the profile with new first/last name updates `name`. Posting `employee_id` or `job_role` to the profile changes nothing. The page shows both values.
  - **Tests:** Update `ProfilePageTest`; add "profile ignores employee_id and job_role" case.

- [ ] **1.4 Let admins set employee ID, names and job role; list and search by employee ID** (REG-2, MIG-3, prerequisite for ADM-3)
  - **Do:** `StoreAdminUserRequest` / `UpdateAdminUserRequest`: `employee_id` (required on store, nullable on update, unique ignoring the user), `first_name`, `last_name` (replace `name`), `job_role` (nullable enum). `AdminUserController` store/update and `AdminUserPageService::formData()` / `indexData()`; `resources/views/admin/users/form.blade.php` and `index.blade.php`: inputs, an Employee ID column, and search matching `employee_id` as well as name and email (`FilterAdminUsersRequest` unchanged). Audit metadata records that the employee ID changed (not its value).
  - **Acceptance:** An admin can create a coach with an employee ID and correct it later; a duplicate shows a validation error and nothing is saved. Searching the user list for an employee ID finds that user. Non-admins still get 403.
  - **Tests:** Update `AdminUserManagementTest`, `AdminUserFormPageTest`, `AdminUserListPageTest`: create with/without employee ID, duplicate on update, search by employee ID, column shown.

- [ ] **1.5 Require an employee ID on next login** (MIG-3, REG-3)
  - **Do:** Middleware `EnsureEmployeeIdIsSet` (alias in `bootstrap/app.php`) on the `auth` route group: a signed-in user without `employee_id` is redirected to `account.employee-id.edit`, except on that route, `account.employee-id.update` and `logout`. `EmployeeIdController` (edit/update), `UpdateEmployeeIdRequest` (required, max 50, unique), view `resources/views/account/employee-id.blade.php` (plain form, `x-ui.card`, `x-ui.button`). After saving, redirect to the role landing page; move the role → route mapping out of `AuthenticatedSessionController::store()` into one shared place (e.g. a `User` method) and use it in both.
  - **Acceptance:** A coach with no employee ID who logs in is sent to the form; any other page (e.g. `/coach`) redirects there too; they can log out. After a valid save they land on their dashboard. A duplicate ID shows an error. Users with an ID never see the form. Requests expecting JSON (e.g. draft autosave) get 403 with "Add your employee ID to continue." instead of a redirect.
  - **Tests:** New `tests/Feature/EmployeeIdPromptTest.php`: redirect after login, redirect from another page, logout allowed, save and continue, duplicate rejected, user with ID unaffected.

- [ ] **1.6 Show employee IDs in exports** (REG-2)
  - **Do:** Add an "Employee ID" column right after the participant name in the CSV exports in `AttendanceController`, `CoachOperationsController` (progress, reviews, gates), `MissingSubmissionReportController` and `ScoringReportController` (scores, participant summary). Skip daily-log exports (removed by developer program REM-7). Eager-load nothing new; the column is on `users`.
  - **Acceptance:** Each CSV has the new header and the participant's employee ID (empty when not set) in every row.
  - **Tests:** Update the header/row assertions in `AttendanceTest`, `CoachReportsTest`, `MissingSubmissionReportPageTest`, `ScoringReportControllerTest`.

## Phase 2 — Training sessions: admin setup (no gate; after Phase 1)

- [ ] **2.1 Add the TrainingSession model and session participants** (SES-1, SES-3, SA-1; prerequisite for the rest)
  - **Do:** Migrations for `training_sessions` and `training_session_participants` (see Design). Models `TrainingSession` (relations, `status()`, `isCompleted()`, scopes, `weeks()`, `includesWeek()`, `weekOpensOn()`) and `TrainingSessionParticipant` pivot if useful; `User::trainingSessions()`; `Program::trainingSessions()`. Enum `TrainingSessionStatus`. `TrainingSessionFactory` with `upcoming()`, `active()`, `completed()` states (dates relative to `today()`).
  - **Acceptance:** Status is Upcoming/Active/Completed exactly at the boundaries (start today → Active; end today → Active; end yesterday → Completed). `weeks()` returns program weeks 1..N only. `weekOpensOn(2)` for a 9 Nov start is 16 Nov.
  - **Tests:** New `tests/Feature/TrainingSessionModelTest.php` (uses `travelTo`): status boundaries, `weeks()`, `includesWeek()` for a week of another program, `weekOpensOn()`.

- [ ] **2.2 Add the admin session list and create form** (SES-1, ADM-1 "View all", SA-2, SA-4)
  - **Do:** `TrainingSessionPolicy` (admin abilities; see Design). `AdminTrainingSessionController@index|create|store`, `StoreTrainingSessionRequest` (`program_id` exists and active; `title` required max 255; `weeks_count` integer 1..number of the program's active weeks with message "The program has only N weeks."; `starts_on` date; `ends_on` date equal to `starts_on + weeks × 7 − 1` with message "The end date must be N weeks after the start date (DD Mon YYYY)."). `TrainingSessionService::create()`. `AdminAuditService` (new) records `training_session_created`. `AdminTrainingSessionPageService` index rows: title, program, date range ("9 Nov – 6 Dec 2026"), status badge, participant count (`withCount`), paginated (20), newest start first. Views `admin/sessions/index` and `admin/sessions/form`. JS initializer `initializeSessionEndDate` in `resources/js/app.js` fills `ends_on` from `starts_on` and `weeks_count` (server stays the source of truth). Sidebar link "Training Sessions" for admins in `SidebarNavigationService`.
  - **Acceptance:** An admin creates "L4 Accelerator Training — November 2026", 4 weeks, 9 Nov to 6 Dec 2026, and sees it in the list. End 7 Dec → validation error, nothing saved. 5 weeks for a 4-week program → error. Two overlapping sessions of the same program both save. Participants and coaches get 403 on every route. An audit row exists.
  - **Tests:** New `tests/Feature/AdminTrainingSessionTest.php`: create happy path, mismatched end date, too many weeks, inactive program, overlap allowed, authorization (participant, coach, guest), pagination order, audit row.

- [ ] **2.3 Edit a session** (SES-2, SA-4)
  - **Do:** `AdminTrainingSessionController@edit|update`, `UpdateTrainingSessionRequest` (same rules as store; `program_id` must equal the current one once the session has participants: "The program cannot change after participants have joined."). Shared form view with the program select disabled (and explained) when locked. Audit `training_session_updated` with the changed field names.
  - **Acceptance:** Title, weeks and dates update under SES-1 rules. With one participant, a changed `program_id` fails and nothing is saved; without participants it saves.
  - **Tests:** Extend `AdminTrainingSessionTest`: update happy path, mismatched dates, program change with and without participants, authorization.

- [ ] **2.4 Add the session dashboard with tabs and the Participants tab** (ADM-2, ADM-3)
  - **Do:** `AdminTrainingSessionController@show`, view `admin/sessions/show` with header (title, program, dates, status, Edit link) and tabs Overview, Participants (n), Mentors (n), Activities (n), Resources (n) using `x-ui.tab-list`. Overview shows the header facts only (filled in 7.1); Mentors and Resources show their empty states until 5.2 and 2.6; Activities lists the session weeks' activities (week, title) until 7.2. Participants tab: name, employee ID, job role label, joined date (current mentor column added in 5.3), one eager-loaded query.
  - **Acceptance:** Tab labels show live counts (e.g. "Participants (12)"). Participant rows show the listed fields. Hash links open the right tab. Non-admins get 403; an unknown session 404.
  - **Tests:** New `tests/Feature/AdminTrainingSessionDashboardTest.php`: tab labels with counts, participant row fields, authorization, query count stays flat with more participants.

- [ ] **2.5 Add and remove session participants** (SES-3, ADM-3, SA-4)
  - **Do:** `AdminTrainingSessionParticipantController@store|destroy`; `StoreTrainingSessionParticipantsRequest` (`user_ids` array of active participants not already in the session); `TrainingSessionService::addParticipants()` / `removeParticipant()` (sets `added_by`, `joined_at`). Participants tab: multi-select labelled "Name (Employee ID)" and a Remove button with the existing confirmation initializer. Audit `session_participant_added` / `session_participant_removed` per user. Return to `#participants`.
  - **Acceptance:** Adding two participants lists both with today's joined date; adding someone already in the session or a coach fails validation. Removing a participant deletes only the membership row; their (later) submissions are kept (assert in 4.3 tests).
  - **Tests:** Extend `AdminTrainingSessionDashboardTest` or new `AdminTrainingSessionParticipantTest`: add, duplicate, wrong role, inactive user, remove, authorization, audit rows.

- [ ] **2.6 Manage session resources** (SES-4, ADM-5, SA-3, SA-4)
  - **Do:** Migration + model `TrainingSessionResource`, enum `TrainingSessionResourceType`, factory. Config in `config/training.php`: `session_resource_max_kb` (102400) and allowed extensions/MIME types (pdf, doc, docx, xls, xlsx, ppt, pptx, png, jpg, jpeg, gif, webp, mp4). `StoreTrainingSessionResourceRequest` (`title` required; `type`; `file` required for files with those rules; `url` required http(s) for links; `week_number` nullable 1..`weeks_count`), `UpdateTrainingSessionResourceRequest` (title only). `TrainingSessionResourceService` stores files on the private `local` disk under `training-sessions/{id}/resources`, deletes them on removal. `AdminTrainingSessionResourceController@store|update|destroy`; `TrainingSessionResourceController@show` (admin-only for now; opened to members in 4.6) streams files inline or redirects to links. Resources tab grouped "Whole session", "Week 1", …, each row with type, size, Rename (inline form) and Remove. Add `admin/sessions/*/resources` to the `PostTooLargeException` handler in `bootstrap/app.php` with "The file must not exceed 100 MB." Audit add/rename/remove.
  - **Acceptance:** An admin uploads a 2 MB PDF to Week 1 and adds a YouTube link to the whole session; both appear grouped. A `.exe` or a 101 MB file fails validation. Week 5 on a 4-week session fails. Rename changes only the title. Remove deletes the row and the stored file. Non-admins get 403 on all write routes.
  - **Tests:** New `tests/Feature/AdminTrainingSessionResourceTest.php` with `Storage::fake('local')`: upload, link, invalid type, too large, week out of range, rename, remove (file gone), grouping on the tab, authorization, audit rows.

- [ ] **2.7 Show recent sessions on the admin dashboard** (ADM-1)
  - **Do:** `AdminDashboardPageService::showData()` adds `recentSessions`: the 5 sessions with the latest `starts_on` (with program and participant count), same row data as the list; `resources/views/admin/dashboard.blade.php` renders a "Training sessions" card with linked rows to `admin.sessions.show`, a "View all sessions" link and an empty state with a "Create session" link.
  - **Acceptance:** With 6 sessions the card shows the 5 newest by start date, newest first, each with title, program, dates, status and participant count; clicking opens its dashboard. With none it shows the empty state.
  - **Tests:** Update `AdminDashboardPageTest`: 6 sessions → 5 rows in order, link targets, empty state.

## Phase 3 — Remove training paths (Gate A)

- [ ] **3.1 Apply every activity requirement to every participant** (REG-4, MIG-2)
  - **Do:** Remove `participant_path` / `eligibleTrainingPaths()` filtering everywhere requirements are read (e.g. `SubmissionWorkflowService::fieldDefinitions()` / `evidenceRequirements()`, `ActivitySubmissionPageService`, `TrainingActivityPageService`, `SubmissionEvidenceService`, `SubmissionAnswerPresenter`; find the rest with `grep -rn "participant_path\|eligibleTrainingPaths" app`). Seeders and factories stop writing `participant_path`.
  - **Acceptance:** A participant with no path sees and must satisfy every requirement of an activity, including ones that had a path. Coaches see the same list.
  - **Tests:** Update the affected tests (`SubmissionFormPageTest`, `TrainingActivityPageTest`, `ParticipantWorkflowTest`, …); add one case: a requirement formerly tied to a path is required for a participant without a path.
  - **Depends on:** Gate A.

- [ ] **3.2 Remove the path from registration, profile, admin user forms and commands** (REG-4, MIG-2)
  - **Do:** Drop `training_path` from `StoreRegisteredUserRequest`, `UpdateProfileRequest`, `auth/register`, `profile/show`, `ProfilePageService`. Admin user access types become `admin`, `coach`, `participant` (`StoreAdminUserRequest`, `UpdateAdminUserRequest`, `FilterAdminUsersRequest`, `AdminUserController::mapAccessType()` → role only, `AdminUserPageService`, `admin/users/*` views). `BootstrapAdminCommand`, `DevelopmentAccountProvisioner`, `DatabaseSeeder` stop setting paths.
  - **Acceptance:** No form or admin filter shows or accepts a path. Old `qa_*` access types fail validation. An admin can still create participants, coaches and admins.
  - **Tests:** Update `AuthPagesTest`, `ProfilePageTest`, `AdminUser*Test`, `BootstrapAdminCommandTest`, `DevelopmentAccountProvisioningTest`.
  - **Depends on:** 3.1.

- [ ] **3.3 Remove path filters and columns from lists, reports and exports** (MIG-2)
  - **Do:** Remove the `path` filter and path column/label from every remaining Filter Request (`FilterCoach*Request`, `FilterAttendanceSessionsRequest`, `FilterMissingSubmissionsRequest`, `FilterReviewQueueRequest`, `FilterScoring*Request`, `ExportCoachReportRequest`) and their page services, views and CSVs (`trainingPathLabel()` callers).
  - **Acceptance:** No page, filter or CSV mentions Manual/Automation; old `?path=` query strings are ignored or rejected consistently (state which in tests).
  - **Tests:** Update affected page/report tests; delete `TrainingPathFilterValidationTest`.
  - **Depends on:** 3.2.

- [ ] **3.4 Drop the path columns and the TrainingPath enum** (MIG-2)
  - **Do:** Migration dropping `users.training_path` (and its composite index with `role`; keep an index on `role`) and `activity_requirements.participant_path` (rebuild `activity_req_activity_path_pos_idx` as (`activity_id`, `position`)). Delete `App\Enums\TrainingPath`, `User::trainingPathLabel()`, `eligibleTrainingPaths()`, the `fillable`/cast entries and factory fields. `grep` must find no `training_path`, `participant_path` or `TrainingPath` outside old migrations.
  - **Acceptance:** Migrations run up and down on SQLite and MySQL; the suite passes with no path references.
  - **Tests:** Suite only; one schema assertion that the columns are gone.
  - **Depends on:** 3.3.

## Phase 4 — Session-scoped training for participants (Gate A; after Phase 2)

- [ ] **4.1 Tie submissions, gate evaluations and weekly videos to a session** (PAR-3, MIG-1)
  - **Do:** Migration adding `training_session_id` (not null, FK restrict) to `activity_submissions`, `gate_evaluations`, `weekly_video_recordings`, swapping the unique keys and index (see Design). The migration first checks the three tables are empty and throws a clear exception otherwise (MIG-1 guarantees they are after developer program DAT-2). Models: fillable, `trainingSession()` relations, `TrainingSession::activitySubmissions()` etc. Factories: default `training_session_id` from a `TrainingSession` factory whose program owns the activity's/gate's/week's program where practical.
  - **Acceptance:** Migrates on SQLite and MySQL; refuses to run with existing rows. The same participant can have a gate evaluation for the same gate in two sessions.
  - **Tests:** New `tests/Feature/SessionScopedRecordsTest.php`: two sessions, same gate/user → two evaluations; same user/week → two videos; existing factories still work across the suite.
  - **Depends on:** Gate A, 2.1.

- [ ] **4.2 Add session access control and nest the week and activity pages** (PAR-4, PAR-6, SES-3)
  - **Do:** `TrainingSessionPolicy::view` (admin, member participant, any coach until 5.4). The week-in-session middleware (Design). Move `training.weeks.show` and `training.activities.show` under `training/sessions/{trainingSession}/weeks/{trainingWeek}`; update `TrainingWeekController`, `TrainingActivityController`, `TrainingWeekPageService`, `TrainingActivityPageService` and every `route()` call to pass the session (find them with `grep -rn "training.weeks.show\|training.activities.show" app resources tests`). Sidebar "active" patterns updated.
  - **Acceptance:** A member opens their session's week and activity pages. A participant not in the session gets 403 on both; a participant removed from the session gets 403. A week from another program, or beyond `weeks_count`, returns 404.
  - **Tests:** New `tests/Feature/TrainingSessionAccessTest.php`: member 200, non-member 403, removed 403, foreign week 404, week beyond length 404, admin 200; update `TrainingWeekPageTest`, `TrainingActivityPageTest`.
  - **Depends on:** 4.1.

- [ ] **4.3 Keep submissions within their session** (PAR-3)
  - **Do:** Nest the submission, evidence-upload and activity-export routes under the session (`ActivitySubmissionController`, `SubmissionEvidenceController@store`, `ActivityExportController`, `StoreSubmissionDraftRequest` if it reads route models). `SubmissionWorkflowService::saveDraft()` and every attempt lookup filter and write `training_session_id`; retries in `SubmissionReviewService` and `RecoveryRequestController` copy it. `ActivitySubmissionPageService`, `ParticipantDashboardService::attachActivityProgress()` read only the session's attempts.
  - **Acceptance:** A participant in two sessions of the same program has separate drafts, attempts and scores for the same activity in each; work in session A never shows in session B. A submission URL with a version from another session returns 404. Removing the participant from a session keeps the rows (assert `assertDatabaseHas`).
  - **Tests:** Extend `SessionScopedRecordsTest` or new `SessionSubmissionTest`: separate drafts per session, cross-session version 404, retry keeps session, removal keeps submissions; update `ParticipantWorkflowTest`, `SubmissionFormPageTest`, `SubmissionVersionPageTest`.
  - **Depends on:** 4.2.

- [ ] **4.4 Keep gates and weekly videos within their session** (PAR-3)
  - **Do:** Nest the participant gate page and video routes (`GateReviewController@participant`, `WeeklyVideoRecordingController`) and the coach gate review (`GateReviewController@show|update`, `StoreGateReviewRequest`) under the session; `GateEvaluation::updateOrCreate` keys include `training_session_id`; the participant must be a member of the session (404 otherwise). `WeekProgressionService` takes the `TrainingSession` in every public method and reads only that session's evaluations (keep its gate-chain rule until 6.1); update its callers. The coach Gate board (`CoachGateOverviewService`, `CoachGateBoardPageService`) builds one row per session membership × gate and links with the session (scoping to the mentor comes in 5.7). Update the `PostTooLargeException` path in `bootstrap/app.php`.
  - **Acceptance:** Passing week 1 in session A does not pass it in session B. A video uploaded in session A is not shown in session B. A coach gate review URL for a participant who is not in that session returns 404. An oversized video upload still shows "The video must not exceed 350 MB."
  - **Tests:** Update `ParticipantGatePageTest`, `CoachGateReviewPageTest`, `CoachGateBoardPageTest`, `WeeklyVideoRecording*Test`; add cross-session isolation cases.
  - **Depends on:** 4.3.

- [ ] **4.5 Add the session details page and turn the dashboard into the landing page** (PAR-1, PAR-4)
  - **Do:** `TrainingSessionController@show` (`training.sessions.show`, policy `view`) renders `resources/views/training-sessions/show.blade.php`: header (title, program, dates, status) and the week tabs moved from `participant/dashboard.blade.php` (one tab per session week, activities, gate card, video card), built by `ParticipantTrainingSessionPageService` reusing `ParticipantDashboardService`. `/dashboard` (`ParticipantDashboardController`) becomes the landing page: attendance card (heading "ATTENDANCE"), notifications summary, **My trainings** (joined, not completed) and **Past trainings** (joined, completed), each row linking to the session; empty states. No active-program lookup remains in the controller.
  - **Acceptance:** A participant in one active and one completed session sees each in the right list and can open both; a participant in no session sees empty states and no error. The session page shows one tab per session week with the same activity/gate/video content as today, scoped to the session.
  - **Tests:** Rewrite `ParticipantDashboardControllerTest` for the landing lists; new `tests/Feature/ParticipantTrainingSessionPageTest.php` for the tabs and scoping.
  - **Depends on:** 4.4.

- [ ] **4.6 Add the participant Resources tab and resource access** (PAR-4, PAR-6)
  - **Do:** "Resources (n)" tab on the session details page, grouped like the admin tab, read-only; links open `training.sessions.resources.show`. `TrainingSessionResourcePolicy::view` delegates to the session's `view`; `TrainingSessionResourceController@show` uses it. Interim (Q3): every resource is shown to members; 6.6 adds the week rule.
  - **Acceptance:** A member opens a session-wide and a week resource. A non-member gets 403 on the resource URL even with a valid ID; a resource ID from another session in the URL returns 404.
  - **Tests:** Extend `TrainingSessionAccessTest`: member 200 (file streamed, link redirected), non-member 403, mismatched session 404.
  - **Depends on:** 4.5, 2.6.

- [ ] **4.7 List available sessions and let participants join** (PAR-1, PAR-2)
  - **Do:** Landing page **Available trainings**: every non-completed session the participant is not in, with a one-click **Join** button (POST `training.sessions.join`, policy `join`, no form fields; Q1). `TrainingSessionService::join()` inserts the membership idempotently (`firstOrCreate`), `added_by` null. No repository URL here (developer program LOG-2 adds it on the session page later).
  - **Acceptance:** Joining moves the session from Available to My trainings and redirects to the session page with a status message. Joining twice leaves one row and no error. Joining a completed session returns 403 and creates nothing; coaches and admins get 403.
  - **Tests:** New `tests/Feature/JoinTrainingSessionTest.php`: join, double join, completed 403, other roles 403, lists after join.
  - **Depends on:** 4.5.

- [ ] **4.8 Let new participants pick sessions at registration** (REG-1)
  - **Do:** `auth/register` lists non-completed sessions as optional checkboxes (title, dates); `StoreRegisteredUserRequest` accepts `training_session_ids` (array, exists, not completed); `RegisteredUserController` joins each through `TrainingSessionService::join()` after creating the user, in one transaction. No per-session fields (Q1).
  - **Acceptance:** Registering with two sessions ticked shows both under My trainings. A completed or unknown session ID fails validation and creates no user. Registering with none works.
  - **Tests:** Extend the registration tests from 1.2.
  - **Depends on:** 4.7.

- [ ] **4.9 Generate participant notifications per session** (PAR-3)
  - **Do:** `ParticipantNotificationService::sync()` iterates the participant's non-completed sessions instead of the active program, with keys prefixed `session:{id}:` and action URLs to the nested routes; delete or resolve notifications whose session the participant left. `ParticipantProgressObserver` keeps queuing per participant.
  - **Acceptance:** A participant in two sessions gets separate "missing submission" notifications per session, each linking to the right session's activity. Removal from a session resolves its notifications.
  - **Tests:** Update `ParticipantNotificationsTest`, `ParticipantNotificationTriggersTest`; add a two-session case.
  - **Depends on:** 4.5.

- [ ] **4.10 Sync Drive workbooks per session** (PAR-3)
  - **Do:** `SyncTrainingDataJob` and `TrainingWorkbookService::forWeek()` work per (session, week): one managed folder per session (named by session title) containing its week workbooks, evidence and videos; `google_drive_exports.scope_key` includes the session ID. Workbook rows add the employee ID.
  - **Acceptance:** A participant in two sessions gets two folders whose workbooks contain only that session's work. Re-running the sync updates rather than duplicates files.
  - **Tests:** Update `GoogleDriveSyncTest` / `GoogleDriveExportTest` with two sessions.
  - **Depends on:** 4.4.

## Phase 5 — Mentors

- [ ] **5.1 Add session mentors and mentor assignments** (MEN-1, MEN-2, MEN-3; prerequisite)
  - **Do:** Migrations and models for `training_session_mentors` and `mentor_assignments` (Design), factories. `MentorAssignmentService`: `addMentors()`, `removeMentor()` (ends that mentor's current assignments in the session), `assign(TrainingSession, User $participant, User $mentor, User $actor)` (ends the current row and opens a new one in a transaction; no-op when unchanged), `currentMentor()`, `history()`. `TrainingSessionService::removeParticipant()` ends the participant's current assignment. Relations on `TrainingSession` and `User` (`mentoredSessions()`, `currentMentorAssignments()`).
  - **Acceptance:** Assigning B after A leaves A's row ended at the change time and B's current; history lists both in order. Assigning a mentor who is not a session mentor, or a participant not in the session, throws a validation error.
  - **Tests:** New `tests/Feature/MentorAssignmentServiceTest.php`: assign, change, unchanged no-op, remove mentor ends assignments, remove participant ends assignment, invalid mentor/participant.
  - **Depends on:** 2.5.

- [ ] **5.2 Manage mentors on the Mentors tab** (MEN-1, ADM-4, SA-4)
  - **Do:** `AdminTrainingSessionMentorController@store|destroy`, `StoreTrainingSessionMentorRequest` (`user_ids` of active coaches not yet mentors). Mentors tab: list (name, employee ID, number of current participants), add multi-select, Remove with confirmation warning that their participants become unassigned. Audit add/remove.
  - **Acceptance:** Adding two coaches lists both; adding a participant or admin fails validation. Removing a mentor leaves their participants unassigned. Counts on the tab label update.
  - **Tests:** New `tests/Feature/AdminTrainingSessionMentorTest.php`: add, wrong role, duplicate, remove (assignments ended), authorization, audit rows.
  - **Depends on:** 5.1, 2.4.

- [ ] **5.3 Assign and change mentors, and show the current mentor** (MEN-2, MEN-3, ADM-3, ADM-4, SA-4)
  - **Do:** `AssignMentorRequest` (`mentor_id` among the session's mentors); `admin.sessions.assignments.update` calls `MentorAssignmentService::assign()`. Mentors tab: per participant a mentor select ("Not assigned" when none) and a collapsible history ("Mentor A, 9 Nov – 23 Nov · Mentor B, since 23 Nov"). Participants tab: current mentor column. Audit `session_mentor_assigned` with previous and new mentor IDs.
  - **Acceptance:** An admin assigns A, then changes to B in week 3; the Participants tab shows B, history shows both with dates. Choosing a coach who is not a session mentor fails validation.
  - **Tests:** Extend `AdminTrainingSessionMentorTest`: assign, change, history shown, invalid mentor, authorization, audit row.
  - **Depends on:** 5.2.

- [ ] **5.4 Authorize mentors per session and retire global coach assignments** (MEN-3, MEN-4)
  - **Do:** Add `User::mentorsParticipantInSession()` (Design) and replace every `canManageParticipant()` use with it, resolving the session from the record (`SubmissionVersionPolicy`, `GateEvaluationPolicy`, `SubmissionEvidencePolicy`, the six Form Requests, `GateReviewController`, `ScoringController`, `WeeklyVideoRecordingController`, `ScoringPageService`, `CoachSubmissionReviewPageService`, `CoachGateReviewPageService`, `CoachParticipantPageService`). `TrainingSessionPolicy::view` for coaches → session mentors only. Remove `admin.users.assignments`, `UpdateParticipantAssignmentsRequest`, the assignments section of the admin user form, `CoachParticipantAssignment`, `assignedCoaches()` / `assignedParticipants()` / `canManageParticipant()`, and drop `coach_participant_assignments` (migration). Remaining readers of `assignedCoaches` (reports) switch to current mentor assignments in the batches below; make them compile here by reading the current mentor through `mentor_assignments`.
  - **Acceptance:** After a change from A to B, B can review, score and decide the gate on the participant's week 1 work in that session; A gets 403 on the same URLs; A's earlier reviews still show A as reviewer. A mentor of the participant in session X gets 403 on that participant's work in session Y. Admins keep full access.
  - **Tests:** New `tests/Feature/MentorScopeAuthorizationTest.php` covering review, score, second reader, gate review, recovery decision, video view, evidence download for current/previous/other-session mentors and admin; update tests that used `assignedCoaches()`.
  - **Depends on:** 5.3, 4.4.

- [ ] **5.5 Scope the coach participants list and participant page** (MEN-4, SA-2)
  - **Do:** `CoachParticipantListPageService` lists one row per (participant, session) where the viewer is the current mentor (admins: all), with a session filter (`FilterCoachParticipantsRequest` gains `session`), employee ID and session columns, and the "Assigned to me" label removed. `CoachParticipantPageService` / `coach.participants.show` take the session (route or query) and show only that session's work; 403 for non-mentors.
  - **Acceptance:** A mentor sees only their current participants; after reassignment the participant disappears from the old mentor's list. Admin sees everyone and can filter by session.
  - **Tests:** Update `CoachParticipantListPageTest`, `CoachParticipantPageTest`.
  - **Depends on:** 5.4.

- [ ] **5.6 Scope the review queue, review page and recovery requests** (MEN-4)
  - **Do:** `CoachReportService::reviewQueue()` / `CoachReviewQueuePageService` and `CoachRecoveryRequestPageService` filter to submissions whose session + participant the viewer currently mentors (admins: all), add a session filter and column; the review CSV adds a "Training session" column.
  - **Acceptance:** A mentor's queue shows only their current participants' submissions in their sessions; a previous mentor no longer sees them.
  - **Tests:** Update `CoachReviewQueuePageTest`, `CoachRecoveryRequestPageTest`, `CoachReportsTest`.
  - **Depends on:** 5.4.

- [ ] **5.7 Scope the Gate board and Gate review** (MEN-4)
  - **Do:** Gate board rows limited to the viewer's current mentees per session (admins: all), with a session filter (`FilterCoachGatesRequest`); gates CSV adds the session column. Unassigned mentors get 403 on the gate review page (replaces today's read-only view).
  - **Acceptance:** Board and CSV list only the mentor's participants; review page 403 for others.
  - **Tests:** Update `CoachGateBoardPageTest`, `CoachGateReviewPageTest`.
  - **Depends on:** 5.4.

- [ ] **5.8 Scope scoring pages and scoring reports** (MEN-4)
  - **Do:** `ScoringPageService` (overview, participant page) and `ScoringReportService` / `ScoringReportController` / `ExportParticipantScoreSummaryRequest` replace the active-program lookup and `assigned_coach` filter with session + current-mentor filters; CSVs add the session column; per-session summaries for participants in several sessions.
  - **Acceptance:** A mentor sees and exports only their mentees' scores, per session; admins can filter by session and by mentor.
  - **Tests:** Update `ScoringOverviewPageTest`, `ScoringParticipantPageTest`, `ScoringReportControllerTest`, `ScoringReportsPageTest`.
  - **Depends on:** 5.4.

- [ ] **5.9 Scope the coach dashboard, reports and attendance; list a mentor's sessions** (MEN-4, SA-2)
  - **Do:** `CoachDashboardPageService` drops the active-program lookup: counts cover the viewer's current mentees (admins: all) and a "My sessions" card lists the sessions where the viewer is a mentor (title, dates, status, my participant count, link to the participants list filtered by session). `CoachReportService` progress report and `MissingSubmissionReportService` take a session filter and the mentor scope; `AttendancePageService` (coach view) lists only current mentees. CSVs add the session column.
  - **Acceptance:** A mentor in two sessions sees both in "My sessions" and counts only their mentees; reports and attendance exclude other participants. A coach with no sessions sees empty states.
  - **Tests:** Update `CoachDashboardPageTest`, `CoachProgressReportPageTest`, `MissingSubmissionReportPageTest`, `AttendancePagesTest`.
  - **Depends on:** 5.4.

## Phase 6 — Week schedule and unlocking

- [ ] **6.1 Open weeks by date and lock them after an incomplete review** (WK-1, WK-2, WK-3, developer program DEV-3)
  - **Do:** Rewrite `WeekProgressionService` access rules for a session: week *N* is accessible to a participant when `today >= weekOpensOn(N)` and no earlier week of the session has a gate evaluation whose status is *incomplete* (see Assumptions); coaches and admins always have access. Return a state per week: `open`, `not_open` (with date), `locked` (with the blocking week number). "Current week" = the latest open, unlocked week of an active session; `requiresAttendanceForActivity()` uses it. Remove the gate-chain rule and `ensurePriorWeekThreeActivities()` if still present. Check whether developer program LOG-4 (DP 4.3) changed the review statuses and adjust the mapping.
  - **Acceptance:** In a session starting Mon 9 Nov, on 15 Nov week 1 is open and week 2 shows "not open"; on 16 Nov week 2 is open without week 1's gate being passed. After a mentor records week 1 *incomplete*, weeks 2 to 4 are locked even if open; submitting the missing work alone does not unlock; marking week 1 *completed* (by mentor or admin) unlocks the weeks whose date has come, and later weeks stay date-locked. Earlier weeks are never locked.
  - **Tests:** Rewrite the progression parts of `TrainingDomainTest` / `ParticipantWorkflowTest` with `travelTo`: date opening, lock after incomplete, no unlock on resubmission, unlock on completed (mentor and admin), date lock kept after unlock, earlier weeks open.
  - **Depends on:** Phase 5, DP 4.3.

- [ ] **6.2 Explain locked and unopened weeks on their tabs** (WK-4)
  - **Do:** On the session details page every session week keeps its tab (title visible, `locked` state icon). An unopened week's panel shows "Opens on Mon 16 Nov" (format `D j M`); a review-locked week shows "Complete your Week 1 deliverables to unlock" instead of its contents. Week, activity and submission URLs of such weeks return 403 for participants with the same message.
  - **Acceptance:** Tabs and messages match the cases in 6.1; the earlier weeks' contents remain reachable.
  - **Tests:** Extend `ParticipantTrainingSessionPageTest` and `TrainingSessionAccessTest` with `travelTo`.
  - **Depends on:** 6.1.

- [ ] **6.3 List the missing deliverables and show when they await verification** (WK-4)
  - **Do:** Under the locked message, list the incomplete review's required checklist items marked not met (titles), each linking to the incomplete week (its tab on the session page, where its activities are). Put the "has new work since the review" check in `WeekProgressionService`, so 6.5 reuses it: at least one activity version of that week, in that session, by that participant, with `submitted_at` after the review's `evaluated_at` (Q2). When it holds, replace the message and list with "Waiting for your mentor to verify Week 1".
  - **Acceptance:** After an incomplete review of week 1 with two unmet items, a later week's tab shows "Complete your Week 1 deliverables to unlock" and both item titles linking to week 1. After the participant submits any week 1 activity, it shows "Waiting for your mentor to verify Week 1" and the later weeks stay locked (WK-3). A submission made *before* the review, or in another week or session, does not change the message. A new incomplete review brings the list back.
  - **Tests:** Extend `ParticipantTrainingSessionPageTest`: missing list, waiting after one submission, earlier/other-week/other-session submissions ignored, re-review resets.
  - **Depends on:** 6.2.

- [ ] **6.4 Make completed sessions read-only, except reopened activities** (PAR-5)
  - **Do:** When the session is completed, every week's contents, own submissions, scores and resources are viewable (no date or review locks for viewing), and draft/submit/revise/evidence/video writes are refused with "This training session has ended." — except an attempt in `NeedsRevision`, which can be revised and resubmitted. Hide write buttons accordingly.
  - **Acceptance:** After the end date a participant can open everything but cannot start or submit work (403 / validation message); an activity a mentor marks *Needs revision* after the end can be resubmitted, and the mentor can review it.
  - **Tests:** New `tests/Feature/CompletedTrainingSessionTest.php`: view allowed, writes refused, reopened attempt resubmitted, mentor reviews it.
  - **Depends on:** 6.1.

- [ ] **6.5 Show mentors which locked weeks await verification** (WK-3)
  - **Do:** Coach dashboard card "Waiting for verification": the viewer's current mentees (admins: all) with an incomplete week that has new work since the review (the 6.3 check, Q2), showing participant, session, week, the latest submission time and a link to the gate review. Built with a fixed number of queries.
  - **Acceptance:** The participant from 6.3 appears for their current mentor (not for other or previous mentors) once any activity of the incomplete week is submitted after the review, and disappears once the week is marked completed.
  - **Tests:** Extend `CoachDashboardPageTest`: appears after a later submission, absent before it, absent for another mentor, gone after completed.
  - **Depends on:** 6.3, 5.9.

- [ ] **6.6 Unlock week resources by date or by the previous week's checklist** (WK-1, PAR-4, PAR-5)
  - **Do:** Apply the Q3 rule in `TrainingSessionResourcePolicy::view` and in the participant Resources tab, using the week states from 6.1. Session not completed: a participant can open a week *N* resource when `today >= weekOpensOn(N)` or their week *N − 1* review in that session is *completed* (week 1: date only). Session-wide resources are always open; a completed session opens everything; mentors and admins are not restricted. Resources that are not open yet are listed with "Opens on Mon 16 Nov" and no link; their URLs return 403 to participants. An *incomplete* review of an earlier week does not hide resources of weeks whose date has passed (see Assumptions).
  - **Acceptance:** In a session starting Mon 9 Nov, on 12 Nov a Week 2 resource shows "Opens on Mon 16 Nov" and its URL returns 403. Once the participant's week 1 review is marked completed on 13 Nov, it opens for that participant but not for others. On 16 Nov it opens for everyone. Week 2 *activities* stay closed until 16 Nov either way. Session-wide resources are always open. After the session ends every resource opens. Mentors and admins can open everything at any time.
  - **Tests:** Extend `TrainingSessionAccessTest` with `travelTo`: date rule, early unlock by a completed previous review (only for that participant), week 1 date-only, session-wide, completed session, mentor/admin, activities still date-locked.
  - **Depends on:** 6.1, 4.6.

## Phase 7 — Session overview and admin dashboard

- [ ] **7.1 Fill the Overview tab** (ADM-2)
  - **Do:** `AdminTrainingSessionPageService` overview stats for the session using `x-ui.stat`: participants, mentors, unassigned participants, participants with work per week, awaiting review, needs revision, pending gates, participants with locked weeks. Counts in a fixed number of queries.
  - **Acceptance:** Counts include only the session's participants and records; another session's submissions do not change them.
  - **Tests:** Extend `AdminTrainingSessionDashboardTest` with two sessions.
  - **Depends on:** Phase 6.

- [ ] **7.2 Fill the Activities tab** (ADM-2)
  - **Do:** Per activity of the session's weeks: week, title, and counts among the session's participants of not started, awaiting review, needs revision, approved; link to the review queue filtered by session.
  - **Acceptance:** Counts match the session's submissions; the tab label shows the number of activities.
  - **Tests:** Extend `AdminTrainingSessionDashboardTest`.
  - **Depends on:** 7.1.

- [ ] **7.3 Drop program-wide training stats from the admin dashboard** (ADM-1, ADM-2, developer program GEN-4)
  - **Do:** `AdminDashboardPageService` stops reading the active program: keep account counts, recent sessions (2.7), global review counts and integrations; remove the per-week training stats (now per session in 7.1).
  - **Acceptance:** The admin dashboard renders with zero, one or many programs and sessions; no `Program::query()->where('is_active', true)` remains in it.
  - **Tests:** Update `AdminDashboardPageTest`.
  - **Depends on:** 7.1.

## Out of scope / follow-ups

- Developer program DEV-4 / DMO-2 (seed the first session and enroll demo users with mentors, DP 6.1 and 6.2) and LOG-2 (repository URL, asked on the session page after a one-click Join, DP 6.3): owned by the developer program plan, after Phases 2, 4 and 5 here.
- Linking each checklist item to a specific activity, which would let "Waiting for your mentor" track the actual missing deliverable (Q2 trade-off).
- Switching the existing inline audit writers (`AdminUserController`, `AdminAiSettingsController`, `AdminGoogleDriveController`, `BootstrapAdminCommand`) to `AdminAuditService`.
- Searchable participant/mentor pickers instead of selects for large user lists.
- Showing the participant's current mentor on their session page; mentor change notifications (spec Out of scope).
- Leaving a session as a participant, deleting sessions, certificates, per-session reports beyond the scoped exports (spec Out of scope).
- Program, week and activity editing UI (spec Out of scope).
- Deployment note: PHP `upload_max_filesize` / `post_max_size` must allow 100 MB resource uploads; record it in the deploy checklist when 2.6 ships.

## Open decisions

Problems found during implementation that need a decision. Starts empty.

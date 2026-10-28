# Weekly Session Attendance Plan

Source spec: `docs/specs/session-attendance.md` (written 2026-10-28, updated the same day with Removals REM-1 to REM-4, decisions 5–6 and "any week, for now" (ATT-4, decision 3)) · Last updated: 2026-10-28

**Status legend:** `[x]` done · `[ ]` to do · `[~]` in progress · `[-]` deferred

## Goal

Mentors and admins record, on the existing Attendance page, which participants joined each week's online session of a curriculum: a grid with one row per participant, one checkbox per active week, totals, a summary and a CSV export. A mentor sees and edits only the participants currently assigned to them. The participant check-in / check-out feature and recovery requests are removed completely, together with their tables. Removing them first leaves smaller pages for the new grid to build on.

## Rules for every batch

- Follow the "Code Quality Rules" in `CLAUDE.md` for every new or touched line. `php` is `~/.config/herd-lite/bin/php`. The framework is **Laravel 13.30** (`composer show laravel/framework`).
- Keep each batch small and independently shippable: one concern, its tests, green suite.
- Do only the batch you were given. Record new problems under "Open decisions" instead of fixing them on the spot.
- Definition of done: `vendor/bin/pint --dirty --format agent`, the full suite passing (`php artisan test --compact`, check the real exit code), `npm run build` if CSS, JS or Tailwind classes changed, the item ticked here (`[x]`, or `[~]` with a short note), and one commit per batch.
- **Migrations:** create them with `php artisan make:migration … --no-interaction`. Never edit the squashed `2026_10_24_*` files: the original create migrations for `attendance_sessions` and `activity_recovery_requests` stay, because `AdoptSquashedMigrationsCommand` lists them. Do not add new files to `AdoptSquashedMigrationsCommand::SQUASHED_MIGRATIONS`. Use only portable Blueprint calls, so the suite (SQLite) and dev (MySQL) both run them. After a batch adds a migration, run it on the dev MySQL database. Both drop migrations are destructive: confirm with the user first. Dev holds 0 rows in both tables (checked 2026-10-28).
- **Removals delete; they do not hide.** Delete routes, controllers or methods, Form Requests, services or methods, views, enum and model files, and their tests. Leave no dead branches, unused array keys, PHPDoc shape entries or `use` lines. When a removed feature was the only reason a test existed, delete the test. When a test only used the feature for setup (for example `post(route('participant.attendance.check-in'))` before opening an activity), delete the setup line and keep the test.
- **UI words:** the page is "Attendance". Columns are "Week N". The action buttons are "Save attendance" and "Export CSV". Say "curriculum" / "curricula" in UI text and "mentor" for the `coach` role. No text may mention check-ins, training hours or recovery requests (REM-4).
- Do not touch the uncommitted mentor-assignment work in the working tree (`AdminProgramMentorAssignmentController`, `AssignMentorRequest`, `ProgramPeopleTabService`, `admin/curriculum/tabs/{mentors,participants}.blade.php`, `AdminProgramMentorTest`). No batch here needs those files. If they are still uncommitted when a batch runs, leave them out of its commit.
- Session attendance is not written to the admin audit log (spec assumption).

## Requirements

| ID | Requirement (from spec) | Covered by |
|---|---|---|
| ATT-1 | One weekly session per active week; inactive weeks have none and do not appear; attended or not, no other state | 3.1, 3.2 |
| ATT-2 | Grid: participant name + employee ID, one column per active week in order ("Week N" + dates or "Not scheduled"), one checkbox per cell, total "3 of 4" (attended of active weeks) | 3.2 |
| ATT-3 | Admin sees every participant of the curriculum; mentor only their current mentees there; unassigned participants only to admins | 3.2, 3.3, 3.4 |
| ATT-4 | For now every active week's checkboxes are always enabled, whatever its dates (future and unscheduled weeks too), and stay editable after the curriculum ends; headings show only the dates | 3.2, 3.3 |
| ATT-5 | One form, **Save attendance**; every checkbox saved as shown; back to the same curriculum with "Attendance saved."; saving twice changes nothing | 3.3 |
| ATT-6 | A save naming a participant the viewer does not see, a week of another curriculum or an inactive week is refused with a validation error and changes nothing | 3.3 |
| ATT-7 | Each attended record keeps who last marked it and when (not shown) | 3.1, 3.3 |
| ATT-8 | After a mentor change the new mentor sees and edits every week, including the previous mentor's marks; the previous mentor no longer sees the participant; records unchanged | 3.2, 3.3 |
| ATT-9 | Removing a participant hides them but keeps their records (re-adding shows them again); deleting a curriculum, week or user deletes its records | 3.1, 3.2 |
| ATT-10 | `/admin/attendance` and `/coach/attendance` show only weekly session attendance (picker, summary, grid, export); check-in report removed; title and sidebar link "Attendance", same URLs | 3.2, 3.4 |
| ATT-11 | Curriculum picker = the coach curriculum filter list (`CoachProgramFilterService::options()`); opens on the first; "No curricula to show." when none; a curriculum the viewer may not see is a validation error | 3.2 |
| ATT-12 | Summary above the grid: "42 of 48 · 88%" (attended of held) for the participants shown, or "No sessions held yet"; a ticked week not yet started counts as attended and held | 3.4 |
| ATT-13 | Main action **Export CSV**: Participant, Employee ID, Email, Mentor, "Week N" per active week (Attended / Absent / Not held = not started, not ticked), Attended, Held, Rate (%); one row per participant by name; same scope as the grid | 3.4 |
| ATT-14 | "No participants to show." / "This curriculum has no weeks." instead of the grid; Save button whenever the grid is shown | 3.2, 3.3 |
| ATT-15 | Only mentors and admins open the page, save or export; participants cannot see or change session attendance anywhere | 3.2, 3.3, 3.4 |
| REM-1 | Remove check-ins everywhere: participant page, actions and sidebar link; the check-in requirement before working on an activity; dashboard and curriculum page prompts; summaries on mentor participant list/page and admin user form, the admin user list link; the check-in report on the Attendance page; the model, requests and tests | 2.1, 2.2, 2.3, 3.2, 4.1 |
| REM-2 | Remove recovery requests everywhere: the participant form and notes under a failed attempt (keep the closed sentence, feedback and newer-attempt note), the mentor page, action and sidebar link, the model, enum, requests, page service and tests. Retry and mark-incomplete stay | 1.1 |
| REM-3 | New migrations drop `attendance_sessions` and `activity_recovery_requests`; the original create migrations stay; no export needed (production is empty) | 1.2, 4.1 |
| REM-4 | No page, email, export, notification or legal text mentions check-ins, check-in hours or recovery requests; the privacy policy describes weekly session attendance recorded by mentors | 1.1, 2.1, 2.2, 2.3, 4.2 |

## Current state

- **Attendance page (mentor/admin).** Since 3.2, `GET admin/attendance` and `GET coach/attendance` (`routes/web.php` L153 in the `role:admin` group, L184 in the `role:coach,admin` group) call `AttendanceController::index` with `FilterSessionAttendanceRequest` (`programRules()`) and `AttendancePageService::indexData()`, which reads `SessionAttendanceService::memberships()` / `attendedPairs()`. Since 3.3, `PUT admin/attendance` and `PUT coach/attendance` (L155, L186, `attendance.update`) call `AttendanceController::update` with `UpdateSessionAttendanceRequest` and `SessionAttendanceService::save()`. Since 3.4, `GET admin/attendance.csv` and `GET coach/attendance.csv` (L154, L185, `attendance.export`) call `AttendanceController::export` with `FilterSessionAttendanceRequest`, `AttendancePageService::selectedProgram()` and `SessionAttendanceService::csvRows()`. The view `resources/views/attendance/index.blade.php` shows the header ("Attendance") with the Export CSV main action, the `session('status')` and `$errors->first()` alerts, the curriculum picker and the grid card (heading, the "Sessions attended" summary, then the `PUT` form with enabled checkboxes and "Save attendance"). The check-in report, `FilterAttendanceSessionsRequest` and `attendance/manage.blade.php` are gone. The sidebar (`SidebarNavigationService` L82) links "Attendance" to `coach.attendance.index` for mentors and admins. Since 2.3 no page links to `admin.attendance.index` (the admin user list button and form card were its only links); admins use the shared sidebar link.
- **Check-ins (participant).** The participant page and actions (`participant.attendance.*`, `AttendanceController::participant|checkIn|checkOut`, `CheckInAttendanceRequest`, `AttendancePageService::participantData()`, `attendance/index.blade.php`) and the sidebar link were removed in 2.2; `GET /attendance` now returns 404. `AttendanceSession` and `User::attendanceSessions()` were deleted in 4.1, and its migration drops `attendance_sessions` (the create migration stays).
- **Check-in requirement.** Removed in 2.1. `WeekProgressionService::ensureCanEditActivity(User, Program, Activity)` now only calls `ensureProgramAcceptsWork()`. `attendance_return_to`, its reader and the unreachable "CHECK-IN REQUIRED" alert went with the participant page in 2.2.
- **Other check-in surfaces.** The participant dashboard card, intro clause and live-timer script were removed in 2.2. The summaries on the mentor participant list (Attendance column) and page (Attendance tab) and the admin user list ("View Attendance") and form ("Attendance summary") were removed in 2.3, with `AttendanceSession::durationLabel()`, their last caller. What remains: `legal/privacy-policy.blade.php` L24 and L36.
- **Recovery requests.** `POST training/submissions/{activitySubmission}/recovery-request` `training.recovery.store` (L162, `role:participant`), `GET coach/recovery-requests` `coach.recovery.index` and `POST coach/recovery-requests/{recoveryRequest}` `coach.recovery.decide`, all on `RecoveryRequestController`. Supporting code: `StoreRecoveryRequestRequest`, `FilterRecoveryRequestsRequest`, `DecideRecoveryRequestRequest`, `ActivityRecoveryRequest`, `RecoveryRequestStatus`, `CoachRecoveryRequestPageService` and `coach/recovery_requests.blade.php`. The sidebar has a "Recovery Requests" link (L89). `TrainingActivityPageService` provides `acceptsRecoveryRequests` and the `recovery_request` row on each attempt. `activities/show.blade.php` L143–160 prints the notes and the form. Docblocks that mention recovery requests: `Program::acceptsWork()` and `WeekProgressionService::ensureProgramAcceptsWork()`. `CoachReviewController::retry` (`SubmissionReviewService::createRetry()`) stays. No notification or dashboard tile reads recovery requests.
- **Curricula and scope** (from `curriculum-sessions-merge`):
  - Weeks: `TrainingWeek` with nullable `starts_on`/`ends_on` cast to `date`, `isOpen()` (`starts_on <= today()`), `isScheduled()` and `dateRangeLabel()` ("9 – 15 Nov 2026" or "Not scheduled", via `App\Support\DateRange`). `Program::activeWeeks()` is a `hasMany` of active weeks in `week_number` order.
  - Membership: `ProgramParticipant` (`program_participants`) with `scopeCurrentlyMentoredBy(User)`. Removing a participant deletes the row and ends their mentor assignment (`ProgramEnrollmentService::removeParticipant()`). Removing a mentor ends that mentor's assignments (`MentorAssignmentService::removeMentor()`).
  - Mentor names: `MentorAssignment::scopeCurrentMentorNameOfRow($outer, 'program_participants.program_id')` reads the current mentor's name. `ScoringReportService::memberships()` is the reference for a membership query with `mentor_name`.
  - Curriculum lists: `CoachProgramFilterService::options(User, $selected)` lists every curriculum for an admin and `mentoredPrograms()` (`program_mentors`) for a mentor, latest start first, labelled "Name (dates)". Existing coach filters validate `program` only with `Rule::exists(Program::class, 'id')`, so they do not restrict a mentor to their own curricula.
- **Tests that pin the removed features:** `AttendanceTest` (deleted in 4.1; `AttendancePagesTest` was deleted in 3.2), `CoachRecoveryRequestPageTest`, recovery cases in `CoachOperationsTest` (L469–590), `MentorScopeAuthorizationTest`, `CompletedProgramTest` L109–118, `TrainingActivityPageTest` L246. Rows in `AppLayoutTest` (L97, mentor sidebar). `EmptyTrainingDataTest` has no attendance rows in `pages()` since 3.2; its `attendancePages()` case asserts "No curricula to show.". The check-in setup posts were removed from every test in 2.2. Smaller references in about 15 other files (`grep -rlnE "AttendanceSession|attendance|check.?in|RecoveryRequest|recovery" tests`).
- **`docs/CODE_QUALITY_PLAN.md`:** no unchecked item would change these files. Three entries become obsolete. The "Production values" item (L220–228) lists a query for `activity_recovery_requests.status`. Open decision L446 is about the recovery page's `coach_response` `old()`. The visual-check follow-up L417 names the participant attendance page. Batches 1.2, 1.1 and 4.2 annotate them.

## Design

### Data model

New table **`weekly_session_attendances`** (model `WeeklySessionAttendance`; `Str::plural` gives this table name). It stores attended cells only; absence is the lack of a row.

| Column | Definition |
|---|---|
| `id` | `id()` |
| `training_week_id` | `foreignId()->constrained()->cascadeOnDelete()`: deleting a week, or a curriculum (which cascades to its weeks), deletes its records (ATT-9) |
| `user_id` | `foreignId()->constrained()->cascadeOnDelete()`: the participant; deleting the user deletes their records (ATT-9) |
| `marked_by` | `foreignId()->nullable()->constrained('users')->nullOnDelete()`: who marked it (ATT-7); the record survives the marker's deletion |
| `marked_at` | `timestamp()`: when it was marked (ATT-7) |
| timestamps | |
| keys | unique (`training_week_id`, `user_id`) `weekly_session_attendances_week_user_unique`; index `user_id` |

The curriculum is reached through the week, as `weekly_video_recordings` does. No `program_id` column is needed. Removing a participant from a curriculum touches no records (ATT-9). Deactivating a week keeps its records, and they are hidden while the week is inactive.

`WeeklySessionAttendance` uses `#[Fillable(['training_week_id', 'user_id', 'marked_by', 'marked_at'])]` (as `ProgramResource` does), casts `marked_at` as `datetime`, and has the relations `week()` (`training_week_id`), `participant()` (`user_id`) and `markedBy()` (`marked_by`). `WeeklySessionAttendanceFactory` provides an active week, a participant, a coach as `marked_by` and `marked_at = now()`.

### Rules the grid follows

- **Columns** are the curriculum's active weeks, in `week_number` order, headed "Week {week_number}". Under each heading: `dateRangeLabel()` only.
- **Cells are always enabled**, for every active week, whatever its dates (ATT-4, "for now").
- A week has **started** when `TrainingWeek::isOpen()` is true. Per participant, **held** = the started active weeks plus the ticked active weeks that have not started; **attended** = ticks on active weeks. Held is used only by the summary and the CSV, so the rate never exceeds 100%.
- **Rows** come from a `ProgramParticipant` query for the curriculum, limited to users with the Participant role (as every coach report is). A mentor's query adds `currentlyMentoredBy($viewer)`. Each row loads the participant (`id,name,email,employee_id`) and `mentor_name` via `currentMentorNameOfRow`. Rows are ordered by participant name, then id. Disabled users stay listed, as they do in the other reports.
- **Cell** state: ticked when a record exists for (week, participant).
- **Total** per row: "{attended} of {active weeks}" ("3 of 4").
- **Summary** (ATT-12): the sums of attended and held over the rows shown, "A of H · R%" with the rate rounded to a whole percent ("42 of 48 · 88%"). "No sessions held yet" when H = 0, and no summary when there are no rows.

### Routes (both areas, same controller)

| Method and path | Name (`admin.` / `coach.` prefix) | Action | Request |
|---|---|---|---|
| `GET {area}/attendance` | `attendance.index` (unchanged) | `AttendanceController@index` (was `manage`) | `FilterSessionAttendanceRequest` |
| `GET {area}/attendance.csv` | `attendance.export` (unchanged name and URL, new content) | `AttendanceController@export` | `FilterSessionAttendanceRequest` |
| `PUT {area}/attendance` | `attendance.update` (new) | `AttendanceController@update` | `UpdateSessionAttendanceRequest` |

`{area}` is `admin` (group `role:admin`) or `coach` (group `role:coach,admin`). The area is still read with `$request->routeIs('admin.*')`, so links, the form action and redirects stay in the area the page is in. The participant routes `participant.attendance.*` are deleted (2.2).

### Requests

- **`FilterSessionAttendanceRequest`**: `authorize()` returns `isCoachOrAdmin()`. Rules: `program` nullable integer. An admin may choose any `Program`. A coach may choose only a curriculum they mentor: `Rule::exists(ProgramMentor::class, 'program_id')->where('user_id', $this->user()->id)`, which is the same set `CoachProgramFilterService::options()` lists. Define this rule once as a public static method (e.g. `programRules(User $viewer)`) for the update request to reuse. Attribute name: "curriculum". Message: "Choose a curriculum from the list.". `getRedirectUrl()` points to the area's `attendance.index` without `program`, so a bad choice lands on the default curriculum with the error shown (ATT-11).
- **`UpdateSessionAttendanceRequest`**: the same `authorize()`. `program` is required and uses the same rules. `attendance` is required (`array`), `attendance.*` is `array`, and `attendance.*.*` is `required|in:0,1`. The **`after()`** hook checks keys, which Laravel rules cannot validate, and runs only when the rules passed. It loads the curriculum and calls `SessionAttendanceService::visibleParticipantIds($viewer, $program)` and `activeWeekIds($program)`:
  - Every first-level key must be a visible participant id. Otherwise the error on `attendance` reads "You can only record attendance for participants shown in this curriculum."
  - Every second-level key must be an active week of that curriculum. Otherwise the error reads "You can only record attendance for active weeks of this curriculum." That covers another curriculum's week and an inactive week; weeks that have not started are accepted (ATT-4).

  The whole save is refused and nothing is written (ATT-6). `getRedirectUrl()` points to the area's `attendance.index` with the posted `program` when it is an integer.
- **Payload shape:** every cell renders `<input type="hidden" name="attendance[{userId}][{weekId}]" value="0">` followed by the checkbox with the same name and `value="1"`. PHP keeps the last value, so each cell posts 0 or 1. A participant added after the page was loaded posts nothing and is not touched.

### Services

- **`SessionAttendanceService`** (new, domain):
  - `memberships(User $viewer, Program $program): Builder<ProgramParticipant>`: the rows described above.
  - `visibleParticipantIds(User, Program): list<int>`.
  - `activeWeekIds(Program): list<int>`.
  - `attendedPairs(Program, list<int> $participantIds): array<int, array<int, true>>`: one query, keyed by user and then week, for active weeks only.
  - `save(User $viewer, array<int, array<int, bool>> $cells): void` (3.3; the cells come from `UpdateSessionAttendanceRequest::cells()`, already scoped, so no `Program` is passed), in one `DB::transaction()`. Read the existing pairs for the posted users and weeks, delete the rows for posted 0 cells (one `delete` per week), and insert the missing rows for posted 1 cells with `marked_by` = the viewer and `marked_at` = now (`insertOrIgnore`, so a concurrent save cannot fail on the unique key). Existing rows for posted 1 cells are not touched, so saving twice changes nothing and the first marker is kept (ATT-5, ATT-7).
  - `csvRows(User, ?Program): list<list<string>>` (3.4; the header row only without a curriculum), with `heldCount(Program, array $attendedWeeks): int` and `rate(int $attended, int $held): ?int`, which the page summary uses too.
- **`AttendancePageService`** (rewritten): `indexData(User $viewer, ?int $selectedProgramId, bool $isAdminArea)` returns:
  - `areaLabel`, `programOptions`, and `selectedProgram` (the requested curriculum or the first option, or null);
  - `weeks`: a list of `{heading, dates}` (3.4 needed no `id` or `is_started`: the services read `TrainingWeek::isOpen()`);
  - `rows`: a list of `{participant_name, employee_id, cells: list<{input_name, is_attended, label}>, total}`;
  - `summary` (3.4), `formUrl`, `exportUrl`, and `emptyMessage` (one of "No curricula to show.", "This curriculum has no weeks.", "No participants to show.", or null).

  Its query count stays flat as participants grow: the options, the curriculum with `activeWeeks`, the memberships, and the attended pairs.
- **`AttendanceController`** after 3.4: `index`, `update` and `export` only, each a few lines (resolve input, call the service, return the view, redirect or `streamDownload`).

### Screen (`attendance/manage.blade.php` → renamed `attendance/index.blade.php` in 3.2, after 2.2 frees the name)

1. Header: eyebrow "{ADMIN|MENTOR} · ATTENDANCE" and page title "Attendance" (A1). The intro reads "Record who joined each week's session of a curriculum." The `x-ui.main-action` shows Export CSV when a curriculum is selected (3.4).
2. Flash: `x-ui.alert tone="success" role="status"` for `session('status')`, and `x-ui.alert tone="error" role="alert"` for `$errors->first()`.
3. Curriculum picker: an `x-ui.card` GET form (`aria-label="Choose curriculum"`) with a labelled `<select name="program">` and an `x-ui.button variant="secondary"` "Show". No JS.
4. Summary line (3.4).
5. The grid: an `x-ui.card` holding the `PUT` form (3.3) and a focusable scroll region (`relative overflow-x-auto`, `role="region"`, `tabindex="0"`, as in the current table). The table has `<th scope="col">` week headings and a `<th scope="row">` participant cell (name, with the employee ID muted below). Each checkbox carries `aria-label="{name} attended Week {n}"`. The table ends with the totals column. The "Save attendance" `x-ui.button` shows whenever the grid is shown (rows and weeks exist). The empty states replace the grid.

## Assumptions

- **A1** The page title becomes "Attendance" (today "Participant Attendance"), the eyebrow keeps "{AREA} · ATTENDANCE", and the sidebar link stays "Attendance". Reason: ATT-10 names the title "Attendance", and the page no longer lists participants' own records.
- **A2** Week headings use `week_number` ("Week 3" even when Week 2 is inactive). Reason: every other page names weeks by number.
- **A3** Records on inactive weeks are hidden and kept; reactivating a week shows them. The summary shows "No sessions held yet" only when the held count is 0, so ticks on weeks that have not started produce a rate even before Week 1 starts. Reason: deactivating must not delete data; ATT-12 counts a ticked not-started week as held.
- **A4** When a posted cell falls outside the scope (a participant reassigned or removed since the page loaded, or a week deactivated), the whole save is refused and nothing is written. Reason: ATT-6 says "refused … and changes nothing". The error tells the mentor what was refused, and the redirect reloads the current grid.
- **A5** Re-saving a ticked cell keeps its original `marked_by` / `marked_at`. Only a new tick (a new record) records the viewer. Reason: ATT-5 requires that saving twice changes nothing.
- **A6** The rate is rounded half up to a whole percent. The CSV "Rate (%)" is that integer, or empty when Held = 0. Mentor is "Not assigned" when there is none, as in `MissingSubmissionReportService`.
- **A7** The export resolves the curriculum the way the page does (the requested one, else the first in the list). With no curricula it returns the header row only. Reason: it keeps `EmptyTrainingDataTest`'s "CSV without filters" cases meaningful, and the button only appears when a curriculum is selected.
- **A8** Participant rows include disabled users who are still members, and only users with the Participant role. Reason: the same membership rules as every coach report.
- **A9** The curriculum picker needs no JS (a select with a "Show" button). Reason: the existing filters work this way, and the rules ask for shared JS only when it is needed.
- **A10** The mentor curriculum check uses `program_mentors`, not current assignments. Reason: ATT-11 says "the curricula they mentor" and reuses the filter list. A mentor of a curriculum with no current mentees sees "No participants to show.".
- **A11** Removing check-ins drops the `return_to` round trip (`attendance_return_to`, `CheckInAttendanceRequest::return_to`, `AttendanceController::isSafeInternalUrl()`) with the check-in action in 2.2. Batch 2.1 only stops setting it.
- **A12** The `docs/specs/developer-program.md` "Kept" list (L33, and L66 under DAT-2) is updated in 4.2, as the coordinator asked. `docs/specs/` is in `.gitignore`, so that edit stays local to this working copy.

## Open questions

None blocking. Every design point above has a default; confirm A1, A3 and A4 if they read wrong.

## Phase 1 — Remove recovery requests

- [x] **1.1 Delete the recovery request feature** (REM-2, REM-4), size M. Done; line numbers in "Current state" corrected after the route and test removals. Also updated `ParticipantWorkflowTest::test_failed_attempt_without_retry_…` (it asserted the "Request Review" form). The acceptance grep still matches the two migration names in `AdoptSquashedMigrationsCommand`, which must stay (see Open decisions).
  - **Do:**
    - Routes and code: delete `training.recovery.store`, `coach.recovery.index` and `coach.recovery.decide` from `routes/web.php` with their `use` lines. Delete `RecoveryRequestController`, `StoreRecoveryRequestRequest`, `FilterRecoveryRequestsRequest`, `DecideRecoveryRequestRequest`, `ActivityRecoveryRequest`, `RecoveryRequestStatus`, `CoachRecoveryRequestPageService` and `resources/views/coach/recovery_requests.blade.php`.
    - Sidebar: remove the "Recovery Requests" link from `SidebarNavigationService`.
    - `TrainingActivityPageService`: remove `acceptsRecoveryRequests`, the recovery request query and the `recovery_request` attempt key, with their PHPDoc shapes.
    - `activities/show.blade.php`: a failed attempt keeps "This attempt was not accepted by your mentor and is now closed. …", the Mentor feedback block and the newer-attempt note. Delete the pending, declined and ended notes, the "If you believe…" sentence and the form.
    - Docblocks: reword the ones that name recovery requests (`Program::acceptsWork()`, `WeekProgressionService::ensureProgramAcceptsWork()`). `CoachReviewController::retry` and `SubmissionReviewService::createRetry()` stay.
    - Code quality plan: annotate open decision L446 in `docs/CODE_QUALITY_PLAN.md` as "Obsolete: recovery requests removed (session-attendance 1.1)".
  - **Acceptance:**
    - A participant's failed attempt shows the closed sentence and the mentor feedback, with no "Request Review" form, no "Review requested" or "declined" note, and no "can no longer ask for a review" note.
    - `GET coach/recovery-requests` returns 404, and the mentor sidebar has no "Recovery Requests" link.
    - A mentor can still start a retry from the review screen.
    - `grep -rniE "recovery.?request|RecoveryRequest|Request Review" app resources routes` finds nothing. Only the table and its create migration remain until 1.2.
  - **Tests:**
    - Delete `CoachRecoveryRequestPageTest`, the recovery cases in `CoachOperationsTest` and `MentorScopeAuthorizationTest`, the recovery part of `CompletedProgramTest` (keep its other assertions), and the `ParticipantWorkflowTest` cases at L677/L690.
    - Remove the `AppLayoutTest` sidebar row and the `EmptyTrainingDataTest` "coach recovery requests" row.
    - Rewrite `TrainingActivityPageTest` L246 so a failed attempt with feedback shows the closed sentence and feedback and does not show "Request Review".
    - Add one case: `GET /coach/recovery-requests` as an admin returns 404.
    - Keep the existing retry tests green.
  - **Depends on:** none.

- [x] **1.2 Drop the `activity_recovery_requests` table** (REM-3), size S. Done: `2026_10_28_200029_drop_activity_recovery_requests_table`; `down()` matches the create migration; ran, rolled back and re-ran on dev MySQL (0 rows); the "Production values" query is struck through in `docs/CODE_QUALITY_PLAN.md`.
  - **Do:**
    - Migration: `php artisan make:migration drop_activity_recovery_requests_table --no-interaction`. `up()` runs `Schema::dropIfExists('activity_recovery_requests')`. `down()` recreates the table exactly as `2026_10_24_000030_create_activity_recovery_requests_table.php` defines it, including the unique key (`activity_submission_id`, `status`). The docblock says why the table goes. Leave the create migration and `AdoptSquashedMigrationsCommand` alone.
    - Code quality plan: in the "Production values" item, mark the `activity_recovery_requests` query as no longer needed (table dropped by session-attendance 1.2).
    - Dev database: run the migration on dev MySQL after confirming with the user (0 rows).
  - **Acceptance:** after `migrate`, `activity_recovery_requests` no longer exists. `migrate:rollback --step=1` recreates it. `AdoptSquashedMigrationsCommandTest` stays green.
  - **Tests:** a new `DropRemovedFeatureTablesMigrationTest` with one case: `Schema::hasTable('activity_recovery_requests')` is false. Batch 4.1 adds the attendance case.
  - **Depends on:** 1.1.

## Phase 2 — Remove participant check-ins (participant and summary surfaces)

- [x] **2.1 Remove the check-in requirement before working on an activity** (REM-1, REM-4), size M. Done; `ensureCanEditActivity()` also lost its now unused `TrainingWeek` parameter (callers in `ActivitySubmissionController` and `SubmissionEvidenceController`), and `isCurrentWeek()` went (no other caller). Also rewrote `ParticipantWorkflowTest::test_the_current_week_is_the_latest_open_unlocked_week_and_only_it_asks_for_a_check_in` to assert the curriculum page's selected "Current week" tab instead of the check-in redirect, and `AttendancePagesTest`'s first case now opens `/attendance` directly (no activity redirect, no `return_to`). Line numbers in "Current state" and 2.2 corrected.
  - **Do:**
    - `WeekProgressionService`: delete `requiresAttendanceForActivity()` and `requiresAttendanceForNewActivity()`. `ensureCanEditActivity()` keeps only `ensureProgramAcceptsWork()`; update its docblock. Delete `isCurrentWeek()` too if nothing else calls it.
    - Controllers: delete the redirect and the `attendance_return_to` session write in `ActivitySubmissionController` (L58–63), and the redirect in `TrainingActivityController` (L30–31).
    - `ParticipantDashboardService::weeks()`: drop the `?AttendanceSession` parameter and the `attendance_required` key. Update its caller in `ParticipantProgramPageService` (drop the `activeAttendance` query) and the PHPDoc shapes.
    - Views: in `training-programs/show.blade.php`, delete the "Please check in…" notice (L67–70) and the `attendance_required` condition (L96). In `participant/dashboard.blade.php`, delete the `$errors->has('attendance')` alert (L5–6).
    - `ErrorPagesTest` L48–49: change the sample message to text that names no check-in (it only proves escaping).
  - **Acceptance:**
    - A participant with no check-in opens a current-week activity they have not started (200), saves a draft and submits it.
    - They reopen a draft or needs-revision attempt in the current week without any redirect or 403.
    - The curriculum page shows no "check in" text.
    - Week opening and locking, "This curriculum has ended." and reopened attempts behave as before (their existing tests stay green).
    - Check-in and check-out still work on the participant attendance page until 2.2.
  - **Tests:**
    - Delete the requirement tests in `AttendanceTest` (L52, L190, L214, L231, L248).
    - Replace `ParticipantWorkflowTest::test_editing_without_an_open_attendance_session_guides_participant_to_check_in` with a case where a participant who never checked in edits and saves a current-week activity.
    - Add a `ParticipantProgramPageTest` assertion that the current week shows no "check in" notice.
    - Update any assertion on `attendance_required` or "Please check in". Check-in setup posts elsewhere stay until 2.2.
  - **Depends on:** none (can run before or after Phase 1).

- [x] **2.2 Remove the participant check-in page, actions and dashboard card** (REM-1, REM-4), size M. Done; the now empty `role:participant` route group went too. The dashboard keeps the Mentor / Next action card as a plain `div` (its `section` was labelled by the removed attendance heading). `AttendanceTest`'s management access case lost its check-out step and now asserts 403 on both the admin and mentor pages (it absorbed the mentor 403 from a deleted participant case). The 404 and no-card cases are in `ParticipantDashboardControllerTest` so they survive 3.2 and 4.1 emptying the attendance test files. `GoogleDriveSyncTest`'s `AttendanceSession::create` was setup only (the sync reads no attendance) and was deleted. Line numbers in "Current state" and 3.2 corrected.
  - **Do:**
    - Routes and code: delete `participant.attendance.index|check-in|check-out` (L85–87), `AttendanceController::participant|checkIn|checkOut|isSafeInternalUrl` (and the `TrainingExportCoordinator` / `DB` / `Str` imports they alone used), `CheckInAttendanceRequest`, `AttendancePageService::participantData()` and `resources/views/attendance/index.blade.php`.
    - Sidebar: remove the participant "Attendance" link.
    - `ParticipantDashboardController`: drop `activeAttendance` / `recentAttendance` and reword its docblock.
    - `participant/dashboard.blade.php`: delete the attendance card (L19–42), the inline live-timer `<script>` (L55–74) and the "Check in when you start training," clause. The intro reads "Keep track of what needs attention and open your curricula.". Keep the layout grid sound once the card is gone.
  - **Acceptance:**
    - `GET /attendance` and `POST /attendance/check-in` as a participant return 404 (405 is acceptable for the POST if a GET route of the same URI remains).
    - The participant dashboard has no attendance card, no Check In / Check Out buttons and no timer script.
    - The participant sidebar lists Dashboard and Notifications.
    - The mentor and admin Attendance pages still show the check-in report until 3.2.
  - **Tests:**
    - Delete the participant cases of `AttendanceTest` (L17, L36, L121, L138) and `AttendancePagesTest` (L19, L43, L76, L194).
    - Remove the check-in setup posts from `ParticipantWorkflowTest`, `DevelopmentAccountProvisioningTest` L288 and any other test using `participant.attendance.*`.
    - Check `GoogleDriveSyncTest` L808: delete the `AttendanceSession::create` line if it only set up check-ins, or record it under Open decisions if the sync read it.
    - Update the `AppLayoutTest` participant sidebar rows and remove the `EmptyTrainingDataTest` "participant attendance" row. Update `ParticipantDashboardControllerTest` and `HomeNavigationTest`.
    - Add: participant `GET /attendance` returns 404, and the dashboard does not show "CHECK IN".
  - **Depends on:** 2.1.

- [x] **2.3 Remove check-in summaries from mentor and admin pages** (REM-1, REM-4), size M. Done; `AttendanceSession::durationLabel()` went too (only the removed summaries called it), with its two assertions in `AttendanceTest`'s duration cases (`totalHours()` and `durationSeconds()` stay for the Attendance page). The mentor participant page keeps two tabs (Submissions, Gates) on `initializePageTabs`. `AdminUserFormPageTest::test_query_count_does_not_grow_with_the_number_of_attendance_sessions` existed only for the summary and was deleted; the other query-count tests lost their check-in setup and stay flat. The list, page and admin tests now also assert the column, tab, button and card are gone. Stray references dropped from `CoachOperationsTest` (renamed `test_participant_list_is_a_searchable_table_with_drive_links`), `CoachOperationsControllerTest` (query filter regex) and `CoachProgressReportPageTest` (retrieved-model assertion). "Current state" updated.
  - **Do:**
    - Mentor participant list: in `CoachParticipantListPageService`, drop the `attendanceSessions` eager load, `attendanceSummary()` and the `attendance` row key. Remove the "Attendance" column from `coach/participants.blade.php`.
    - Mentor participant page: in `CoachParticipantPageService`, drop the `attendanceSessions` eager load, the `attendance` key, the `attendance` tab and `attendanceSummary()`. Remove the panel from `coach/participant.blade.php`.
    - Admin user pages: in `AdminUserPageService`, drop `attendanceUrl` and `attendanceSummary()` and the `attendance` key. Remove the "View Attendance" button from `admin/users/index.blade.php` and the "Attendance summary" card from `admin/users/form.blade.php`.
    - Update the PHPDoc shapes throughout.
  - **Acceptance:** the mentor participant list has no Attendance column, the mentor participant page has no Attendance tab, and the admin user list and form show no attendance link or summary. The other columns, tabs and actions are unchanged.
  - **Tests:** update `CoachParticipantListPageTest`, `CoachParticipantPageTest` (tab list without Attendance), `AdminUserFormPageTest` and `AdminUserListPageTest`, plus the stray check-in references in `CoachOperationsTest`, `CoachOperationsControllerTest` and `CoachProgressReportPageTest`. Query-count tests may drop by one; keep them flat.
  - **Depends on:** 2.2.

## Phase 3 — Weekly session attendance

- [x] **3.1 `weekly_session_attendances` table, model and factory** (ATT-1, ATT-7, ATT-9), size S. Done: `2026_10_28_211844_create_weekly_session_attendances_table`; `WeeklySessionAttendance` and its factory per Design; nothing added to `TrainingWeek` or `User` (3.2–3.4 query `WeeklySessionAttendance` directly, so `TrainingWeek::sessionAttendances()` is not needed); `WeeklySessionAttendanceTest` covers the five acceptance cases (the three cascades as one data-provider test, so four methods and six runs) and fails with SQLite foreign keys off; ran on dev MySQL (new table, nothing destructive).
  - **Do:**
    - Migration: `php artisan make:migration create_weekly_session_attendances_table --no-interaction` per Design › Data model.
    - Model and factory: `php artisan make:model WeeklySessionAttendance --factory --no-interaction`, with the Fillable attribute, casts, relations and factory from Design. Add `TrainingWeek::sessionAttendances()` (hasMany) only if 3.2's queries use it; otherwise add nothing to `TrainingWeek` or `User`.
    - Dev database: run the migration on dev MySQL.
  - **Acceptance:**
    - A second record for the same week and participant is rejected by the unique key.
    - Deleting the week, the curriculum or the participant deletes the record.
    - Deleting the marker keeps the record with `marked_by` null.
    - Removing the participant from the curriculum (`ProgramEnrollmentService::removeParticipant()`) keeps it.
  - **Tests:** new `WeeklySessionAttendanceTest` (feature, `RefreshDatabase`) with those five cases.
  - **Depends on:** none (independent of Phases 1–2; 3.2 needs 2.2).

- [x] **3.2 Attendance page shows the curriculum picker and a read-only grid** (ATT-1–4, ATT-8–11, ATT-14, ATT-15, REM-1), size L (one page; the check-in report goes in the same pass). Done; only what the read-only grid uses shipped. Deferred to 3.3: `SessionAttendanceService::visibleParticipantIds()` / `activeWeekIds()` (their only caller is `UpdateSessionAttendanceRequest::after()`), `formUrl`, `selectedProgram.id`, the cell `week_id` / `input_name` and the `session('status')` alert. Deferred to 3.4: `weeks.is_started`, `summary` and `exportUrl` (`memberships()` already selects `mentor_name` and the participant's `email` for the CSV). `indexData()` returns `selectedProgram` as `{name}` (the grid card's heading, which labels the scroll region). Rows order by participant name, then `user_id`. The participant column is sticky in the scroll region. `EmptyTrainingDataTest`'s two attendance page rows moved to their own `attendancePages()` case asserting "No curricula to show.". `AttendanceSession` now has no runtime reader (its helpers are only called by `AttendanceTest`'s duration cases until 4.1). Phone-width visual check not done (no browser in the session).
  - **Do:**
    - Remove the check-in report: `AttendanceController::manage` / `export`, `FilterAttendanceSessionsRequest`, `AttendancePageService::manageData()|filteredSessions()|options()` and `STATUS_OPTIONS`, and the `attendance.export` routes in both groups (3.4 brings the export back).
    - Rename `manage` to `index` (`attendance.index` keeps its URL and name). Add `FilterSessionAttendanceRequest`, `SessionAttendanceService` (`memberships`, `visibleParticipantIds`, `activeWeekIds`, `attendedPairs`) and `AttendancePageService::indexData()`, all per Design.
    - Rename the view to `attendance/index.blade.php` and rewrite it per Design › Screen without the form, summary and export. Every checkbox renders `disabled`, with its ticked state, as the read-only interim until 3.3.
    - Empty states: "No curricula to show." (no picker options, no grid), "This curriculum has no weeks.", "No participants to show.".
  - **Acceptance:**
    - An admin opening `/admin/attendance` sees the first curriculum of `CoachProgramFilterService::options()` selected, with every participant of it by name. The columns are Week 1…N with dates ("9 – 15 Nov 2026" / "Not scheduled") and nothing else under the heading. Totals read "1 of 4" (ticks on active weeks of the active-week count). Inactive weeks do not appear.
    - A mentor at `/coach/attendance` sees only curricula they mentor in the picker, and only their current mentees.
    - After an admin changes a participant's mentor, the old mentor no longer sees the participant and the new mentor sees them with the earlier marks.
    - A participant removed from the curriculum disappears from the grid, and adding them again shows their earlier marks.
    - `?program=` with another mentor's curriculum, or an unknown id, redirects with "Choose a curriculum from the list." shown on the page.
    - A participant gets 403 on `/coach/attendance` and `/admin/attendance`, and guests are sent to login.
    - No check-in filters, table or text remain.
  - **Tests:**
    - Delete the remaining check-in management cases in `AttendancePagesTest` (all four: L19, L62, L94, L121) and `AttendanceTest` (L16, L37, L67). The duration helper cases (L75, L86) wait for 4.1.
    - In `EmptyTrainingDataTest`, remove the two attendance CSV rows (3.4 re-adds them); the page rows now assert "No curricula to show.".
    - New `WeeklySessionAttendancePageTest` covering:
      - admin grid markup in order (headings, dates, totals, ticked vs unticked);
      - default to the first curriculum, and choosing another;
      - mentor scope (other mentor's mentees and unassigned participants absent);
      - the mentor change;
      - remove and re-add;
      - inactive week hidden;
      - each empty state;
      - an invalid or not-mentored `program` failing validation;
      - role access (participant 403, guest redirect);
      - query count flat for 1 vs 3 participants.
  - **Depends on:** 2.2 (needs `attendance/index.blade.php` free and the participant `AttendancePageService` code gone), 2.3, 3.1.

- [x] **3.3 Save attendance with scope enforcement** (ATT-4–8, ATT-14, ATT-15), size M. Done: routes at `routes/web.php` L154 and L184; `UpdateSessionAttendanceRequest` (rules, `after()` key checks that return early when any rule failed, since Laravel runs `after()` callbacks either way; `program()` and `cells()`, which casts the posted 0/1 to bools); `AttendanceController::update`; `SessionAttendanceService::visibleParticipantIds()` / `activeWeekIds()` (sharing a private `visibleMemberships()` base query with `memberships()`) and `save(User, array $cells)` without the Design's `Program` parameter (it had no use once the request scopes the cells). `save()` deletes only existing records of posted 0 cells (one delete per week that has any) and inserts with `insertOrIgnore`, so a re-post runs one read and no write. `indexData()` adds `formUrl`, `selectedProgram.id` and the cell `input_name` (the `week_id` key was dropped: nothing reads it). A malformed cell value fails `attendance.{user}.{week}` ("The selected attendance is invalid."). Tests in `WeeklySessionAttendancePageTest` (14 new runs: save with flash, idempotency, ended curriculum, future and unscheduled weeks, the four refusals as one data-provider test, the `program` refusal, a malformed value, the new mentor unticking, participant 403, guest login redirect, save query count flat for 1 vs 3 participants); `disabledCheckboxes()` became `checkboxes()` (enabled, each after its hidden 0 input), and the grid and empty-state tests assert the Save button's presence and absence. Phone-width visual check not done (no browser in the session).
  - **Do:**
    - Routes: add `PUT admin/attendance` and `PUT coach/attendance` (`attendance.update`).
    - Request and controller: add `UpdateSessionAttendanceRequest` (reusing `FilterSessionAttendanceRequest::programRules()`) and `AttendanceController::update`, which calls `SessionAttendanceService::save()` and redirects to `attendance.index` with `program` and "Attendance saved.".
    - Service: add `SessionAttendanceService::visibleParticipantIds()` and `activeWeekIds()` per Design (deferred from 3.2, which had no caller for them) and `save()`.
    - Page data: `indexData()` adds `formUrl`, `selectedProgram.id` (the hidden `program`) and each cell's `week_id` / `input_name` (deferred from 3.2).
    - View: add the `x-ui.alert tone="success" role="status"` for `session('status')` above the error alert. Wrap the grid in the `PUT` form (`@csrf`, `@method('PUT')`, hidden `program`). Enable every checkbox (drop `disabled`; `WeeklySessionAttendancePageTest::disabledCheckboxes()` expects it and changes with it), each with its hidden 0 input. "Save attendance" shows whenever the grid shows.
  - **Acceptance:**
    - Ticking two cells and unticking one previously attended cell, then saving, leaves exactly the ticked records. New records have `marked_by` = the viewer and `marked_at` = now. Posting the same grid again changes no row (the same ids and `marked_at`).
    - A week of a curriculum that has ended can still be changed. A mentor can tick a week that has not started and an unscheduled week.
    - Each of these is refused with a validation error on `attendance` and writes nothing: a mentor posting a cell for another mentor's mentee, for an unassigned participant, for a week of another curriculum or for an inactive week. A mentor posting a curriculum they do not mentor gets the error on `program`.
    - The new mentor can untick a week the previous mentor marked.
    - A participant's `PUT` gets 403.
  - **Tests:** extend `WeeklySessionAttendancePageTest`, or add `SaveWeeklySessionAttendanceTest`, with the cases above: save and redirect with flash, idempotency, ended curriculum still editable, future and unscheduled weeks accepted, four refusal cases plus `program` refusal (each asserting the table is unchanged), new mentor edits the old mark, Save button presence, role access.
  - **Depends on:** 3.2.

- [x] **3.4 Summary and CSV export** (ATT-3, ATT-10, ATT-12, ATT-13, ATT-15), size M. Done: routes at `routes/web.php` L154 and L185; `AttendanceController::export` streams like the other coach CSVs (`streamDownload`, `fputcsv`, `Content-Type: text/csv`; none of them guards formula injection, see Open decisions); `FilterSessionAttendanceRequest::selectedProgramId()` serves `index` and `export`. `SessionAttendanceService` adds `heldCount()`, `rate()` (half up, null when nothing was held) and `csvRows(User, ?Program)` (`?Program` so no curricula gives the header row only, A7; a curriculum without active weeks still lists its participants with 0, 0 and an empty rate). `AttendancePageService` adds the public `selectedProgram(User, ?int)` (the page's resolution, reused by the export), `exportUrl` and `summary`, and passes the attended pairs to `rows()` and `summary()` so the page still runs four queries. `weeks.is_started` was left out: nothing in the view reads it. The summary is an `x-ui.stat` ("Sessions attended") inside the grid card, under the curriculum heading and above the grid, so it sits with the curriculum it sums; the main action follows the `lg` two-column header pattern of the other main-action pages. Tests in `WeeklySessionAttendancePageTest` (13 new runs: the acceptance summary, rounding 63 % / 67 % / 33 % on the page and in the CSV, the no-held state, exact admin CSV with the first curriculum, "Not assigned" and an empty employee ID, exact mentor CSV of the chosen curriculum without the inactive week, header only without curricula, two invalid `program` redirects, participant 403, guest login redirect, export query count flat for 1 vs 3); the admin grid test now asserts the Export CSV link instead of its absence, the mentor test the coach export link, and the empty-state tests its absence without curricula and no summary without participants. `EmptyTrainingDataTest` has its two attendance CSV rows back. Phone-width visual check not done (no browser in the session).
  - **Do:**
    - Summary: add it to `AttendancePageService::indexData()` and the view per Design, with each week's `is_started` (deferred from 3.2).
    - Export: add `GET {area}/attendance.csv` `attendance.export` → `AttendanceController::export` with `FilterSessionAttendanceRequest`, streaming `SessionAttendanceService::csvRows()` as `weekly-session-attendance.csv`. The header row is `Participant, Employee ID, Email, Mentor, Week 1…N, Attended, Held, Rate (%)`. Cells read Attended / Absent / Not held (A3, A6).
    - Main action: `indexData()` adds `exportUrl`; the Export CSV button (`x-ui.main-action`) links to the export with the selected `program` and shows only when a curriculum is selected.
  - **Acceptance:**
    - 2 participants, 4 active weeks, of which 2 have started. P1 ticked W1, W2 and W3 (W3 not started); P2 ticked W1. The summary reads "4 of 5 · 80%". CSV P1: Attended, Attended, Attended, Not held, then 3, 3, 100. CSV P2: Attended, Absent, Not held, Not held, then 1, 2, 50.
    - With no started week and nothing ticked, the summary reads "No sessions held yet" and the CSV rate is empty.
    - The CSV for a mentor lists only their mentees by name, with one column per active week.
    - An admin's CSV includes unassigned participants with Mentor "Not assigned".
    - A participant gets 403 on the export.
  - **Tests:** extend `WeeklySessionAttendancePageTest`, or add `WeeklySessionAttendanceExportTest`, with summary values (including rounding and the no-held state), exact CSV rows for admin and mentor, `program` validation on the export, and role access. Re-add the two `EmptyTrainingDataTest` CSV rows to `pages()` (header only; `coach.attendance.export` / `admin.attendance.export`).
  - **Depends on:** 3.3.

## Phase 4 — Finish the removals

- [x] **4.1 Delete `AttendanceSession` and drop `attendance_sessions`** (REM-1, REM-3), size S. Done: `2026_10_28_225907_drop_attendance_sessions_table`, whose `down()` matches the create migration with both indexes; `AttendanceSession`, `User::attendanceSessions()` and `AttendanceTest` deleted; `ParticipantWorkflowTest` lost its `attendanceSessions()` count assertion. No seeder, console command, Drive sync / export coordinator or `User` PHPDoc referred to the model. The acceptance grep matches only the two migration names in `AdoptSquashedMigrationsCommand` (L57, L143) and the new `DropRemovedFeatureTablesMigrationTest` case; `AdoptSquashedMigrationsCommandTest` does not name the table, and the create migration is outside the grep's paths. Ran, rolled back and re-ran on dev MySQL (0 rows) after the user confirmed.
  - **Do:**
    - Code: delete `app/Models/AttendanceSession.php`, `User::attendanceSessions()` and every remaining `AttendanceSession` reference, including the two leftover duration cases of `AttendanceTest` (L15, L25; delete the file once it is empty).
    - Migration: `php artisan make:migration drop_attendance_sessions_table --no-interaction`. `down()` recreates the table as `2026_10_24_000012_create_attendance_sessions_table.php` defines it, with both indexes. Leave the create migration and `AdoptSquashedMigrationsCommand` alone.
    - Dev database: run the migration on dev MySQL after confirming with the user (0 rows).
  - **Acceptance:** `grep -rn "AttendanceSession\|attendance_sessions\|attendanceSessions" app database/factories database/seeders resources routes tests` finds only the original create migration and the migration-name strings in `AdoptSquashedMigrationsCommand` and its test. The table is gone after `migrate`.
  - **Tests:** add an `attendance_sessions` case to `DropRemovedFeatureTablesMigrationTest`.
  - **Depends on:** 3.2 (the last runtime reader). Can run before 3.3.

- [x] **4.2 No leftover wording; privacy policy and docs** (REM-4), size S. Done; the privacy policy's attendance paragraph now describes weekly session attendance (who records it, who recorded it and when, where Mentors and Admins see it, participants do not), and L36 stays. The sweep found no user-facing text left: besides the privacy paragraph it matches only the two migration names in `AdoptSquashedMigrationsCommand` (L45, L161; expected, see Open decisions), Drive "recovery remains idempotent" (`GoogleDriveService` L44), two "checked in after()" docblocks (`UpdateSessionAttendanceRequest` L27, `UpdateTrainingWeekRequest` L30) and the evidence preview's internal `Error('preview request failed')` (`resources/js/app.js` L767, caught and never shown). `LegalPagesTest` asserts two phrases of the new paragraph and no "check-in" / "check-out". Docs: `docs/specs/developer-program.md` L33 and L66 (local only, A12), `docs/CODE_QUALITY_PLAN.md` L417, `docs/plans/curriculum-sessions-merge.md` open decision (2.1 step 4b) resolved. The policy's "Last updated" date was left (see Open decisions).
  - **Do:**
    - Privacy policy: rewrite the attendance paragraph in `legal/privacy-policy.blade.php` (L24) to describe weekly session attendance. Mentors and admins record which week's online session each participant joined, and the platform keeps who recorded it and when. In L36, "record progress and attendance" stays true; reword it only if it reads as check-ins.
    - Wording sweep: run `grep -rniE "check.?in|check.?out|checked in|training hours|recovery|request review|review request" app resources routes database/seeders` and fix any remaining user-facing text. Leave code names that refer to unrelated things, such as Drive "recovery remains idempotent".
    - Docs:
      - In `docs/specs/developer-program.md`, replace "attendance" and "recovery requests" in the Kept list (L33) with "weekly session attendance (session-attendance spec)", and drop "recovery requests" from L66 (local only, A12).
      - In `docs/CODE_QUALITY_PLAN.md` L417, note that the participant attendance page was removed (session-attendance 2.2).
      - Mark the open decision "(2.1 step 4b) Attendance still calls a check-in a 'training session'" in `docs/plans/curriculum-sessions-merge.md` as resolved by this plan (check-ins removed).
  - **Acceptance:** the privacy page shows the new paragraph and no "check-in" or "check-out". The sweep finds no user-facing mention.
  - **Tests:** update `LegalPagesTest` if it asserts the old paragraph, and assert one phrase of the new one.
  - **Depends on:** 1.1, 2.3, 4.1.

## Out of scope / follow-ups

- Spec Out of scope: showing session attendance to participants; using it in Gates, checklists, week locking or scoring; scheduling sessions; day-by-day, excused or late attendance; showing it on the mentor participant page or in the summary export. The progress report (`coach.reports`) and its progress CSV now show each membership's attendance as "3/4" (attended of the curriculum's active weeks), added after Phase 3 at the user's request; the mentor participant page and the summary export stay out of scope.
- Restricting the other coach pages' `?program=` filters to a mentor's own curricula (today they accept any existing curriculum id). This plan restricts only the Attendance page, as ATT-11 asks.
- A sidebar link to `admin.attendance.index` (admins use the shared "Attendance" link to `coach.attendance.index`, as today).
- Auto-save, or auto-submitting the curriculum picker on change.

## Open decisions

Problems found during implementation that need a decision. Starts empty.

- [x] Found in 1.1: the 1.1 acceptance grep (`grep -rniE "recovery.?request|RecoveryRequest|Request Review" app resources routes`) still matches `app/Console/Commands/AdoptSquashedMigrationsCommand.php` L45 and L161, the migration names `2026_09_07_000001_create_activity_recovery_requests_table` and `2026_10_24_000030_create_activity_recovery_requests_table`. The "Rules for every batch" require that command's list to stay, so the lines were kept. Accept them as the expected exceptions to the grep (and to the 4.2 wording sweep)? **Resolved 2026-10-28:** yes, they are migration names the rules require to stay, not user-facing text.
- [ ] Found in 3.4: no CSV export in the app guards spreadsheet formula injection. `AttendanceController::export`, `MissingSubmissionReportController::export`, `ScoringReportController::csv()` and `CoachOperationsController::export` write names and emails with plain `fputcsv`, and participants set their own first and last name (`UpdateProfileRequest`), so a name starting with `=`, `+`, `-` or `@` runs as a formula when the file opens in a spreadsheet. 3.4 matched the existing exports. Add one shared guard (for example prefix such cells with `'`) to every CSV export, as its own batch?
- [ ] Found in 4.2: the privacy policy's attendance paragraph changed, but its "Last updated: September 11, 2026" line (`legal/privacy-policy.blade.php` L16, asserted by `LegalPagesTest`) was left, and the policy's "Changes" section says the effective date is posted on the page. Which date should it show (the deploy date of this change, or a date the platform owner sets)? The policy's Contact address and the Terms' "To be confirmed" governing law suggest the owner reviews legal text before it ships.

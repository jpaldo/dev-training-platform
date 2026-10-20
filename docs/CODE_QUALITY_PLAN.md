# Code Quality & UI Modernization Plan

Living plan for bringing the codebase in line with Laravel best practices and migrating the UI to Tailwind CSS v4. Work it top to bottom, one phase (or one batch inside a phase) at a time, and tick items off as they land.

**Status legend:** `[x]` done · `[ ]` to do · `[~]` in progress · `[-]` deferred

**Rules for every batch**

- Keep each batch small and independently shippable: one concern, its tests, green suite.
- Behavior must not change unless the item says so. Existing tests are the safety net; add tests where a fix changes or protects behavior.
- Definition of done: `vendor/bin/pint --dirty --format agent`, the full suite passing (`php artisan test --compact`), `npm run build` if frontend files changed, and this file updated.

---

## Target conventions

The standing conventions for new and touched code live in the "Code Quality Rules" section of `CLAUDE.md`, so every session and agent applies them without reading this file. Change them there.

---

## Phase 0 — Completed

- [x] Rename the product from "QA Training Platform" to **Developer Training Platform** (layout, auth, legal pages, settings, mail test, interview page default title).
- [x] Grouped sidebar navigation per role, with a mobile drawer (`resources/views/layouts/navigation.blade.php`).
- [x] Participant dashboard reorganized into a status strip and tabs (per week, Mentoring, Overview & Resources).
- [x] Tailwind v4 foundation: theme tokens in `resources/css/app.css`, Preflight disabled, legacy layout CSS moved into `@layer legacy`, legacy `.grid` scoped to `[class="grid"]`.
- [x] `x-ui.*` components: `card`, `button`, `badge`, `eyebrow`, `alert`, `tab-list`, `tab-panel`, `sidebar-link`.
- [x] Layout, sidebar and participant dashboard converted to Tailwind.

---

## Phase 1 — Return types (small, very low risk)

Add explicit return types (and parameter types where missing).

- [x] `app/Http/Controllers/ValidationLabController.php` — `index`, `labHome`, `artifact`, `checklist`, `submit`, `submitRecord`, `result`, `ownedRecord`, `appLogin`, `sendOtp`, `otp`, `verifyOtp`
- [x] `app/Http/Controllers/RecoveryRequestController.php` — `store`, `index`, `decide`
- [x] `app/Http/Controllers/WeeklyVideoRecordingController.php` — `edit`, `store`
- [x] `app/Http/Controllers/AttendanceController.php` — `filtered`
- [x] `app/Http/Controllers/DailyLogReportController.php` — `rows`
- [x] `app/Services/SubmissionWorkflowService.php` — `evidenceRequirements`
- [x] `app/Services/ScoringReportService.php` — `participants`
- [x] Sweep for closures without return types in `app/` and fix them in the same batch.

---

## Phase 2 — Form Requests (medium, low risk)

Move the 42 inline validations into Form Requests. Keep the rules and error messages exactly the same; existing validation tests must pass unchanged. Batch by controller.

- [ ] `CoachOperationsController` — lines 44, 58, 77, 94, 105, 141, 259, 291, 316
- [ ] `ValidationLabController` — 45, 60, 118, 250
- [ ] `AdminInterviewSchedulingController` — 72, 87, 97
- [ ] `AttendanceController` — 30, 68, 76
- [ ] `AdminUserController` — 30, 73
- [ ] `MentorInterviewAvailabilityController` — 29, 38
- [ ] `RecoveryRequestController` — 17, 33
- [ ] `ScoringController` — 28, 82
- [ ] `WeekFourMentorInterviewController` — 21, 33
- [ ] Single-call controllers: `AdminAiSettingsController` (31), `DailyLogExportController` (14), `DailyLogReportController` (17), `GoogleDriveDestinationController` (24), `MarkdownPreviewController` (13), `MentorBookingCalendarController` (27), `MissingSubmissionReportController` (50), `ParticipantNotificationController` (17), `PublicInterviewController` (43), `ReusableAssetLibraryController` (21), `ScoringReportController` (17), `WeekThreeWorkspaceController` (15), `WeeklyVideoRecordingController` (38)

Line numbers are from the audit on 2026-10-20 and will drift; search for `->validate(` / `Validator::make` to find the current locations.

---

## Phase 3 — N+1 queries (medium, low–medium risk)

Replace per-row queries with eager loading or single aggregate queries. For each hotspot, add a feature test that fails if the query count grows with the number of rows (e.g. compare `DB::getQueryLog()` counts for 1 vs 3 records).

- [ ] `ParticipantDashboardController` (lines ~34–103) — per-activity `submissions()` queries (twice), per-week daily-log count and video lookup, per-activity Validation Lab `exists()`, per-gate readiness. Highest-traffic page; do this first.
- [ ] `CoachOperationsController` (~49)
- [ ] `ScoringReportController` (~35, ~48)
- [ ] `DailyLogReportController` (~52)
- [ ] `ReusableAssetLibraryController` (~26)

---

## Phase 4 — Slim controllers (medium, medium risk)

Move business logic into services (existing ones where they fit); controllers keep only the HTTP concerns. Do this after Phases 2–3 so the code being moved is already clean.

- [ ] `CoachOperationsController` (352 lines) — split by area if it helps (e.g. participants, reviews, Week 4 pairing), each delegating to services.
- [ ] `SubmissionEvidenceController` (346 lines)
- [ ] `ValidationLabController` (257 lines)
- [ ] `ActivitySubmissionController` (237 lines)

---

## Phase 5 — Tailwind conversion and view cleanup (large, medium risk)

Convert each page to Tailwind and `x-ui.*` components, and fix its view problems **in the same pass** so each file is touched once:

- move database access and non-trivial `@php` logic into the controller or a service;
- break single-line templates into readable markup;
- replace inline `style=""` attributes with utilities;
- extract repeated markup into `x-ui.*` components when it appears on three or more pages.

Pages, in priority order (database calls in the view and `@php` counts in brackets):

- [ ] `activities/show` — tabs: Instructions · Requirements · Validation Lab · Submission & Attempts; a single main action button kept in view (proposal C) [7 queries, 3 @php]
- [ ] `coach/participant` — page heading first, then tabs: Submissions · Gates · Logs & Attendance · Week 4 (proposal D) [3 queries]
- [ ] `submissions/edit` [3 queries, 26 @php]
- [ ] `submissions/week-four-guidance` [5 queries, 14 @php]
- [ ] `submissions/week-three` [3 queries, 4 @php]
- [ ] `submissions/show` [2 queries, 2 @php]
- [ ] `coach/gate_review` [3 queries]
- [ ] `participant/gate`, `weeks/show`, `daily_logs/*`, `participant/notifications`, `attendance/*`
- [ ] `coach/*` remaining (dashboard, participants, reviews, review, gates, reports, daily-log reports, missing submissions, recovery requests, mentor availability, Week 4 questionnaire)
- [ ] `scoring/*` [scoring/edit: 8 @php]
- [ ] `admin/*` (dashboard, users, settings pages) [admin/users/form: 2 queries]
- [ ] `reusable-assets/*` [index: 5 @php]
- [ ] `profile/*`, `auth/*`, `home`, `legal/*`
- [ ] Lab pages (`lab/*`, `checklist`, `result`, `artifact`)
- [ ] Shared components: `evidence-actions`, `weekly-video-actions`, `week-three-review`, `week-four-review`, `markdown-field`, `formatted-text`, `markdown`
- [ ] Standalone pages that do not use the layout: `public/interviews`, `public/interview-register` (decide whether they should load `app.css`)

Note: the view "query" counts come from a pattern search and include some Collection calls (`->count()`, `->where()` on loaded collections). Only real database access has to move.

---

## Phase 6 — Finish the migration (small, medium risk)

- [ ] Enable Preflight in `resources/css/app.css` (switch back to `@import 'tailwindcss';`).
- [ ] Delete the `@layer legacy` styles from `resources/views/layouts/app.blade.php` and any remaining per-page `<style>` blocks.
- [ ] Remove the unused `resources/views/welcome.blade.php`.
- [ ] Visual check of every role's main pages at desktop and phone widths.

---

## Phase 7 — Enums (deferred, highest risk)

- [-] Replace status and role strings with backed enums and Eloquent enum casts (submission statuses, gate evaluation statuses, roles, export statuses). Touches stored values and most of the app; plan separately once Phases 1–6 are done.

---

## Open decisions

- [ ] `resources/css/app.css` contains `p[id="mentor-interview-timezone"] { display:none; }`. It became active when the layout started loading `app.css`, so the timezone line on the Week 4 submission page is now hidden. Confirm that it should stay hidden, or remove the rule.
- [ ] `APP_NAME` in `.env` / `.env.example` is still `Laravel`, so outgoing mail uses "Laravel" as the sender name. Set it to `"Developer Training Platform"`?

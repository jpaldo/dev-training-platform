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

- [x] `CoachOperationsController` — lines 49, 63, 82, 99, 110, 146, 264, 296, 321. Nine Form Requests (`Filter…`, `AssignWeekFourPairRequest`, `WaiveWeekFourPeerInterviewRequest`, `StorePartnerFeedbackRequest`, `ExportCoachReportRequest`). The non-participant 404 (and the `view` policy for daily logs) moved into `authorize()` so it still wins over validation. Only remaining order change: if the Week 4 position-2 activity is missing, an over-long waiver `reason` now gets a validation error instead of 404.
- [x] `ValidationLabController` — lines 47, 62, 120, 252. Three Form Requests: `StoreValidationArtifactRequest` (`artifact`; the stricter second-stage rules run in an `after()` hook only when the base rules pass and the request is not the legacy name-only start, so the error set is unchanged), `StoreValidationSubmissionRequest` (`submit`; the `artifact_name` trim moved to `prepareForValidation`), `VerifyLabOtpRequest` (`verifyOtp`). No pre-validation checks existed, so no order changes.
- [x] `AdminInterviewSchedulingController` — 72, 87, 97. Three Form Requests: `SaveInterviewSchedulerRequest` (shared by `store` and `update`, as the old private `validated()` helper was; the post-validation `booking_url`/`is_active`/`display_order` defaults stay in the controller's `interviewerAttributes()`), `UploadInterviewPageLogoRequest` (`uploadLogo`), `UpdateInterviewPagePublicTitleRequest` (`updatePublicTitle`). `authorize()` mirrors the route's `role:admin`. No pre-validation checks existed (the `{interviewer}` 404 still comes from route binding first), so no order changes.
- [x] `AttendanceController` — 31, 69, 77. Two Form Requests: `CheckInAttendanceRequest` (`checkIn`; the safe-URL `return_to` check stays after validation in the controller, as before) and `FilterAttendanceSessionsRequest` (shared by `manage` and `export`, which had identical rules). `authorize()` mirrors the routes' `role:participant` and `role:coach,admin`/`role:admin` middleware, which still runs first. No pre-validation checks existed, so no order changes.
- [x] `AdminUserController` — 33, 76. Two Form Requests: `FilterAdminUsersRequest` (`index`) and `UpdateParticipantAssignmentsRequest` (`assignments`). The non-participant 404 moved into `authorize()` so it still wins over validation (covered by a new test). `authorize()` mirrors the route's `role:admin`. No other order changes.
- [x] `MentorInterviewAvailabilityController` — 29, 38. Two Form Requests: `StoreMentorInterviewAvailabilityRequest` (`store`) and `UpdateMentorInterviewAvailabilityRequest` (`update`). `authorize()` mirrors the route's `role:coach,admin`. The ownership check (`MentorInterviewAvailabilityService::authorizeAccess`) stays in the service after validation, so another mentor's invalid input still gets validation errors before the 403 (covered by new tests). No order changes.
- [x] `RecoveryRequestController` — 19, 35. Two Form Requests: `StoreRecoveryRequestRequest` (`store`) and `DecideRecoveryRequestRequest` (`decide`). The owner/failed-status 403 and, for `decide`, the not-pending 422 followed by the `canManageParticipant` 403 moved into `authorize()` in their original order, so they still win over validation. For `store`, the "already pending" redirect also ran before validation: `rules()` returns no rules when a pending request exists (memoized `hasPendingRecoveryRequest()`, which the controller reuses for the redirect), so an invalid `message` still gets the same status redirect. All four paths are covered by new tests. No order changes.
- [x] `ScoringController` — 29, 83. Two Form Requests: `FilterScoringParticipantsRequest` (`index`; `authorize()` mirrors the route's `role:coach,admin`) and `ImproveScoringFeedbackRequest` (`improveFeedback`). The draft/non-participant 404 followed by the `canManageParticipant` 403 moved into `authorize()` in their original order, so they still win over validation (covered by new tests). The only visible difference: the 403 JSON body now carries Laravel's "This action is unauthorized." message instead of an empty one. No order changes.
- [x] `WeekFourMentorInterviewController` — 21, 33. One Form Request, `ScheduleMentorInterviewRequest`, shared by `store` and `update` (identical rules and messages). The activity-not-in-this-week 404 moved into `authorize()` so it still wins over validation (covered by a new test). `authorize()` otherwise returns true; the route's `role:participant` still runs first. No order changes.
- [x] Single-call controllers: `AdminAiSettingsController` (31), `DailyLogExportController` (14), `DailyLogReportController` (20), `GoogleDriveDestinationController` (24), `MarkdownPreviewController` (13), `MentorBookingCalendarController` (27), `MissingSubmissionReportController` (50), `ParticipantNotificationController` (18), `PublicInterviewController` (44), `ReusableAssetLibraryController` (23), `ScoringReportController` (21), `WeekThreeWorkspaceController` (15), `WeeklyVideoRecordingController` (41). Fourteen Form Requests: `TestAiSettingsRequest`, `ExportDailyLogsRequest`, `FilterDailyLogReportRequest` (shared by `index`, `export`, `details`), `StoreGoogleDriveDestinationRequest`, `PreviewMarkdownRequest`, `StoreMentorBookingCalendarRequest`, `FilterMissingSubmissionsRequest` (`index`, `export`), `FilterParticipantNotificationsRequest`, `StoreInterviewerRegistrationRequest`, `FilterReusableAssetsRequest`, `FilterScoringReportRequest` (`index`, `scores`, `completion`) plus `ExportParticipantScoreSummaryRequest` (extends it for `participantSummary`), `UpdateWeekThreeWorkspaceRequest`, `StoreWeeklyVideoRecordingRequest`. The filter rules differ between the Daily Log, missing-submission and scoring reports (and from `FilterScoringParticipantsRequest`), so none are shared across controllers. Pre-validation checks kept in order: the training-role 403 (`FilterReusableAssetsRequest`), the participant-role 403 (`UpdateWeekThreeWorkspaceRequest`), and the week-access 403 followed by the locked-after-Gate 422 (`StoreWeeklyVideoRecordingRequest`) moved into `authorize()`; the public registration rate limit is counted in `prepareForValidation()` and a limited request skips validation so the controller still returns the "Too many submissions" redirect; the participant summary skips validation when no program is active so the `firstOrFail()` 404 still wins. Each path is covered by a new test. Only remaining order change: `ParticipantNotificationController::index` now validates `filter` before `ParticipantNotificationService::sync()`, so a request with an invalid filter no longer syncs before its validation redirect (the next valid page load syncs as before). No `->validate(` / `Validator::make` calls remain in `app/Http/Controllers`.

Line numbers are from the audit on 2026-10-20 and will drift; search for `->validate(` / `Validator::make` to find the current locations.

---

## Phase 3 — N+1 queries (medium, low–medium risk)

Replace per-row queries with eager loading or single aggregate queries. For each hotspot, add a feature test that fails if the query count grows with the number of rows (e.g. compare `DB::getQueryLog()` counts for 1 vs 3 records).

- [ ] `ParticipantDashboardController` (lines ~40–109) — per-activity `submissions()` queries (twice), per-week daily-log count and video lookup, per-activity Validation Lab `exists()`, per-gate readiness. Highest-traffic page; do this first.
- [ ] `CoachOperationsController` (~63)
- [ ] `ScoringReportController` (~39, ~53)
- [ ] `DailyLogReportController` (~61)
- [ ] `ReusableAssetLibraryController` (~26)

---

## Phase 4 — Slim controllers (medium, medium risk)

Move business logic into services (existing ones where they fit); controllers keep only the HTTP concerns. Do this after Phases 2–3 so the code being moved is already clean.

- [ ] `CoachOperationsController` (362 lines) — split by area if it helps (e.g. participants, reviews, Week 4 pairing), each delegating to services.
- [ ] `SubmissionEvidenceController` (347 lines)
- [ ] `ValidationLabController` (210 lines)
- [ ] `ActivitySubmissionController` (242 lines)

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
- [ ] `DailyLogReportController::details` repeats the whole filter query from `rows` (only the ordering and pagination differ). Extract the shared filter constraints (e.g. a `DailyLog` scope or a service method) when this controller is slimmed or moved to a Form Request?
- [ ] Four methods in `app/` still have untyped parameters (closures are all typed now): `ScoringService::weekly($week)`, `ScoringService::summary($program)`, `ScoringService::runningAverage($exceptVersion)` and `ActivitySubmissionController::carryForwardLabRecord($labRecords)`. Add a Phase 1 item to type them?
- [ ] `ValidationLabController::artifact` still returns `back()->withErrors()` when the "Other" feature area / artifact type or the artifact name is blank after trimming. With the global `TrimStrings` and `ConvertEmptyStringsToNull` middleware, whitespace-only input already fails `required_if` / `required` in `StoreValidationArtifactRequest`, so these branches look unreachable. Remove them (or fold them into the Form Request) when the controller is slimmed in Phase 4?
- [ ] `MentorInterviewAvailabilityController::store` passes the validated `mentor_id` into `MentorInterviewAvailabilityService::create`, where `$data + ['mentor_id' => $mentor->id]` keeps the request's value. A coach who posts another coach's `mentor_id` therefore creates availability owned by that coach, and an admin who omits `mentor_id` gets a 500 (undefined array key). Drop `mentor_id` from the data passed to the service and make it `required` for admins?
- [ ] `POST /interviews/register` has both `throttle:5,10` route middleware and an in-request limiter (`StoreInterviewerRegistrationRequest`, 5 attempts per 600 s per IP). The middleware answers the sixth request with a 429 first, so the friendlier "Too many submissions" redirect is effectively unreachable. Keep one of them (drop the middleware for the redirect, or drop the in-request limiter)?

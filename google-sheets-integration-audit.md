# 1. Executive Summary

The audit found that Laravel is the authoritative source for all training, submission, review, evidence, daily-log, Gate, and progression state.

No Google integration currently exists. No Google packages, credentials, spreadsheets, synchronization jobs, or Google-specific database structures were found.

No files, schema, seed data, or records were modified.

The core synchronization grain should be:

```text
Participant
→ Week
→ Activity
→ ActivitySubmission attempt
→ SubmissionVersion
→ Requirement / answer field
→ Evidence
→ Review
```

Attempts and versions must remain separate in the future reporting mirror.

The current implementation supports:

- Weeks 2–4 only
- nine official activities
- Manual and Automation participant paths
- multiple ActivitySubmission attempts
- immutable SubmissionVersions after submission
- revision versions for `needs_revision`
- coach-created retry attempts after failure
- participant recovery requests after failure
- evidence associated with submission versions
- coach reviews attached to submission versions
- daily logs associated with participants and optional activities
- explicit Gate evaluations and criterion evaluations
- server-side sequential progression

# 2. Existing Data Model

## Users / participants

Table: `users`

Relevant fields:

| Field | Meaning |
|---|---|
| `id` | Participant identity |
| `name` | Participant name |
| `email` | Login/email identity |
| `training_path` | `manual` or `automation` |
| `role` | `participant`, `coach`, or `admin` |
| `created_at` | Account creation time |
| `updated_at` | Account update time |

Laravel model: `App\Models\User`

Relationships:

- `activitySubmissions()`
- `dailyLogs()`
- `gateEvaluations()`
- `reviews()` as reviewer

Passwords and authentication fields must not be synchronized to Sheets.

## Program

Table: `programs`

Fields:

- `id`
- `name`
- `description`
- `is_active`
- timestamps

Current seeded program:

```text
AI-Assisted QA Level 3
```

## Training weeks

Table: `training_weeks`

Fields:

- `id`
- `program_id`
- `week_number`
- `title`
- `description`
- `position`
- `is_active`
- timestamps

Current participant weeks:

- Week 2
- Week 3
- Week 4

There is no Week 1 participant activity and no Week 5 activity in the current seeder.

## Activities

Table: `activities`

Fields:

- `id`
- `training_week_id`
- `title`
- `description`
- `objectives`
- `instructions`
- `configuration` JSON
- `position`
- `requires_coach_review`
- `is_active`
- timestamps

The `configuration` JSON currently contains activity-specific configuration such as:

```json
{
  "lab_route": "lab.activity"
}
```

This is used by Week 2 Activity 1.

## Activity requirements

Table: `activity_requirements`

Fields:

- `id`
- `activity_id`
- `participant_path`
- `requirement_type`
- `title`
- `description`
- `is_required`
- `position`
- `configuration` JSON
- timestamps

Requirement types currently used include:

- `submission_field`
- `deliverable`
- `path_guidance`

Submission fields are defined inside `configuration`, for example:

```json
{
  "key": "exact_ai_output",
  "label": "Exact AI output that was wrong",
  "type": "textarea"
}
```

The participant’s answer is ultimately stored using the configured `key`.

## Evidence requirements

Table: `evidence_requirements`

Fields:

- `id`
- `activity_requirement_id`
- `title`
- `description`
- `evidence_type`
- `is_required`
- `minimum_count`
- `maximum_count`
- `configuration` JSON
- `position`
- timestamps

The seeder creates, for each activity:

1. One required deliverable evidence requirement.
2. One optional supporting link requirement.

The required evidence type varies by activity.

## Activity submissions / attempts

Table: `activity_submissions`

Current fields:

- `id`
- `activity_id`
- `user_id`
- `attempt_number`
- `status`
- `submitted_at`
- `completed_at`
- timestamps

The retry migration removed the original unique `(activity_id, user_id)` constraint and added:

```text
attempt_number
```

This means multiple attempts are now represented as separate `ActivitySubmission` records.

Laravel model: `App\Models\ActivitySubmission`

Statuses currently used include:

- `draft`
- `submitted`
- `under_review`
- `needs_revision`
- `resubmitted`
- `approved`
- `failed`
- `completed`

An ActivitySubmission is the attempt-level record.

## Submission versions

Table: `submission_versions`

Fields:

- `id`
- `activity_submission_id`
- `validation_submission_id`
- `version_number`
- `status`
- `field_values` JSON
- `participant_note`
- `submitted_at`
- timestamps

Laravel model: `App\Models\SubmissionVersion`

This is where participant answers are stored.

The `field_values` JSON contains the configured activity requirement keys and participant responses.

Example conceptual value:

```json
{
  "exact_ai_output": "...",
  "actual_truth": "...",
  "detection_and_action": "..."
}
```

The same answer field may therefore have different values in different versions.

## Submission evidence

Table: `submission_evidence`

Evidence belongs to a `SubmissionVersion`, not directly to an ActivitySubmission or Activity.

Fields:

- `id`
- `submission_version_id`
- `evidence_requirement_id`
- `evidence_type`
- `title`
- `content`
- `url`
- `disk`
- `path`
- `original_filename`
- `mime_type`
- `size_bytes`
- `metadata` JSON
- timestamps

## Submission reviews

Table: `submission_reviews`

Fields:

- `id`
- `submission_version_id`
- `reviewer_id`
- `decision`
- `comments`
- `reviewed_at`
- timestamps

Reviews belong to a specific immutable submission version.

## Daily logs

Table: `daily_logs`

Fields:

- `id`
- `user_id`
- `activity_id` nullable
- `logged_on`
- `content`
- timestamps

There are no separate database columns for blocker or next action. Those concepts are currently represented, if at all, inside the free-text `content`.

## Gates

Table: `training_gates`

Fields:

- `id`
- `training_week_id`
- `title`
- `description`
- `position`
- `is_active`
- timestamps

## Gate criteria

Table: `gate_criteria`

Fields:

- `id`
- `training_gate_id`
- `criterion_type`
- `title`
- `description`
- `is_required`
- `position`
- `configuration` JSON
- timestamps

## Gate evaluations

Table: `gate_evaluations`

Fields:

- `id`
- `training_gate_id`
- `user_id`
- `status`
- `reviewer_id`
- `evaluated_at`
- `comments`
- timestamps

There is a unique `(training_gate_id, user_id)` constraint.

## Gate criterion evaluations

Table: `gate_criterion_evaluations`

Fields:

- `id`
- `gate_evaluation_id`
- `gate_criterion_id`
- `status`
- `notes`
- `reviewer_id`
- `evaluated_at`
- timestamps

Criterion evaluations are attached to the participant’s GateEvaluation.

## Activity recovery requests

Table: `activity_recovery_requests`

Fields:

- `id`
- `user_id`
- `activity_submission_id`
- `status`
- `message`
- `coach_response`
- `reviewed_by`
- `requested_at`
- `reviewed_at`
- timestamps

Current statuses:

- `pending`
- `approved`
- `declined`
- `cancelled` is suggested by the requested model design but is not currently used by the implementation.

This is separate from SubmissionReview.

## Validation Lab records

Table: `validation_submissions`

Fields include:

- `id`
- `user_id`
- `trainee_name`
- `artifact_code`
- `source_status`
- `source_notes`
- `scope_status`
- `scope_notes`
- `spec_status`
- `spec_notes`
- `cross_check_status`
- `cross_check_notes`
- `run_status`
- `run_notes`
- `log_status`
- `log_notes`
- `finding`
- `decision`
- `evidence_notes`
- timestamps

The six-check data is stored in columns rather than JSON.

Week 2 Activity 1 links to one Lab record through:

```text
submission_versions.validation_submission_id
```

The same value is also retained in the version’s `field_values` when the configured field is submitted.

The relationship is singular: one selected Lab record per submission version.

# 3. Activity-by-Activity Answer Mapping

The exact runtime numeric Activity IDs depend on the current database’s auto-increment state. The implementation defines them by `(training_week_id, position)`, not by hard-coded external IDs.

The activity IDs therefore should be resolved at synchronization time from the database.

All current submission-field requirements are path-neutral. Manual and Automation differences are represented through `path_guidance` requirements rather than separate answer-field sets.

## Week 2 — Validation Checklist

Activity title:

```text
Validation Checklist
```

Configuration:

```json
{
  "lab_route": "lab.activity"
}
```

| Requirement / field | Answer key | Storage | Type | Versioned |
|---|---|---|---|---|
| Real AI artifact and work context | `artifact_context` | `submission_versions.field_values` | textarea/text | Yes |
| Source, Scope, Spec, Cross-check, Run, and Log outcomes | `checklist_outcomes` | `submission_versions.field_values` | textarea/text | Yes |
| Concrete finding and resulting decision | `finding_and_decision` | `submission_versions.field_values` | textarea/text | Yes |
| Existing QA Lab validation record ID | `validation_submission_id` | `submission_versions.validation_submission_id` and `field_values` | selected ID/text | Yes |

Evidence:

- Required: `Validation evidence or reference`, configured as `text`
- Optional: `Optional supporting link or file`, configured as `link`

Path guidance:

- Manual: validate test steps, expected results, requirements, real behavior, evidence
- Automation: validate selectors, assertions, scripts, framework conventions, execution, evidence

The Lab record itself contains the six-check structured data.

## Week 2 — AI Output Error Detection

Fields:

| Requirement / field | Answer key | Storage | Type | Versioned |
|---|---|---|---|---|
| Exact AI output that was wrong | `exact_ai_output` | `submission_versions.field_values` | textarea/text | Yes |
| What was actually true | `actual_truth` | `submission_versions.field_values` | textarea/text | Yes |
| How it was caught and what happened afterward | `detection_and_action` | `submission_versions.field_values` | textarea/text | Yes |

Evidence:

- Required: `Proof of the AI-wrong case`, configured as `text`
- Optional: supporting `link`

Path guidance differs for Manual and Automation, but answer fields are shared.

## Week 2 — Pre-acceptance Check

Fields:

| Requirement / field | Answer key | Storage | Type | Versioned |
|---|---|---|---|---|
| Higher-stakes AI-generated test, fix, or QA change | `higher_stakes_change` | `submission_versions.field_values` | textarea/text | Yes |
| What it touches and where it runs | `affected_scope` | `submission_versions.field_values` | textarea/text | Yes |
| Validation performed and result | `validation_outcome` | `submission_versions.field_values` | textarea/text | Yes |

Evidence:

- Required: `Pre-acceptance validation evidence`, configured as `text`
- Optional: supporting `link`

## Week 3 — Test-Failure Root-Cause Analysis

Fields:

| Requirement / field | Answer key | Storage | Type | Versioned |
|---|---|---|---|---|
| Five failed runs and evidence-backed analysis | `failed_runs` | `submission_versions.field_values` | textarea/text | Yes |
| Shared cause or explicit no-shared-cause conclusion | `shared_cause` | `submission_versions.field_values` | textarea/text | Yes |
| Severity, blast radius, affected areas, and next diagnostic step | `impact_next_step` | `submission_versions.field_values` | textarea/text | Yes |

Evidence:

- Required: `RCA report (PDF)`, configured as `file`
- Optional: supporting `link`

## Week 3 — Script from Description

Fields:

| Requirement / field | Answer key | Storage | Type | Versioned |
|---|---|---|---|---|
| Real requirement and Jira-to-test mapping | `requirement_mapping` | `submission_versions.field_values` | textarea/text | Yes |
| AI-generated test, steps, assertions, framework conventions | `generated_test` | `submission_versions.field_values` | textarea/text | Yes |
| Execution evidence and what was fixed/rejected | `execution_investigation` | `submission_versions.field_values` | textarea/text | Yes |

Evidence:

- Required: `Executed AI-assisted test and write-up`, configured as `file`
- Optional: supporting `link`

## Week 3 — Regression Prioritization and Coverage Gaps

Fields:

| Requirement / field | Answer key | Storage | Type | Versioned |
|---|---|---|---|---|
| Real PR changed files and requirements reviewed | `pr_and_requirements` | `submission_versions.field_values` | textarea/text | Yes |
| Validated regression priorities and flaky-test analysis | `regression_priorities` | `submission_versions.field_values` | textarea/text | Yes |
| Coverage gaps and missing scenarios | `coverage_gaps` | `submission_versions.field_values` | textarea/text | Yes |

Evidence:

- Required: `Regression prioritization and coverage-gap report`, configured as `file`
- Optional: supporting `link`

## Week 4 — Build a Reusable Asset

Fields:

| Requirement / field | Answer key | Storage | Type | Versioned |
|---|---|---|---|---|
| Asset name, purpose, audience, when to use | `asset_name_purpose` | `submission_versions.field_values` | textarea/text | Yes |
| Inputs, instructions/prompt/checklist, expected output | `asset_inputs_instructions` | `submission_versions.field_values` | textarea/text | Yes |
| Validation notes and second-task evidence | `asset_validation_reuse` | `submission_versions.field_values` | textarea/text | Yes |
| Team-library location or link | `team_library_location` | `submission_versions.field_values` | URL | Yes |

Evidence:

- Required: `Reusable asset in MD format`, configured as `file`
- Optional: supporting `link`

## Week 4 — Mock Level 3 Interview

Fields:

| Requirement / field | Answer key | Storage | Type | Versioned |
|---|---|---|---|---|
| Full Interview Guide answers grounded in real work | `interview_evidence` | `submission_versions.field_values` | textarea/text | Yes |
| Partner feedback on at least two answers | `partner_feedback` | `submission_versions.field_values` | textarea/text | Yes |
| QA Coach feedback on at least one answer | `coach_feedback` | `submission_versions.field_values` | textarea/text | Yes |
| Daily-log entry reference | `daily_log_reference` | `submission_versions.field_values` | text | Yes |

Evidence:

- Required: `Completed mock interview and feedback notes`, configured as `text`
- Optional: supporting `link`

## Week 4 — Formal Reassessment

Fields:

| Requirement / field | Answer key | Storage | Type | Versioned |
|---|---|---|---|---|
| Strict-mode Leveling interview transcript | `strict_interview_transcript` | `submission_versions.field_values` | textarea/text | Yes |
| Assessment tracker result appended | `assessment_tracker_result` | `submission_versions.field_values` | textarea/text | Yes |
| Updated level and confidence | `updated_level_confidence` | `submission_versions.field_values` | textarea/text | Yes |

Evidence:

- Required: `Strict-mode transcript and tracker result`, configured as `file`
- Optional: supporting `link`

# 4. Attempt vs Version vs Revision Model

## Attempt

An attempt is an `activity_submissions` row.

Example:

```text
ActivitySubmission #10
activity_id = 2
user_id = 5
attempt_number = 1
status = failed
```

A retry is another `activity_submissions` row:

```text
ActivitySubmission #11
activity_id = 2
user_id = 5
attempt_number = 2
status = draft
```

The retry has independent versions, evidence, and reviews.

## Version

A version is a `submission_versions` row associated with one ActivitySubmission.

Example:

```text
Attempt 2
  SubmissionVersion 1 = submitted
  SubmissionVersion 2 = resubmitted/revision
```

Version numbers are unique only within the parent ActivitySubmission.

## Revision

A `needs_revision` decision creates another SubmissionVersion under the same ActivitySubmission.

The previous submitted version remains immutable.

## Failed attempt

A failed attempt remains a terminal ActivitySubmission with immutable submitted versions.

A coach may create a new ActivitySubmission retry.

## Current active attempt

The current implementation generally treats the latest attempt by `attempt_number` as authoritative.

The latest-attempt Gate rule is implemented in `WeekTwoGateService`.

## Gate satisfaction

For each required activity, the service selects the latest ActivitySubmission.

The latest attempt must have one of:

- `submitted`
- `resubmitted`
- `approved`
- `completed`

If the latest attempt is:

- `draft`
- `under_review`
- `needs_revision`
- `failed`

the activity is not Gate-ready.

Required evidence is evaluated only against the latest attempt’s eligible version.

# 5. Evidence Model

Evidence belongs to:

```text
SubmissionVersion
```

It also references the configured:

```text
EvidenceRequirement
```

Evidence forms currently support:

- text content
- URLs
- repository links
- video/recording links
- uploaded files
- screenshots/documents/logs/reports

Stored file metadata includes:

- internal evidence ID
- private storage path
- original filename
- MIME type
- file size
- evidence type

The private storage path must not be synchronized directly into Sheets.

Recommended future Sheet representation:

- evidence ID
- submission version ID
- requirement ID
- evidence type
- title
- original filename
- MIME type
- size
- authorized application evidence URL
- URL evidence where applicable
- content only when safe and appropriate

Do not publish private filesystem paths.

The current application serves protected downloads through:

```text
/training/evidence/{submissionEvidence}/download
```

Authorization is handled through `SubmissionEvidencePolicy`.

# 6. Review Model

Reviews are stored in `submission_reviews`.

Each review includes:

- review ID
- submission version ID
- reviewer ID
- decision
- comments
- reviewed timestamp
- created/updated timestamps

The three operational decisions are represented as:

| Training meaning | Request value | Parent submission status |
|---|---|---|
| Passed | `approved` | `approved` |
| Revise | `needs_revision` | `needs_revision` |
| Failed | `rejected` | `failed` |

The UI labels the last decision as rejection/failure, but the persisted decision is `rejected`.

A future Sheet should preserve both:

- canonical database decision value
- human-readable decision label

Recovery requests are separate from SubmissionReviews.

# 7. Daily Log Model

Daily logs are stored in `daily_logs`.

Fields:

- participant
- optional activity
- logged date
- free-text content
- created timestamp
- updated timestamp

There are no dedicated blocker or next-action columns.

Those values are `NOT DETERMINED FROM CURRENT IMPLEMENTATION` as separate structured fields. If present, they are embedded in `content`.

Daily logs are participant-owned and coach-visible through authorized operational views.

The current Gate readiness service counts daily logs by participant and activity’s training week.

# 8. Gate Model

## Week 2 Gate

Criteria:

1. Daily-log entries for all three sessions
2. One complete six-check validation run
3. One concrete AI-wrong case with evidence
4. One pre-acceptance check
5. Checklist recall and coach review passed

## Week 3 Gate

Criteria:

1. Tracker complete with validation outcomes
2. RCA PDF covering five failed runs
3. Executed AI-assisted test with evidence
4. Regression prioritization and coverage-gap report
5. Loom walkthrough explaining prompts and iterations
6. Drive folder complete with the full packet

## Week 4 Gate

Criteria:

1. Validation Checklist use visible across Weeks 2–4
2. Strict-mode Leveling interview completed
3. Reusable asset live in team library
4. Reassessment result appended to tracker
5. Next dashboard build reflects updated level/confidence
6. Assessment result is Level 3
7. Confidence is Medium or higher
8. Workflow Integration score at least 3
9. Validation score at least 3
10. Concrete “yesterday” example
11. No Rule-5 red-flag phrase
12. QA Coach Gate review passed

Gate readiness is computed by `WeekTwoGateService`.

Gate evaluations are stored in `gate_evaluations`.

Criterion evaluations are stored in `gate_criterion_evaluations`.

Gate status is authoritative only when evaluated by an authorized coach/admin.

# 9. Progression Model

`WeekProgressionService` controls access.

## Week 2 → Week 3

Week 3 is accessible only when all prior Week 2 Gates have an evaluation with:

```text
status = passed
```

## Week 3 → Week 4

Week 4 is accessible only when all prior Week 3 Gates have an evaluation with:

```text
status = passed
```

## Program completion

Program completion requires every active week’s Gate to be passed.

The following do not directly unlock a week:

- spreadsheet values
- activity opening
- activity draft
- activity approval alone
- evidence alone
- readiness alone

The explicit GateEvaluation remains authoritative.

# 10. Proposed Google Sheets Workbook Structure

A normalized workbook is preferable to one giant participant/activity sheet.

Recommended sheets:

1. `Participants`
2. `Programs`
3. `Weeks`
4. `Activities`
5. `Activity Requirements`
6. `Evidence Requirements`
7. `Attempts`
8. `Submission Versions`
9. `Answers`
10. `Evidence`
11. `Reviews`
12. `Recovery Requests`
13. `Daily Logs`
14. `Gates`
15. `Gate Criteria`
16. `Gate Evaluations`
17. `Gate Criterion Evaluations`
18. `Validation Lab`
19. `Sync Log`

This structure preserves attempt/version distinctions and supports relational traceability.

# 11. Recommended Sheet Columns

## Participants

- `participant_id`
- `name`
- `email`
- `training_path`
- `role`
- `created_at`
- `updated_at`

Exclude password and authentication secrets.

## Programs

- `program_id`
- `name`
- `description`
- `is_active`

## Weeks

- `week_id`
- `program_id`
- `week_number`
- `title`
- `description`
- `position`
- `is_active`

## Activities

- `activity_id`
- `week_id`
- `week_number`
- `position`
- `title`
- `description`
- `objectives`
- `instructions`
- `configuration_json`
- `requires_coach_review`
- `is_active`

## Activity Requirements

- `requirement_id`
- `activity_id`
- `participant_path`
- `requirement_type`
- `title`
- `description`
- `is_required`
- `position`
- `configuration_json`

## Evidence Requirements

- `evidence_requirement_id`
- `activity_requirement_id`
- `activity_id`
- `title`
- `description`
- `evidence_type`
- `is_required`
- `minimum_count`
- `maximum_count`
- `position`
- `configuration_json`

## Attempts

- `activity_submission_id`
- `participant_id`
- `activity_id`
- `week_id`
- `week_number`
- `attempt_number`
- `status`
- `submitted_at`
- `completed_at`
- `created_at`
- `updated_at`

## Submission Versions

- `submission_version_id`
- `activity_submission_id`
- `participant_id`
- `activity_id`
- `attempt_number`
- `version_number`
- `status`
- `participant_note`
- `submitted_at`
- `created_at`
- `updated_at`
- `validation_submission_id`

## Answers

One row per answer field:

- `answer_id` — future synchronization identifier or deterministic key
- `participant_id`
- `week_id`
- `week_number`
- `activity_id`
- `activity_title`
- `activity_submission_id`
- `attempt_number`
- `submission_version_id`
- `version_number`
- `requirement_id`
- `requirement_key`
- `requirement_title`
- `participant_path`
- `answer_type`
- `answer_value`
- `is_required`
- `version_status`
- `submitted_at`
- `created_at`

The answer value comes from:

```text
submission_versions.field_values[requirement.configuration.key]
```

The Sheet must preserve the source JSON field key.

## Evidence

- `evidence_id`
- `submission_version_id`
- `activity_submission_id`
- `participant_id`
- `activity_id`
- `attempt_number`
- `version_number`
- `evidence_requirement_id`
- `evidence_type`
- `title`
- `content` where appropriate
- `url`
- `original_filename`
- `mime_type`
- `size_bytes`
- `authorized_application_url`
- `created_at`
- `updated_at`

Do not include private disk/path values.

## Reviews

- `review_id`
- `submission_version_id`
- `activity_submission_id`
- `participant_id`
- `activity_id`
- `attempt_number`
- `version_number`
- `reviewer_id`
- `reviewer_name`
- `decision`
- `decision_label`
- `comments`
- `reviewed_at`
- `created_at`
- `updated_at`

## Recovery Requests

- `recovery_request_id`
- `activity_submission_id`
- `participant_id`
- `activity_id`
- `attempt_number`
- `status`
- `participant_message`
- `coach_response`
- `reviewed_by`
- `requested_at`
- `reviewed_at`
- `created_at`
- `updated_at`

## Daily Logs

- `daily_log_id`
- `participant_id`
- `activity_id`
- `week_id`
- `week_number`
- `logged_on`
- `content`
- `created_at`
- `updated_at`

## Gates

- `gate_id`
- `week_id`
- `week_number`
- `title`
- `description`
- `position`
- `is_active`

## Gate Criteria

- `gate_criterion_id`
- `gate_id`
- `week_id`
- `criterion_type`
- `title`
- `description`
- `is_required`
- `position`
- `configuration_json`

## Gate Evaluations

- `gate_evaluation_id`
- `gate_id`
- `participant_id`
- `status`
- `reviewer_id`
- `reviewer_name`
- `evaluated_at`
- `comments`
- `created_at`
- `updated_at`

## Gate Criterion Evaluations

- `gate_criterion_evaluation_id`
- `gate_evaluation_id`
- `gate_criterion_id`
- `participant_id`
- `status`
- `notes`
- `reviewer_id`
- `evaluated_at`
- `created_at`
- `updated_at`

## Validation Lab

- `validation_submission_id`
- `participant_id`
- `trainee_name`
- `artifact_code`
- all six check statuses
- all six check notes
- `finding`
- `decision`
- `evidence_notes`
- `created_at`
- `updated_at`

## Sync Log

Recommended future fields:

- `sync_run_id`
- `entity_type`
- `entity_id`
- `operation`
- `payload_hash`
- `sheet_name`
- `sheet_row_key`
- `status`
- `attempt_count`
- `last_error`
- `started_at`
- `completed_at`

# 12. Laravel-to-Sheet Mapping

| Laravel source | Sheet | Columns |
|---|---|---|
| `users` | Participants | participant identity/path/role |
| `programs` | Programs | program metadata |
| `training_weeks` | Weeks | week metadata |
| `activities` | Activities | official activity content/configuration |
| `activity_requirements` | Activity Requirements | questions/requirements/path |
| `evidence_requirements` | Evidence Requirements | evidence expectations |
| `activity_submissions` | Attempts | one row per attempt |
| `submission_versions` | Submission Versions | one row per version |
| `submission_versions.field_values` | Answers | one row per configured answer field |
| `submission_evidence` | Evidence | one row per evidence item |
| `submission_reviews` | Reviews | one row per review decision |
| `activity_recovery_requests` | Recovery Requests | one row per recovery request |
| `daily_logs` | Daily Logs | one row per log |
| `training_gates` | Gates | Gate definitions |
| `gate_criteria` | Gate Criteria | criterion definitions |
| `gate_evaluations` | Gate Evaluations | participant Gate result |
| `gate_criterion_evaluations` | Gate Criterion Evaluations | criterion result |
| `validation_submissions` | Validation Lab | six-check Lab records |

# 13. Synchronization Architecture

Recommended architecture:

```text
Laravel transaction
    ↓
Domain event / outbox record
    ↓
Queued synchronization job
    ↓
Google Sheets API
    ↓
SyncLog result
```

Google writes must not occur inside participant submission transactions.

Recommended components:

- Laravel events for newly created/updated records
- queued jobs for Sheet writes
- scheduled reconciliation job
- manual coach/admin “Sync Now” operation
- periodic full reconciliation
- deterministic external row keys
- batch Google Sheets updates

Use a stable row key per record:

```text
attempt:{activity_submission_id}
version:{submission_version_id}
answer:{submission_version_id}:{requirement_key}
evidence:{submission_evidence_id}
review:{submission_review_id}
```

For immutable versions, synchronization should be append-oriented.

For mutable records such as draft attempts, current statuses, and Gate evaluations, update the corresponding row using the Laravel primary key.

# 14. Google Shared Drive Authentication Recommendation

Use a server-to-server architecture.

Recommended future approach:

- Google Cloud project
- Google Sheets API enabled
- Google Drive API enabled if Shared Drive file discovery/management is required
- service account or Workspace-approved server identity
- Shared Drive membership granted to the service account
- spreadsheet created in the Shared Drive
- spreadsheet ID stored in environment configuration

Participants should not individually authorize Google.

Credentials must be stored outside source control using environment secrets or a managed secret store.

Required future configuration will likely include:

```text
GOOGLE_SHEETS_ENABLED
GOOGLE_SHEETS_SPREADSHEET_ID
GOOGLE_DRIVE_SHARED_DRIVE_ID
GOOGLE_SERVICE_ACCOUNT_JSON
GOOGLE_SHEETS_SYNC_QUEUE
GOOGLE_SHEETS_SYNC_BATCH_SIZE
GOOGLE_SHEETS_SYNC_RETRY_LIMIT
```

Exact names are not currently defined and should be finalized during implementation.

# 15. Security Considerations

Do not synchronize:

- passwords
- remember tokens
- private storage disk names
- private storage filesystem paths
- credentials
- API tokens
- unfiltered secrets from JSON configuration

The reporting Sheet should be treated as privileged.

Access should be restricted to authorized coaches/admins and relevant Workspace users.

The application must continue enforcing:

- participant isolation
- private evidence authorization
- coach/admin route authorization
- Gate authority
- server-side progression

Evidence files should be represented using:

- evidence ID
- original filename
- evidence metadata
- authorized application URL

Do not publish direct storage URLs.

# 16. Idempotency and Failure Handling

The synchronization must tolerate:

- repeated sync jobs
- duplicate queue delivery
- Google API downtime
- partial batch failure
- participant resubmissions
- retry attempt creation
- new revisions
- review changes
- Gate evaluation changes

Recommended protections:

- deterministic row keys
- upsert-style writes
- payload hashes
- sync status records
- retryable exceptions
- exponential backoff
- dead-letter/failure reporting
- periodic reconciliation
- explicit deletion/tombstone handling if records can be deleted in future

Historical attempts and immutable versions should not be overwritten by later attempts.

# 17. Required Google API Dependencies

Future implementation may require:

- Google Sheets API client
- Google Drive API client if Shared Drive operations are needed
- Google authentication library
- Laravel queue infrastructure
- persistent sync-log storage
- optional Redis/database queue backend

No Google package was installed during this audit.

# 18. Existing Test Coverage

Current tests cover substantial workflow behavior, including:

- participant authentication and isolation
- coach/admin authorization
- activity submissions
- draft and submission lifecycle
- immutable versions
- revision workflow
- failed retry attempts
- evidence upload/download isolation
- invalid evidence files
- daily logs
- Gate readiness
- Gate evaluation
- sequential progression
- participant dashboard
- coach operations
- recovery requests
- data integrity
- seeded accounts

The current automated baseline observed during the audit was:

```text
74 tests
265 assertions
Pint passed
```

The exact test result can vary with the current working tree and database/test environment.

# 19. Additional Tests Required Before Google Sync Production Use

Minimum synchronization-specific tests:

1. Every seeded activity appears in the Activities sheet.
2. No Week 1 or Week 5 activities are exported.
3. Manual and Automation paths are preserved.
4. Every configured submission field becomes an Answer row.
5. JSON field keys map deterministically to Answer rows.
6. Multiple attempts produce separate Attempt rows.
7. Multiple versions produce separate Submission Version rows.
8. Failed attempts are not overwritten by successful retries.
9. Revision versions remain distinct.
10. Evidence maps to the correct version.
11. Private evidence paths are never exported.
12. URL evidence remains intact.
13. Reviews preserve reviewer, decision, feedback, and timestamp.
14. Recovery requests remain separate from submission reviews.
15. Daily logs map to participants and activities.
16. Gate readiness is reported from Laravel state.
17. Gate criterion evaluations are preserved.
18. Explicit Gate status is preserved.
19. Duplicate sync jobs do not create duplicate rows.
20. Failed Google API calls retry safely.
21. A submission succeeds when Google is unavailable.
22. Reconciliation repairs incomplete Sheet rows.
23. Unauthorized users cannot trigger privileged synchronization.
24. No participant secrets appear in payloads.
25. Existing application tests remain passing.

# 20. Risks and Gaps Discovered

## Activity IDs are runtime database IDs

The current implementation defines activities by week and position. The future integration should query actual IDs rather than assuming fixed numeric IDs.

## Answers are semi-structured

Participant answers are stored in `submission_versions.field_values` JSON, keyed by requirement configuration. The synchronization must join the JSON key to the corresponding `ActivityRequirement`.

## Path-specific answer fields are not currently distinct

Manual and Automation differences are primarily represented through `path_guidance` requirements. The current submission-field requirements are generally path-neutral.

## Daily logs are free text

Blocker and next-action fields are not separately structured.

## Gate readiness is not stored as a standalone immutable snapshot

It is calculated by `WeekTwoGateService` from current application state. The Sheet should record the synchronized calculated state but must not become authoritative.

## Recovery requests are separate from reviews

`ActivityRecoveryRequest` must not be merged into `SubmissionReview`.

## ActivitySubmission retries are now multiple rows

Any future query using `first()` without an explicit attempt policy is a synchronization risk.

## Evidence URL/file semantics

Evidence supports text, URLs, and private files. File content itself should not be copied into Sheets.

## Review decision naming

The UI terminology and database terminology differ:

- UI: Passed / Revise / Failed
- Database: `approved` / `needs_revision` / `rejected`

Both should be preserved in the mirror.

## Current database runtime IDs

A stable populated development dataset could not be reliably enumerated from the current audit shell without making assumptions about the active database state. Runtime numeric IDs are therefore:

```text
NOT DETERMINED FROM CURRENT IMPLEMENTATION
```

The synchronization must query them dynamically.

# 21. Recommended Implementation Phases

## Phase A — Read-only export design

- finalize workbook schema
- define stable row keys
- define field mapping
- define privacy rules
- define Sheets tab names

## Phase B — Laravel synchronization foundation

- add sync configuration
- add sync-log/outbox storage
- add Google client abstraction
- add authorization boundaries
- add dry-run mode

## Phase C — Metadata synchronization

- Participants
- Programs
- Weeks
- Activities
- Activity Requirements
- Evidence Requirements
- Gates
- Gate Criteria

## Phase D — Participant work synchronization

- Attempts
- Submission Versions
- Answers
- Evidence metadata
- Daily Logs
- Validation Lab records

## Phase E — Operational synchronization

- Submission Reviews
- Recovery Requests
- Gate Evaluations
- Gate Criterion Evaluations
- current readiness snapshots

## Phase F — Reliability

- queued jobs
- retries
- reconciliation
- idempotency
- partial-failure reporting
- manual Sync Now
- monitoring

## Phase G — Acceptance

- automated mapping tests
- authorization tests
- failure/retry tests
- Shared Drive permissions validation
- non-production browser verification
- controlled production-like sync dry run

No Google integration, credentials, packages, spreadsheets, migrations, or application changes were implemented during this audit.

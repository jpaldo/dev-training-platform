# QA VALIDATION LAB — MASTER BUILD PROMPT

You are working on an EXISTING Laravel 13 application that is being evolved into a real training platform for a QA training program.

Project:

C:\Users\Full Scale\Documents\qa-lab

IMPORTANT: This is NOT a blank Laravel project.

The existing application already contains a QA Validation Lab implementation and an existing training activity that is currently labeled "Week 1".

That existing "Week 1" activity is actually the existing implementation of **WEEK 2**.

Your job is to understand and evolve the existing application — NOT rebuild it from scratch.

---

# 1. PRIMARY OBJECTIVE

Transform the existing `qa-lab` Laravel application into a complete QA training platform that supports:

* Week 2
* Week 3
* Week 4
* Manual QA participants
* Automation QA participants
* participant accounts and profiles
* training activities
* activity instructions
* activity submissions
* drafts
* evidence uploads
* evidence links
* daily logs
* coach review
* revision requests
* Gates
* progress tracking
* completion tracking
* participant dashboards
* coach dashboards
* participant reports
* weekly reports
* activity reports
* Gate reports
* at-risk participant identification

The platform must support the actual training program described in the official Week 2, Week 3, and Week 4 PowerPoints.

Do NOT invent Week 1.

Do NOT invent Week 5 or later.

The official training sequence is:

**Week 2 → Week 3 → Week 4**

---

# 2. EXISTING APPLICATION — VERY IMPORTANT

The current `qa-lab` project already contains working files and functionality.

Before changing anything, inspect the existing application thoroughly.

Do NOT:

* create a new Laravel project
* replace the Laravel application
* delete the existing QA Lab
* rebuild existing functionality unnecessarily
* overwrite existing functionality blindly
* reset the database
* delete existing data
* run `php artisan migrate:fresh`
* run destructive database commands

Preserve existing functionality wherever possible.

The goal is to **extend and evolve** the existing QA Lab.

---

# 3. EXISTING "WEEK 1" IS ACTUALLY WEEK 2

This is a critical requirement.

The existing application contains a training activity currently labeled:

**Week 1**

This is NOT a real Week 1 activity.

It is the existing implementation of:

**Week 2**

Therefore:

* rename/relabel the existing Week 1 activity as Week 2
* preserve its functionality
* preserve its existing UI where practical
* preserve its existing validation workflow
* preserve its existing submission behavior
* preserve useful existing database structures
* do not create a duplicate Week 2
* do not create a new Week 1

Compare the existing implementation against the official Week 2 PowerPoint.

If the existing implementation already satisfies a Week 2 requirement, preserve it.

If something is missing or incorrect, extend or refactor it.

The existing Week 2 implementation should be the foundation for the new training platform.

---

# 4. OFFICIAL TRAINING SOURCE OF TRUTH

The official training PowerPoints are located in:

C:\Users\Full Scale\Documents\qa-lab\training-plans

The folder contains:

* Week 2 PowerPoint
* Week 3 PowerPoint
* Week 4 PowerPoint

These files are the source of truth for the participant training content.

You MUST inspect all three PowerPoints before implementing training-specific functionality.

Do not invent activities, deliverables, requirements, evidence, Gates, or participant expectations that are not supported by the training materials.

If the PowerPoints do not specify something, identify it as an implementation decision rather than pretending it came from the training materials.

---

# 5. TRAINING SCOPE

The training platform must represent exactly:

## Week 2

Use the official Week 2 PowerPoint.

The existing Week 1-labeled QA Validation Lab activity becomes Week 2.

The Week 2 implementation must support the actual requirements in the PowerPoint, including the validation workflow and required evidence/deliverables.

Known Week 2 concepts include:

* AI-assisted QA artifact validation
* six-check validation checklist:

  1. Source
  2. Scope
  3. Spec
  4. Cross-check
  5. Run
  6. Log
* identifying a concrete AI mistake
* pre-acceptance checking
* evidence of validation
* same-day logging
* coach review
* Week 2 Gate

Manual QA and Automation QA may use different artifact types, but the validation discipline must remain consistent.

---

## Week 3

Use the official Week 3 PowerPoint as the source of truth.

Implement the activities, deliverables, evidence, review requirements, and Gate requirements specified there.

Known Week 3 themes include:

* RCA
* AI-assisted test generation
* test execution
* regression prioritization
* coverage-gap analysis
* coach-reviewed Gate

For Automation QA participants, the workflow may involve:

* generating framework code
* reviewing generated code
* reusing helpers/page objects
* checking selectors
* checking assertions
* executing tests
* saving execution evidence

For Manual QA participants, support the corresponding manual QA workflow described by the training material.

Do not replace the Automation QA workflow with the platform.

---

## Week 4

Use the official Week 4 PowerPoint as the source of truth.

Implement the activities, deliverables, evidence, review requirements, and Gate requirements specified there.

Do not invent Week 4 content.

---

# 6. MANUAL QA VS AUTOMATION QA

The platform must support both participant types.

A participant should have a training path/type such as:

* Manual QA
* Automation QA

Shared platform capabilities should include:

* login
* profile
* dashboard
* training weeks
* activities
* instructions
* submissions
* evidence
* daily logs
* coach review
* Gates
* progress
* reports

However, activity requirements can differ between Manual QA and Automation QA.

---

# 7. AUTOMATION QA — MONICA REPOSITORY

The Automation QA training uses the **Monica repository/workspace** for actual automation development and execution.

Do NOT attempt to replace Monica with the QA Lab.

The QA Lab should act as the training management and validation platform around the automation work.

For Automation QA, the QA Lab should manage things such as:

* activity instructions
* expected deliverables
* submission forms
* evidence
* screenshots
* test reports
* execution results
* logs
* links
* repository URLs
* coach review
* progress
* Gates
* completion

Automation code should remain in the appropriate Monica repository/workspace.

The QA Lab should allow participants to submit evidence or links proving the required work was completed.

---

# 8. EVIDENCE SYSTEM

Evidence must be a first-class part of the platform.

Depending on the activity, participants may need to submit:

* text
* screenshots
* images
* PDF
* DOC
* DOCX
* TXT
* Markdown
* CSV
* JSON
* test reports
* execution logs
* code snippets
* video/Loom links
* external URLs
* repository URLs
* other files explicitly required by the training material

Design the evidence system so different activities can define different evidence requirements.

Do not hard-code every activity's evidence fields into controllers.

Use a configurable/database-driven approach where practical.

---

# 9. ACTIVITY ARCHITECTURE

The training content should be database-driven.

Prefer a structure conceptually similar to:

Program
→ Weeks
→ Activities
→ Activity Requirements
→ Participant Type
→ Evidence Requirements
→ Submission Fields
→ Review Requirements

The system should allow an activity to define:

* title
* description
* objectives
* instructions
* week
* order
* participant type
* deliverables
* requirements
* evidence requirements
* submission fields
* coach review requirement
* Gate association
* status

Avoid creating a separate hard-coded controller/view architecture for every activity unless there is a strong reason.

---

# 10. SUBMISSIONS

Participants need a proper submission workflow.

Support:

* draft
* submit
* submitted
* under review
* needs revision
* resubmitted
* approved
* rejected/failed
* completed

Do not allow a submitted record to be silently overwritten.

Where appropriate, maintain submission/revision history.

A coach should be able to see what was submitted and when.

Track timestamps.

---

# 11. COACH REVIEW

Coaches need to review participant work.

A coach should be able to:

* view participant
* view week
* view activity
* view submission
* view evidence
* review requirements
* add comments
* approve
* request revision
* mark failed where appropriate
* mark passed where appropriate

Participants should be able to see review status and feedback.

Use clear statuses such as:

* Not Started
* In Progress
* Awaiting Review
* Needs Revision
* Completed
* Passed
* Failed

---

# 12. GATES

The training program contains Gates.

Gates must be configurable rather than implemented as one giant hard-coded condition.

A Gate should be able to define criteria such as:

* required activities completed
* required evidence submitted
* required daily logs completed
* concrete AI-wrong case submitted
* validation checklist completed
* coach review passed
* required deliverables submitted

The exact Gate requirements must come from the official training PowerPoints.

The system should calculate/display:

* Gate status
* completed criteria
* incomplete criteria
* coach review status
* participant readiness

---

# 13. DAILY LOGS

Support daily training logs where required by the official training material.

A participant should be able to record relevant daily work such as:

* date
* activity
* work performed
* findings
* evidence
* notes
* blockers
* outcome

Do not invent unnecessary fields.

Use the PowerPoints to determine what must actually be captured.

---

# 14. PARTICIPANT DASHBOARD

Create a modern participant dashboard showing:

* participant name
* participant type
* current week
* overall progress
* Week 2 progress
* Week 3 progress
* Week 4 progress
* activity statuses
* pending reviews
* revision requests
* Gate status
* recent submissions
* recent coach feedback
* required next action

The dashboard should make it obvious:

**What have I completed?**

**What am I working on?**

**What needs coach review?**

**What needs revision?**

**What should I do next?**

---

# 15. COACH DASHBOARD

Create a coach dashboard showing the overall training cohort.

Include useful metrics such as:

* total participants
* completed
* in progress
* not started
* awaiting review
* needs revision
* passed
* failed
* at-risk participants

Provide filtering by:

* participant
* participant type
* week
* activity
* status
* Gate
* review status

Provide participant detail pages.

A coach should be able to quickly identify who needs attention.

---

# 16. REPORTING

Provide useful reports for coaches.

At minimum consider:

* participant completion report
* weekly completion report
* activity completion report
* coach review report
* Gate report
* at-risk report

Where useful, provide CSV export.

Do not create meaningless reports just to increase feature count.

Reports should reflect the actual training requirements.

---

# 17. UI / UX

The existing QA Validation Lab has an existing visual identity and UI.

Preserve useful existing design elements rather than throwing them away.

Evolve the interface into a professional QA academy/training platform.

The UI should clearly distinguish:

* participant experience
* coach experience
* training week
* activity
* submission
* evidence
* review
* Gate

Prioritize clarity over visual complexity.

The platform should work well on desktop and reasonable tablet widths.

---

# 18. SECURITY / AUTHORIZATION

Implement proper authorization.

Participants must only be able to access their own:

* profile
* submissions
* evidence
* progress
* reviews
* logs

Coaches should be able to access participant training information according to their role.

Do not rely solely on hidden UI elements for authorization.

Use Laravel authorization mechanisms such as policies/gates where appropriate.

Validate uploads.

Do not allow unsafe file uploads.

---

# 19. DATABASE

Use proper Laravel migrations.

Do not destroy existing data.

First understand the existing database.

Extend the schema carefully.

Prefer normalized relationships where appropriate.

Likely concepts include:

* users
* roles
* programs
* weeks
* activities
* activity requirements
* evidence requirements
* submissions
* submission versions/revisions
* evidence
* daily logs
* reviews
* Gates
* Gate criteria
* progress

Do not blindly create every table listed above.

Inspect the existing project first and design the final schema based on actual requirements.

---

# 20. EXISTING QA VALIDATION LAB

The existing QA Validation Lab is especially important.

Inspect its current implementation before modifying it.

Understand:

* current routes
* controllers
* models
* migrations
* Blade views
* validation checklist
* AI artifact
* OTP demonstration
* submission flow
* database persistence
* UI
* JavaScript

The existing lab includes an AI validation scenario involving an OTP claim.

Preserve the useful training concept.

If the current implementation exposes the answer too directly or has prototype limitations, improve it based on the official Week 2 training requirements.

Do not simply throw it away.

---

# 21. IMPORTANT PROTOTYPE LIMITATION

The existing OTP lab is a prototype.

If inspection confirms that:

* the OTP page directly reveals the correct expiry
* OTP validation is not actually time-based
* OTP attempts are not tracked

then identify these as existing prototype limitations.

Determine whether the official Week 2 material requires them to be corrected.

If correction is required, implement a proper training-safe solution.

Do not assume prototype behavior is production behavior.

---

# 22. TESTING

Add automated tests for important functionality.

At minimum cover:

* authentication/authorization
* participant isolation
* activity access
* submission creation
* submission status transitions
* evidence validation
* coach review
* Gate criteria
* progress calculation
* reporting/export where practical

Do not merely create tests that assert the application loads.

Test actual business rules.

---

# 23. SEED DATA

Create useful development/demo seed data.

Include:

* coach user
* Manual QA participant
* Automation QA participant
* Week 2
* Week 3
* Week 4
* activities from the official training material
* requirements
* evidence requirements
* Gate criteria

Do not fabricate training activities.

Use the official PowerPoints as the source for the training content.

Clearly distinguish demo/seed accounts from real participant data.

---

# 24. TRAINING REQUIREMENTS MATRIX

Create a requirements matrix during discovery and keep it updated.

Use a structure similar to:

| Week | Activity | Manual QA | Automation QA | Deliverable | Evidence | Coach Review | Gate |
| ---- | -------- | --------- | ------------- | ----------- | -------- | ------------ | ---- |
| W2   | ...      | ...       | ...           | ...         | ...      | ...          | ...  |
| W3   | ...      | ...       | ...           | ...         | ...      | ...          | ...  |
| W4   | ...      | ...       | ...           | ...         | ...      | ...          | ...  |

Every training requirement implemented in the application should be traceable back to the appropriate Week 2, Week 3, or Week 4 source material.

---

# 25. PHASED DEVELOPMENT — VERY IMPORTANT

Do NOT attempt to build everything immediately.

Work in phases.

## PHASE 1 — DISCOVERY ONLY

DO NOT MODIFY FILES.

Inspect:

### Existing Laravel application

* directory structure
* routes
* controllers
* models
* migrations
* database schema
* Blade views
* components
* JavaScript
* CSS/assets
* authentication
* seeders
* tests
* configuration
* dependencies
* README/documentation

### Existing QA Validation Lab

Understand its complete workflow.

### Training PowerPoints

Read:

* Week 2
* Week 3
* Week 4

Then produce a discovery report.

The discovery report MUST include:

1. Existing architecture
2. Existing QA Lab functionality
3. Existing database structure
4. Existing authentication
5. Existing Week 1-labeled activity
6. Explicit conclusion that the existing Week 1-labeled activity will become Week 2
7. Existing Week 2 functionality
8. Week 2 gaps
9. Week 3 requirements
10. Week 4 requirements
11. Manual QA workflow
12. Automation QA workflow
13. Monica integration/workflow
14. Evidence architecture
15. Submission architecture
16. Coach review architecture
17. Gate architecture
18. Progress architecture
19. Reporting architecture
20. Proposed database changes
21. Proposed routes
22. Proposed UI changes
23. Security considerations
24. Risks/conflicts
25. Training requirements matrix
26. Recommended implementation order

STOP AFTER THE DISCOVERY REPORT.

Do not modify files during Phase 1.

---

# 26. PHASE 2 — DATABASE / DOMAIN MODEL

Only after discovery:

Implement the required database/domain architecture.

Preserve existing tables and functionality.

Use migrations.

Do not destroy existing data.

---

# 27. PHASE 3 — AUTHENTICATION / ROLES / PARTICIPANTS

Implement or improve:

* participant authentication
* coach authentication
* roles
* participant profile
* authorization
* participant isolation

---

# 28. PHASE 4 — TRAINING / ACTIVITIES / SUBMISSIONS / EVIDENCE

Implement:

* weeks
* activities
* activity requirements
* participant-specific requirements
* submissions
* revisions
* evidence
* daily logs
* progress

---

# 29. PHASE 5 — WEEK 2

Convert the existing Week 1-labeled implementation into Week 2.

Preserve its functionality.

Compare against the official Week 2 PowerPoint.

Implement only the required corrections/extensions.

Do not duplicate the Week 2 implementation.

---

# 30. PHASE 6 — WEEK 3

Implement the official Week 3 training activities and requirements.

Support Manual QA and Automation QA differences.

---

# 31. PHASE 7 — WEEK 4

Implement the official Week 4 training activities and requirements.

Support Manual QA and Automation QA differences.

---

# 32. PHASE 8 — COACH DASHBOARD / REVIEWS / GATES / REPORTING

Implement:

* coach dashboard
* participant detail
* reviews
* revision workflow
* Gates
* progress
* reports
* CSV exports

---

# 33. PHASE 9 — TESTING / SEEDING / END-TO-END

Add:

* automated tests
* seed data
* realistic development workflow
* end-to-end verification

Verify:

**Login → Dashboard → Week → Activity → Submission → Evidence → Coach Review → Revision/Approval → Gate → Progress → Reporting**

---

# 34. DEVELOPMENT RULES

Always follow these rules:

1. Inspect before changing.
2. Preserve working existing functionality.
3. Do not rebuild the existing Laravel application.
4. Do not create a new Laravel project.
5. Do not create a Week 1.
6. Rename/reclassify the existing Week 1-labeled activity as Week 2.
7. Do not create a duplicate Week 2.
8. Do not invent Week 5+.
9. Use Week 2, Week 3, and Week 4 PowerPoints as the training source of truth.
10. Do not invent training requirements not supported by the source material.
11. Do not destroy existing database data.
12. Do not use destructive database resets.
13. Use migrations for schema changes.
14. Keep Manual QA and Automation QA workflows distinct where required.
15. Do not replace the Monica automation repository with the QA Lab.
16. Make evidence and submissions traceable.
17. Make Gates configurable.
18. Make training content database-driven where practical.
19. Use authorization and participant isolation.
20. Write meaningful automated tests.
21. Keep the application maintainable.
22. Explain major architectural decisions.
23. If something is unclear from the PowerPoints, identify the ambiguity instead of silently inventing an answer.

---

# 35. FIRST TASK

START WITH PHASE 1 ONLY.

Do NOT modify any files.

Inspect the existing `qa-lab` Laravel application.

Inspect the existing QA Validation Lab.

Inspect all three training PowerPoints:

* Week 2
* Week 3
* Week 4

Pay particular attention to the fact that:

**The existing activity labeled Week 1 is actually the existing Week 2 implementation and must be renamed/reclassified as Week 2.**

Then return a detailed discovery report and requirements matrix.

WAIT FOR MY APPROVAL BEFORE STARTING PHASE 2.

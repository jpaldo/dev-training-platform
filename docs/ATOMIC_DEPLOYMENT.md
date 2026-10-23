# Atomic production releases

The Hostinger application must not be updated by synchronizing a new source
tree into the live application directory. That approach can remove `vendor`,
compiled Vite assets, or Laravel runtime directories while requests are being
served.

The supported target layout is:

```text
/home/u720282675/domains/qa.mprestoza.com/
  qa-training-platform -> qa-training-platform-releases/<sha>
  qa-training-platform-releases/
    <sha>/                  # complete immutable release
  qa-training-platform-shared/
    .env
    private/                # storage/app/private; persistent uploads
    logs/                   # shared Laravel logs
```

The existing public entrypoint already references the stable
`qa-training-platform` path. After a planned one-time cutover converts that
path to a symlink, future releases can switch it atomically without changing
the public root. The one-time conversion is intentionally not performed by
the deployment script.

## Release sequence

1. Build an exact Git archive and a verified frontend build.
2. Run `scripts/atomic-release.sh prepare` into a new release directory.
3. Link only shared `.env`, `storage/app/private`, and `storage/logs`; create
   fresh per-release framework cache/session/view directories.
4. Ensure Composer dependencies are complete, using the committed lockfile.
5. Build config, route, and view caches inside the new release.
6. Verify Laravel boot, writable runtime paths, the Vite manifest, database
   compatibility, and persistent storage access.
7. Run `scripts/atomic-release.sh activate`; it changes the stable symlink
   with one `mv -T` operation.
8. Check HTTP health, queue status, and the persistent-storage manifest. If
   post-switch health fails, activate the previous complete release.

No migration is applied automatically by the script. Pending migrations block
activation. Schema changes must use an expand/migrate/contract sequence so old
and new releases remain compatible during the switch.

For Windows-side persistent-file verification, use
`scripts/persistent-storage-check.ps1` to capture before/after path, byte-size,
and SHA-256 manifests. The Linux release host must perform the equivalent
manifest check before and after activation; the PowerShell helper is not
required on Hostinger.

The existing Hostinger queue/cron mechanism remains the worker mechanism. A
deployment must reload that existing mechanism after activation; it must not
introduce Supervisor, systemd, or a second scheduler.

## Safe simulation

Run from Git Bash:

```bash
scripts/atomic-release.sh simulate
```

The simulation verifies that release A remains live while B is built, an
incomplete C cannot activate, rollback works, and persistent files remain
unchanged throughout.

## Developer program release

One-off steps for the first release that removes the QA training program.
Its migrations delete every QA training record, every participant account and
the participants' uploaded files, and drop the QA tables. They cannot be
rolled back: `down()` restores nothing, and the previous release no longer
runs on the new schema. The release also no longer has the Daily Log
Completion report, so **every export is taken from the version running before
this release**. Run the steps in this order. On a database that has never
held QA training data, skip steps 1 and 2.

### Before deploying

1. **Participants sync to Google Drive.** Ask every participant who has
   connected Google Drive to press **SYNC TO GOOGLE DRIVE** in the sidebar
   (or the sync button in the Google Drive card on their profile) and wait
   until it no longer shows **SYNCING...**. Only the participant can start
   their sync; an admin cannot run it for them. It writes their Week 1 to 4
   workbooks and evidence files to their own Drive folder, which the release
   leaves alone.
2. **An admin downloads every export** while signed in to the running
   version. Clear every filter first: each export uses the filters shown on
   its screen, and an unfiltered export holds every participant.
   - **Reports → Progress → Export Progress CSV**
     (`participant-progress.csv`).
   - Review queue, gate results and at-risk participants have no button; open
     `/coach/reports/progress.csv?type=reviews` (`review-queue.csv`),
     `?type=gates` (`gate-results.csv`) and `?type=risk` (`at-risk.csv`).
   - **Reports → Missing Submissions → Export Missing Submissions CSV**
     (`missing-submissions.csv`).
   - **Scoring → Scoring Reports → Activity Scores CSV**
     (`activity-scores.csv`) and **Participant Summary CSV**
     (`participant-score-summary.csv`).
   - **Attendance → Export CSV** (`attendance.csv`).
   - **Reports → Daily Log Completion → Export Daily Logs CSV**
     (`daily-log-completion.csv`). This report does not exist in this
     release. The CSV lists log dates per week, not the log text; the full
     entries survive only in the database dump.
3. **The operator puts the running release into maintenance mode**
   (`php artisan down` in the live app path), then takes a database dump and
   an archive of the shared `private/` directory (`storage/app/private`).
   Keep both with the CSVs; they are the only way back. Do not continue until
   both are verified readable.

### Deploying

4. Deploy this release with the release sequence above. The script never
   migrates and `prepare` stops while migrations are pending, so run
   `php artisan migrate --force` from the new release directory, then finish
   `prepare` and `activate`. Besides adding the employee ID, first and last
   name and job role columns to `users`, the release's migrations do this:
   - `2026_10_23_060944_delete_qa_training_data` empties the training tables
     (submissions, versions, evidence, reviews, scores, gate evaluations,
     daily logs, attendance, weekly videos, notifications, Drive sync and
     export records, Validation Lab and Week 3 and 4 records), deletes the
     *AI-Assisted QA Level 3* program and every participant account, then
     deletes the evidence, weekly video and Validation Lab files from
     `storage/app/private`. Admin and coach accounts, their Drive connections
     and the audit log stay; files on Google Drive are not touched.
   - The `2026_10_23_*` migrations after it delete the daily log and mentor
     interview notifications and settings, and drop the Validation Lab, daily
     log, Week 3 workspace, Week 4 peer interview and mentor interview
     booking tables.
   - `2026_10_23_121319_drop_training_path_columns` drops the training path
     columns (`users.training_path`, `activity_requirements.participant_path`)
     and their indexes.

   The new release starts with fresh framework directories, so it is not in
   maintenance mode once activated.

### After deploying

5. Seed the L4 program, then its first session, from the active release:
   - `php artisan db:seed --class=L4TrainingProgramSeeder --force`
   - `php artisan db:seed --class=L4FirstSessionSeeder --force` creates
     *L4 Accelerator Training — November 2026* (4 weeks, Mon 9 Nov to Sun
     6 Dec 2026). It stops with an error if the L4 program is missing, and
     leaves the session as it is if it already exists, so an admin's edits
     stay.

   Re-running either is safe. Never run a bare `php artisan db:seed` here: it
   also runs the demo account provisioner.
6. Never run the demo account provisioner in production:
   `app:ensure-development-accounts` (local, staging and testing) and
   `app:ensure-development-baseline` (local and testing) both refuse to run
   there.
7. Reload the queue/cron mechanism and run the usual post-switch checks.

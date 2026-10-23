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
held QA training data, skip steps 2 and 3.

### Before deploying

1. **Google Drive works in production.** Participants choose their weekly
   video from their own Google Drive, and week 1 of the first session
   (Mon 9 Nov 2026) asks for one, so set this up well before then:
   - A Google Cloud project in the company's Google Workspace organization.
   - An OAuth consent screen set to *Internal* (only company Google accounts
     can connect), with the scopes `openid`, `email`, `profile` and
     `https://www.googleapis.com/auth/drive.file`.
   - The Google Drive API and the Google Picker API enabled.
   - A *Web application* OAuth client with the authorized redirect URI
     `https://{host}/profile/google-drive/callback` and the production origin
     (`https://{host}`) as an authorized JavaScript origin.
   - A Picker API key restricted to the production host (HTTP referrers) and
     to the Google Picker API.
   - In the shared `.env`: `GOOGLE_DRIVE_ENABLED=true`, `GOOGLE_CLIENT_ID`,
     `GOOGLE_CLIENT_SECRET`, `GOOGLE_REDIRECT_URI` (the redirect URI above,
     exactly), `GOOGLE_PICKER_API_KEY` and `GOOGLE_CLOUD_PROJECT_NUMBER`. Or
     enter them on the admin **Google Drive Integration** page
     (`/admin/settings/google-drive`: **Settings → Manage Google Settings →
     Manage OAuth / Drive Configuration**); a value saved there wins over
     `.env`.
   - In the shared `.env` only (the admin page does not set it):
     `GOOGLE_DRIVE_SHARE_DOMAIN`, the company's Google Workspace domain as a
     plain hostname (for example `fullscale.io`). Choosing a video shares it
     view only with everyone at that domain who has the link, so mentors and
     admins can open it. Without it, videos are saved but not shared, and
     participants are asked to share them by hand.
   - Verify with **Test Google Configuration** on that page (it checks that
     the client ID, secret and redirect URI are set, not the Picker key).
     After deploying and seeding (step 6), sign in as a test participant,
     connect Google Drive from the profile and choose a video on a week's
     **Choose Weekly Video** page: the migrations delete every participant
     account and the running version cannot choose a video, so this check
     only works on the new release. The page must not show the "could not be
     shared" warning, and in Google Drive the video's sharing must list the
     company domain as Viewer.

   Weekly videos and video evidence are links, not uploads, so they need no
   PHP upload limit. Other evidence files still do: PHP's
   `upload_max_filesize` and `post_max_size` and the web server's body limit
   must stay above `training.evidence_max_kb` (300 MB).

2. **Participants sync to Google Drive.** Ask every participant who has
   connected Google Drive to press **SYNC TO GOOGLE DRIVE** in the sidebar
   (or the sync button in the Google Drive card on their profile) and wait
   until it no longer shows **SYNCING...**. Only the participant can start
   their sync; an admin cannot run it for them. It writes their Week 1 to 4
   workbooks and evidence files to their own Drive folder, which the release
   leaves alone.
3. **An admin downloads every export** while signed in to the running
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
4. **The operator puts the running release into maintenance mode**
   (`php artisan down` in the live app path), then takes a database dump and
   an archive of the shared `private/` directory (`storage/app/private`).
   Keep both with the CSVs; they are the only way back. Do not continue until
   both are verified readable.

### Deploying

5. Deploy this release with the release sequence above. The script never
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

   Since the migration squash (see "One-time: adopting the squashed
   migrations" below), these files exist only in Git history. A release from
   after the squash creates the final schema from the squashed files and runs
   none of these deletions, so deploy the pre-squash release first on a
   database that still holds QA training data.

   The new release starts with fresh framework directories, so it is not in
   maintenance mode once activated.

### After deploying

6. Seed the L4 program, then its first session, from the active release:
   - `php artisan db:seed --class=L4TrainingProgramSeeder --force`
   - `php artisan db:seed --class=L4FirstSessionSeeder --force` creates
     *L4 Accelerator Training — November 2026* (4 weeks, Mon 9 Nov to Sun
     6 Dec 2026). It stops with an error if the L4 program is missing, and
     leaves the session as it is if it already exists, so an admin's edits
     stay.

   Re-running either is safe. Never run a bare `php artisan db:seed` here: it
   also runs the demo account provisioner.
7. Never run the demo account provisioner in production:
   `app:ensure-development-accounts` (local, staging and testing) and
   `app:ensure-development-baseline` (local and testing) both refuse to run
   there.
8. Reload the queue/cron mechanism and run the usual post-switch checks.

## One-time: adopting the squashed migrations

The migration squash release replaces the 102 migration files that existed on
24 Oct 2026 with 43 hand-written files, one per table
(`2026_10_24_000001_create_users_table` to
`2026_10_24_000043_create_google_drive_syncs_table`). The new files create the
same schema, so a database that ran the old files must not run them: it
records them instead with `php artisan app:adopt-squashed-migrations`. The
command only rewrites rows in the `migrations` table and never runs a
migration. A database that has never been migrated skips this section:
`php artisan migrate --force` creates the schema from the new files. As of
24 Oct 2026 no production or staging database exists.

On each environment whose database ran the old files:

1. **Confirm the precondition.** From the live (pre-squash) release,
   `php artisan migrate:status --no-ansi` shows no `Pending` line, and its
   "Ran" list has the same names and count as
   `AdoptSquashedMigrationsCommand::REPLACED_MIGRATIONS` (102 names).
2. Run `scripts/atomic-release.sh prepare` for the squash release. It extracts
   and installs the release, then stops at "unexpected pending migrations".
   That is expected: the new files are not recorded yet.
3. From that release directory, run
   `php artisan app:adopt-squashed-migrations --pretend` and check that it
   lists 102 rows to delete and 43 to insert, then run
   `php artisan app:adopt-squashed-migrations --force`. It changes everything
   in one transaction, or nothing: it refuses (exit 1) when a replaced
   migration is not recorded or a recorded migration is in neither list. A
   second run prints "Already adopted."
4. Re-run `prepare`. It extracts the release again and now passes the
   pending-migration check.
5. `activate` as usual.
6. Never run `php artisan migrate` from a pre-squash release afterwards: it
   would see its old files as pending and try to create tables that already
   exist. Activating a pre-squash release with `atomic-release.sh rollback`
   still works, because rollback runs no migration check.

**Developers' local databases** that ran the old files: after pulling the
squash, either run `php artisan app:adopt-squashed-migrations --pretend`,
then `php artisan app:adopt-squashed-migrations` (no `--force` is needed in
the local environment), or rebuild with `php artisan migrate:fresh --seed`,
which deletes all local data.

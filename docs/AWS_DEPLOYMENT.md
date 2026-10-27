# AWS deployment plan (EC2 + RDS, no Docker)

Target: one EC2 instance running nginx + PHP-FPM, a Supervisor-managed queue
worker and a cron-driven scheduler, with the database on RDS MySQL. Releases
use the symlinked-release layout from `scripts/atomic-release.sh`: a new
release is never synchronised into the live directory, because that can
remove `vendor`, the Vite build or runtime directories while requests are
being served.

Docker is deliberately not used: with a single instance it adds an image
build, a registry and separate web/worker/scheduler containers for no
operational gain. Revisit when a second instance or ECS is needed.

## 1. Target architecture

```text
Internet ──HTTPS──▶ EC2 (Elastic IP, nginx + php-fpm)
                      ├─ /var/www/training/current -> releases/<sha>   (web)
                      ├─ supervisor: php artisan queue:work database    (worker)
                      ├─ cron: php artisan schedule:run every minute    (scheduler)
                      └─ /var/www/training/shared/private               (uploads, on EBS)
                                │
                                └─ RDS MySQL (private subnet, same VPC)
```

Three processes share one codebase and one `.env`:

| Process   | Runs                                              | Needed for                                                                 |
|-----------|---------------------------------------------------|----------------------------------------------------------------------------|
| web       | nginx → php-fpm                                   | all HTTP traffic                                                           |
| worker    | `queue:work database` under Supervisor, always on | every queued job: notification sync, training data sync; later the repository checks (`docs/plans/repository-ai-check.md`, which changes the worker settings in section 6) |
| scheduler | `schedule:run` from cron every minute             | `notifications:sync-participants` hourly (`routes/console.php`)            |

The scheduler only *queues* jobs; the worker processes them. Both must run or
participant reminders stop updating (weeks that open by date are only picked
up by the hourly run; see `WeekProgressionService::sessionWeekStates()`).

## 2. AWS resources

### Network and security

- One VPC with a public subnet (EC2) and two private subnets in different AZs
  (RDS subnet group requires two).
- Security group `training-web`: inbound 80 and 443 from `0.0.0.0/0`. No
  inbound 22; use AWS Systems Manager Session Manager for shell access.
- Security group `training-db`: inbound 3306 **only from** `training-web`.
- Elastic IP attached to the instance; DNS `A` record for the production host
  points at it.

### EC2

- Ubuntu 24.04 LTS, `t3.small` (2 vCPU, 2 GB) to start; `t3.medium` if the
  worker and web compete for memory. Burstable is fine for an internal
  training tool.
- Root EBS gp3, 30 GB, **encrypted**, `DeleteOnTermination=false`. Uploaded
  evidence lives on this volume (`shared/private`), so it is the only
  stateful disk.
- IAM instance role `training-ec2` with `AmazonSSMManagedInstanceCore`,
  `CloudWatchAgentServerPolicy` and `s3:GetObject` on the release bucket
  (section 7 ships releases through it). The AWS CLI on the instance picks
  these credentials up from the instance metadata; no keys in `.env`.
- Enable **EC2 auto-recovery** (CloudWatch `StatusCheckFailed_System` alarm
  with the recover action) so a host failure restarts the instance on new
  hardware with the same EBS volume and Elastic IP.

### RDS MySQL

- MySQL 8.0, `db.t4g.micro` or `db.t4g.small`, 20 GB gp3 with storage
  autoscaling, **encrypted**, single-AZ (Multi-AZ doubles cost; the web tier
  is single-instance anyway, so it buys little until that changes).
- Not publicly accessible; subnet group = the two private subnets; security
  group `training-db`.
- Automated backups: 7-day retention, backup window outside Manila office
  hours (`APP_TIMEZONE=Asia/Manila`, so e.g. `18:00-19:00 UTC`). Enable
  deletion protection.
- Parameter group: default `utf8mb4` is already the MySQL 8 default; set
  `time_zone` only if reports need DB-side local time (the app passes
  timezone through Laravel, so leave UTC).
- Create the application database and user from the EC2 instance once RDS is
  up:

  ```sql
  CREATE DATABASE dev_training_platform CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
  CREATE USER 'dev_training_app'@'%' IDENTIFIED BY '<strong password>';
  GRANT ALL PRIVILEGES ON dev_training_platform.* TO 'dev_training_app'@'%';
  ```

  The `%` host is acceptable because the security group already restricts
  the source to the web instance.

### Secrets

Keep the production `.env` out of Git. Store a copy of its secret values
(`APP_KEY`, `DB_PASSWORD`, Google OAuth client secret, mail credentials) in
AWS Secrets Manager or SSM Parameter Store (SecureString) so the instance can
be rebuilt. The file on disk at `shared/.env` remains the source Laravel
reads.

## 3. Server provisioning

Run once on a fresh instance (via Session Manager).

```bash
# PHP 8.4 (Laravel 13 needs >= 8.3; composer.json says ^8.3)
sudo add-apt-repository -y ppa:ondrej/php
sudo apt update
sudo apt install -y nginx supervisor unzip git acl mysql-client \
  php8.4-fpm php8.4-cli php8.4-mysql php8.4-mbstring php8.4-xml php8.4-curl \
  php8.4-zip php8.4-bcmath php8.4-intl php8.4-gd php8.4-opcache

# Composer
curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

# AWS CLI (pulls release archives from S3 with the instance role)
sudo snap install aws-cli --classic

# Unattended security updates
sudo apt install -y unattended-upgrades && sudo dpkg-reconfigure -plow unattended-upgrades

# Let the release steps (run as www-data) reload php-fpm after a switch (section 7)
echo 'www-data ALL=(root) NOPASSWD: /usr/bin/systemctl reload php8.4-fpm' | sudo tee /etc/sudoers.d/training-release
sudo chmod 440 /etc/sudoers.d/training-release
```

`composer.json` declares no `ext-*` requirements, so the extension list above
is what Laravel and the installed packages need (`pdo_mysql`, `mbstring`,
`xml`, `curl`, `zip`, `bcmath`, `intl`, `gd`).

Node is **not** installed on the server: `public/build` is produced by
`npm run build` on the build side and shipped inside the release archive
(`atomic-release.sh prepare` refuses an archive without
`public/build/manifest.json`).

PHP-FPM pool (`/etc/php/8.4/fpm/pool.d/www.conf`): keep `www-data`, set
`pm = dynamic`, `pm.max_children = 10`, and in `/etc/php/8.4/fpm/php.ini`
set `upload_max_filesize = 300M`, `post_max_size = 310M` (evidence files may
be up to `training.evidence_max_kb`, 300 MB; weekly videos are links and
need no limit), `max_execution_time = 120`, and
`opcache.validate_timestamps = 0` (releases are immutable; a new release gets
a fresh `opcache` because `php-fpm` is reloaded after activation, section 7).

## 4. Directory layout

```text
/var/www/training/
  current -> /var/www/training/releases/<sha>      # live symlink (nginx root is current/public)
  releases/
    <sha>/                                         # immutable release
  shared/
    .env                                           # production environment
    private/                                       # storage/app/private (submission evidence)
    logs/                                          # storage/logs
  atomic-release.sh                                # copy of scripts/atomic-release.sh from the release being deployed
```

`atomic-release.sh` has to exist before `prepare` extracts the archive, so
each release first copies its own `scripts/atomic-release.sh` here (section
7). That keeps the script in step with the release that uses it.

Create it once:

```bash
sudo mkdir -p /var/www/training/{releases,shared/private,shared/logs}
sudo chown -R www-data:www-data /var/www/training
sudo chmod 750 /var/www/training/shared
# first release placeholder so `prepare` passes its "must be a symlink" check
sudo -u www-data ln -s /var/www/training/releases/bootstrap /var/www/training/current
```

This matches the paths `atomic-release.sh` expects:
`--app-link /var/www/training/current --releases-root /var/www/training/releases --shared-root /var/www/training/shared`.

## 5. Production `.env`

`/var/www/training/shared/.env`, owned by `www-data`, mode `640`. Differences
from `.env.example`:

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://<host>
APP_KEY=                      # php artisan key:generate --show, once; never rotate casually (encrypted settings)
LOG_CHANNEL=stack
LOG_STACK=daily
LOG_LEVEL=warning

DB_HOST=<rds endpoint>
DB_DATABASE=dev_training_platform
DB_USERNAME=dev_training_app
DB_PASSWORD=<from Secrets Manager>

SESSION_DRIVER=database       # already DB-backed; nothing instance-local
CACHE_STORE=database
QUEUE_CONNECTION=database
DB_QUEUE_RETRY_AFTER=90       # must stay > worker --timeout (section 6)
FILESYSTEM_DISK=local         # evidence stays on EBS; move to s3 only when adding instances

MAIL_MAILER=smtp              # Amazon SES SMTP or the company mail relay
MAIL_HOST=email-smtp.<region>.amazonaws.com
MAIL_PORT=587
MAIL_SCHEME=tls
MAIL_FROM_ADDRESS=<verified sender>

BOOTSTRAP_ADMIN_TEMPORARY_PASSWORD=   # set only for the first-admin bootstrap, then clear it

GOOGLE_DRIVE_ENABLED=true
GOOGLE_CLIENT_ID=...
GOOGLE_CLIENT_SECRET=...
GOOGLE_REDIRECT_URI=https://<host>/profile/google-drive/callback
GOOGLE_PICKER_API_KEY=...
GOOGLE_CLOUD_PROJECT_NUMBER=...
GOOGLE_DRIVE_SHARE_DOMAIN=fullscale.io
```

The Google Cloud setup (internal OAuth consent, redirect URI, Picker key)
is in section 8. Verify the mail settings with the **Send test email**
action on the admin settings page after the first release.

## 6. Processes

### nginx

`/etc/nginx/sites-available/training`:

```nginx
server {
    listen 80;
    server_name <host>;
    return 301 https://$host$request_uri;
}

server {
    listen 443 ssl http2;
    server_name <host>;
    root /var/www/training/current/public;
    index index.php;

    ssl_certificate     /etc/letsencrypt/live/<host>/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/<host>/privkey.pem;

    client_max_body_size 310M;   # evidence uploads up to 300 MB (training.evidence_max_kb)
    add_header X-Frame-Options SAMEORIGIN;
    add_header X-Content-Type-Options nosniff;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.4-fpm.sock;
        # $realpath_root, not $document_root: resolve the symlink per request so a
        # release switch takes effect without a stale path in php-fpm
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        fastcgi_param DOCUMENT_ROOT $realpath_root;
    }

    location ~ /\.(?!well-known) { deny all; }
}
```

TLS: `sudo apt install certbot python3-certbot-nginx && sudo certbot --nginx -d <host>`.
Certbot installs its own renewal timer. (If an ALB is added later, terminate
TLS with an ACM certificate there instead.)

### Queue worker (Supervisor)

`/etc/supervisor/conf.d/training-worker.conf`:

```ini
[program:training-worker]
command=/usr/bin/php /var/www/training/current/artisan queue:work database --sleep=3 --tries=3 --timeout=60 --max-time=3600 --max-jobs=500
directory=/var/www/training/current
user=www-data
numprocs=1
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
stopwaitsecs=70
stdout_logfile=/var/www/training/shared/logs/worker.log
stderr_logfile=/var/www/training/shared/logs/worker.log
```

Notes:

- `--timeout=60` < `DB_QUEUE_RETRY_AFTER=90` so a job is never handed to a
  second worker while still running. Keep this ordering if either changes.
- `--max-time=3600` makes the worker exit and get restarted by Supervisor
  hourly, which bounds memory growth. Every deploy also runs
  `php artisan queue:restart` (section 7) so the worker picks up the new
  release; without it the worker keeps executing the *old* release's code.
- `numprocs=1` is enough: `GenerateParticipantNotificationsJob` is unique per
  participant and `SyncTrainingDataJob` is light. Raise to 2 only if
  `php artisan queue:monitor database:default --max=100` reports a backlog.
- `stopwaitsecs=70` > `--timeout` so a graceful stop lets the current job
  finish.

Load it: `sudo supervisorctl reread && sudo supervisorctl update`.

This replaces the former shared-hosting `queue-cron.sh` (`--stop-when-empty`
from cron). Do not install that script on EC2.

### Scheduler (cron)

`sudo crontab -u www-data -e`:

```cron
* * * * * cd /var/www/training/current && /usr/bin/php artisan schedule:run >> /dev/null 2>&1
```

Laravel decides inside `schedule:run` which tasks are due, so the only entry
is this one; `notifications:sync-participants` runs at the top of every hour
from it. The `cd` goes through the `current` symlink, so cron always runs the
live release.

`schedule:run` must run on exactly one host. When a second instance is ever
added, use `->onOneServer()` with a shared cache store, or keep cron on one
designated instance.

## 7. Release procedure

Build off the machine, ship an archive, prepare, migrate, activate, restart
the worker.

### Build (developer machine or CI)

```bash
SHA=$(git rev-parse --short HEAD)
npm ci && npm run build                                  # produces public/build
mkdir -p /tmp/release-$SHA
git archive --format=tar HEAD | tar -xf - -C /tmp/release-$SHA
cp -R public/build /tmp/release-$SHA/public/build
tar -czf release-$SHA.tar.gz -C /tmp/release-$SHA .
```

`vendor` is not shipped: `prepare` runs `composer install --no-dev` on the
instance from the committed `composer.lock`, with the server's PHP, so the
platform check matches the runtime. (Shipping a `vendor` built on a
developer machine works too and skips that step, but ties the archive to
that machine's PHP version.)

Ship with `aws s3 cp release-$SHA.tar.gz s3://<release-bucket>/`, or `scp`
through Session Manager port forwarding.

### On the instance

```bash
ROOT=/var/www/training
sudo -u www-data aws s3 cp s3://<release-bucket>/release-$SHA.tar.gz /tmp/

# 0. Take the release script from the archive (it must exist before prepare extracts).
sudo -u www-data tar -xzf /tmp/release-$SHA.tar.gz -C $ROOT --strip-components=2 ./scripts/atomic-release.sh

# 1. Prepare: extracts, links shared .env/private/logs, composer install, builds
#    caches and refuses if migrations are pending.
sudo -u www-data $ROOT/atomic-release.sh prepare \
  --archive /tmp/release-$SHA.tar.gz --sha $SHA \
  --app-link $ROOT/current --releases-root $ROOT/releases --shared-root $ROOT/shared

# 2. Migrate (only when prepare stopped on "unexpected pending migrations"). The
#    script never migrates on its own. Write schema changes expand/migrate/contract
#    (add first, switch code, drop later) so the still-live old release keeps
#    working during the switch and `rollback` stays possible. Re-running prepare
#    wipes and re-extracts the release directory, composer install included.
sudo -u www-data php $ROOT/releases/$SHA/artisan migrate --force
sudo -u www-data $ROOT/atomic-release.sh prepare ...     # same arguments; now passes

# 3. Activate: single mv -T of the symlink, then `queue:restart` so the Supervisor
#    worker reloads onto the new release, then the after-switch command (drop the old
#    opcache; validate_timestamps=0; allowed by the sudoers line in section 3), then
#    the HTTP health check.
sudo -u www-data $ROOT/atomic-release.sh activate \
  --app-link $ROOT/current --release $ROOT/releases/$SHA \
  --after-switch 'sudo systemctl reload php8.4-fpm' \
  --health-url https://<host>/up

# 4. Prune: keep the live release plus the 4 newest others for rollback.
LIVE=$(readlink -f $ROOT/current)
ls -dt $ROOT/releases/*/ | grep -vx "$LIVE/" | tail -n +5 | xargs -r sudo rm -rf
```

`/up` is the framework health route registered in `bootstrap/app.php`.

### Rollback

```bash
sudo -u www-data scripts/atomic-release.sh rollback \
  --app-link /var/www/training/current \
  --release /var/www/training/releases/<previous sha> \
  --after-switch 'sudo systemctl reload php8.4-fpm' \
  --health-url https://<host>/up
```

Rollback runs `queue:restart` and the after-switch command the same way
`activate` does.

Rollback does not undo migrations; that is why they are written expand-first.

### Safe simulation

`scripts/atomic-release.sh simulate` (any Linux shell) checks the layout
logic without a server: release A stays live while B is built, an
incomplete release cannot activate, rollback works and shared files are
untouched throughout. It does not exercise `activate`'s `queue:restart` or
`--after-switch` steps.

## 8. First release

One-off steps for the first production release on an empty RDS database.
`php artisan migrate --force` creates the whole schema from the squashed
migration files; the `app:adopt-squashed-migrations` command exists only for
databases that ran the pre-squash files, and no such database is being
carried over.

On an empty database the first `prepare` does not stop at "unexpected
pending migrations": its `migrate:status` call exits with "Migration table
not found" and the script dies there. That is expected. Run
`php artisan migrate --force` from the extracted release directory as in
section 7 step 2, then re-run `prepare`; from the second release on, the
normal flow applies.

### Before: Google Drive works in production

Participants choose their weekly video from their own Google Drive, and week
1 of the first session (Mon 9 Nov 2026) asks for one, so set this up before
then:

- A Google Cloud project in the company's Google Workspace organization.
- An OAuth consent screen set to *Internal* (only company Google accounts
  can connect), with the scopes `openid`, `email`, `profile` and
  `https://www.googleapis.com/auth/drive.file`.
- The Google Drive API and the Google Picker API enabled.
- A *Web application* OAuth client with the authorized redirect URI
  `https://<host>/profile/google-drive/callback` and the production origin
  (`https://<host>`) as an authorized JavaScript origin.
- A Picker API key restricted to the production host (HTTP referrers) and
  to the Google Picker API.
- In `shared/.env`: `GOOGLE_DRIVE_ENABLED=true`, `GOOGLE_CLIENT_ID`,
  `GOOGLE_CLIENT_SECRET`, `GOOGLE_REDIRECT_URI` (the redirect URI above,
  exactly), `GOOGLE_PICKER_API_KEY` and `GOOGLE_CLOUD_PROJECT_NUMBER`. Or
  enter them on the admin **Google Drive Integration** page
  (`/admin/settings/google-drive`: **Settings → Manage Google Settings →
  Manage OAuth / Drive Configuration**); a value saved there wins over
  `.env`.
- In `shared/.env` only (the admin page does not set it):
  `GOOGLE_DRIVE_SHARE_DOMAIN`, the company's Google Workspace domain as a
  plain hostname (for example `fullscale.io`). Choosing a video shares it
  view only with everyone at that domain who has the link, so mentors and
  admins can open it. Without it, videos are saved but not shared, and
  participants are asked to share them by hand.
- Verify with **Test Google Configuration** on that page (it checks that
  the client ID, secret and redirect URI are set, not the Picker key). After
  seeding (below), sign in as a test participant, connect Google Drive from
  the profile and choose a video on a week's **Choose Weekly Video** page.
  The page must not show the "could not be shared" warning, and in Google
  Drive the video's sharing must list the company domain as Viewer.

Weekly videos and video evidence are links, not uploads, so they need no PHP
upload limit. Other evidence files still do: PHP's `upload_max_filesize` and
`post_max_size` and nginx's `client_max_body_size` must stay above
`training.evidence_max_kb` (300 MB); sections 3 and 6 set them to that.

### After: seed the program

From the active release (`/var/www/training/current`), as `www-data`:

```bash
php artisan db:seed --class=L4TrainingProgramSeeder --force
php artisan db:seed --class=L4FirstSessionSeeder --force
```

The first seeds the *L4 Accelerator Training* program when no program of
that name exists, and otherwise leaves it alone, so edits made on the admin
Curriculum pages stay. The second creates *L4 Accelerator Training —
November 2026* (4 weeks, Mon 9 Nov to Sun 6 Dec 2026); it stops with an
error if the program is missing and leaves the session alone if it already
exists, so an admin's edits stay. Re-running either is safe.

- Never run a bare `php artisan db:seed` in production: it also runs the demo
  account provisioner.
- Never run `app:ensure-development-accounts` or
  `app:ensure-development-baseline` there; both refuse the production
  environment.
- Create the first admin: set `BOOTSTRAP_ADMIN_TEMPORARY_PASSWORD` in
  `shared/.env`, run `php artisan admin:bootstrap` (it creates the admin
  account whose email is fixed in `BootstrapAdminCommand`, and refuses in
  production unless `APP_URL` is `https`), sign in and change the password,
  then clear the variable from `.env` (section 5). Further admins are
  created from the admin user pages.

## 9. Backups, monitoring, recovery

| What                      | How                                                                                  | Restore test                                      |
|---------------------------|--------------------------------------------------------------------------------------|---------------------------------------------------|
| Database                  | RDS automated backups, 7 days + a manual snapshot before every migrating release     | Restore snapshot to a temp instance, run `migrate:status` |
| Uploads (`shared/private`)| AWS Data Lifecycle Manager daily EBS snapshot of the root volume, 14-day retention; optionally a nightly `aws s3 sync shared/private s3://<backup-bucket>/private` from cron | Mount a snapshot volume on a temp instance |
| `.env`                    | Secrets in Secrets Manager / SSM; the file itself is in the EBS snapshot             | —                                                 |
| Logs                      | CloudWatch agent tailing `shared/logs/laravel-*.log` and `worker.log`; 30-day retention | —                                              |

CloudWatch alarms worth having from day one:

- `StatusCheckFailed_System` → recover instance (section 2).
- RDS `FreeStorageSpace` < 2 GB, `CPUUtilization` > 80 % for 15 min.
- EC2 `disk_used_percent` (CloudWatch agent) > 80 %: uploads and logs are
  the growth sources.
- A log metric filter on `laravel-*.log` for `.ERROR:` → alarm when > 0 in 5
  minutes.
- Route 53 or an external uptime check on `https://<host>/up`.

Queue health: `php artisan queue:monitor database:default --max=100`
scheduled hourly, or just watch `worker.log` for `Processed`/`Failed`
lines. Failed jobs land in `failed_jobs`; retry with
`php artisan queue:retry all`.

## 10. Go-live order

The existing EC2 instance is fresh and carries no data, so there is nothing
to migrate from it: build the new environment as sections 2 to 6 describe
(on that instance or a new one), then:

1. Prepare and activate the first release (section 7, with the first-release
   notes of section 8); the `current` placeholder symlink from section 4
   makes `prepare` pass its symlink check.
2. Install the Supervisor program and the cron entry, `supervisorctl update`.
3. Verify: `/up` returns 200 over HTTPS, `supervisorctl status
   training-worker` is `RUNNING`, `php artisan schedule:list` shows the
   hourly command, and the Google Drive and mail checks of sections 5 and 8
   pass.
4. Seed the program and bootstrap the admin (section 8).
5. Point the production DNS record at the Elastic IP, then switch on the
   backups and alarms of section 9. Terminate any earlier throwaway instance.

## 11. Repository changes this plan needs

Each is a small separate change.

- [x] `scripts/atomic-release.sh`: after the symlink switch in `activate`/`rollback`,
      run `php artisan queue:restart` and an optional `--after-switch CMD`
      hook (used above to reload php-fpm). Before this, the script never
      restarted the worker, so a deploy left it on the old release.
- [x] `docs/ATOMIC_DEPLOYMENT.md` (shared-hosting layout, "must not
      introduce Supervisor, systemd") deleted; its Google Drive setup and
      first-release seeding moved to section 8, its release principles to
      section 7. The QA-data deletion walkthrough and the migration-squash
      adoption steps were dropped: the production database starts empty.
- [ ] Remove `queue-cron.sh` (shared-hosting paths, `--stop-when-empty`
      workaround); Supervisor replaces it.
- [ ] Add `Schedule::command('queue:monitor database:default --max=100')->hourly()`
      to `routes/console.php` so a backlog is logged.
- [ ] Optional CI: a GitHub Actions workflow that runs the test suite, builds
      the archive (section 7) and uploads it to the release bucket on a tag,
      leaving `prepare`/`activate` as a manual SSM Run Command step.

## 12. When to revisit

Move evidence to S3 (`FILESYSTEM_DISK=s3`, `AWS_BUCKET`) and put an ALB in
front **before** adding a second instance; sessions, cache and queue are
already in the database so they need no change. At that point the release
archive becomes a container image and ECS replaces Supervisor and cron, which
is the Docker decision deferred here.

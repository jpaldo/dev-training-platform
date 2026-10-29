# AWS deployment plan (EC2 + RDS, no Docker)

Target: one Amazon Linux 2023 EC2 instance running nginx + PHP-FPM, a
systemd-managed queue worker and a cron-driven scheduler, with the database
on RDS MySQL. Releases use the symlinked-release layout from
`scripts/atomic-release.sh`: a new release is never synchronised into the
live directory, because that can remove `vendor`, the Vite build or runtime
directories while requests are being served.

Docker is deliberately not used: with a single instance it adds an image
build, a registry and separate web/worker/scheduler containers for no
operational gain. Revisit when a second instance or ECS is needed.

## 1. Target architecture

```text
Internet ──HTTPS──▶ EC2 (Elastic IP, nginx + php-fpm)
                      ├─ /var/www/training/current -> releases/<sha>   (web)
                      ├─ systemd: training-worker (queue:work database) (worker)
                      ├─ cron: php artisan schedule:run every minute    (scheduler)
                      └─ /var/www/training/shared/private               (uploads, on EBS)
                                │
                                └─ RDS MySQL over TLS (same VPC, not public)
```

Three processes share one codebase and one `.env`, all running as the
`nginx` user:

| Process   | Runs                                              | Needed for                                                                 |
|-----------|---------------------------------------------------|----------------------------------------------------------------------------|
| web       | nginx → php-fpm                                   | all HTTP traffic                                                           |
| worker    | `queue:work database` as the systemd unit `training-worker`, always on | every queued job: notification sync, training data sync; later the repository checks (`docs/plans/repository-ai-check.md`, which changes the worker settings in section 6) |
| scheduler | `schedule:run` from cron every minute             | `notifications:sync-participants` hourly (`routes/console.php`)            |

The scheduler only *queues* jobs; the worker processes them. Both must run or
participant reminders stop updating (weeks that open by date are only picked
up by the hourly run; see `WeekProgressionService::weekStates()`).

## 2. AWS resources

Everything is in the company account `129007323466`, region `us-east-2`,
and already exists (created 2026-10-27):

| Resource              | ID / endpoint                                               |
|-----------------------|-------------------------------------------------------------|
| EC2 `training-web`    | `i-062140707fc5036d8` (us-east-2a, subnet `subnet-98a8e3f0`) |
| RDS `training-web-db` | `training-web-db.chs5dvwmkvnl.us-east-2.rds.amazonaws.com`  |
| SG `training-web`     | `sg-04b35939609325d3c`                                      |
| SG `training-db`      | `sg-07852c0de9aa7c9b7`                                      |
| VPC                   | default VPC `vpc-291d3a41`                                  |

The `fs-training` IAM user used for this deployment can describe resources
and stop, start and resize the instance, but cannot allocate Elastic IPs,
use SSM, IAM, Route 53 or CloudWatch alarms. Those steps need an account
admin and are marked **(admin)** below.

### Network and security

- Security group `training-web`: inbound 80 and 443 from `0.0.0.0/0`, and
  22 from the deploying admin's office IP only (one `/32`). Shell access is
  SSH with the `training-web` key pair (kept in the team password manager);
  Session Manager is not used because the deploying IAM user has no SSM
  permissions. Update the `/32` rule when the office IP changes.
- Security group `training-db`: inbound 3306 **only from** `training-web`.
- Elastic IP `3.142.192.133` (`eipalloc-070054ebb62477381`, tagged
  `training-web`), attached 2026-10-29, so the address and the EC2 DNS name
  survive stop/start. DNS records point at it.

### Hostname

The site is served at **`https://3-142-192-133.sslip.io`** for now.
sslip.io is a free public DNS service that resolves any name of the form
`a-b-c-d.sslip.io` to the IP `a.b.c.d`, so the name needs no DNS record and
Let's Encrypt issues a normal certificate for it (issued 2026-10-29). It is
a bridge, not the final name:

- The site depends on sslip.io's DNS being up.
- sslip.io is not on the public suffix list, so its Let's Encrypt rate
  limit (50 certificates per registered domain per week) is shared with
  every other sslip.io user; a renewal can be refused for a few days (the
  certificate is renewed 30 days before it expires, so there is slack).
- Google may refuse the redirect URI on that domain (section 8); if it
  does, the company hostname below is needed before Google Drive works.

The final name is a company DNS name (for example a `fullscale.io`
subdomain) with an `A` record at the Elastic IP **(admin)**; switching to it
is section 6, TLS. The EC2 DNS name (`ec2-…compute.amazonaws.com`) cannot
be used at all: Let's Encrypt refuses `*.amazonaws.com` names by policy
(tested 2026-10-29) and ACM cannot issue for it either, and without HTTPS
Google OAuth, the Google Picker and `admin:bootstrap` all refuse to work.

### EC2

- Amazon Linux 2023, `t3.small` (2 vCPU, 2 GB; resized from `t3.micro` on
  2026-10-29) plus a 2 GB swap file as a safety margin for `composer
  install` (section 3); PHP-FPM is sized for it. Resizing is stop, change
  the type, start; with the Elastic IP attached the address survives that.
- Root EBS gp3, 20 GB, **encrypted**, `DeleteOnTermination=false`. Uploaded
  evidence lives on this volume (`shared/private`), so it is the only
  stateful disk.
- Instance profile `AmazonEC2RoleforSSM`. Releases arrive by `scp`
  (section 7), so the instance needs no S3 access. The CloudWatch agent
  (section 9) also needs `CloudWatchAgentServerPolicy` on that role
  **(admin)**.
- EC2 **auto-recovery** **(admin)**: a CloudWatch `StatusCheckFailed_System`
  alarm with the recover action, so a host failure restarts the instance on
  new hardware with the same EBS volume and Elastic IP.

### RDS MySQL

- MySQL 8.4, `db.t3.small`, 100 GB gp3 with storage autoscaling to 1000 GB,
  **encrypted**, single-AZ (Multi-AZ doubles cost; the web tier is
  single-instance anyway), not publicly accessible, deletion protection on,
  parameter group `default.mysql8.4`.
- Master user `admin` with a self-managed password (no Secrets Manager; see
  Secrets below). The `dev_training_platform` database was created with the
  instance.
- Automated backups: 7-day retention. The current window, `07:05-07:35`
  UTC, is 15:05 Manila, inside office hours; move it to e.g.
  `18:00-19:00` UTC (`aws rds modify-db-instance --preferred-backup-window`,
  no downtime).
- The app connects over TLS: `MYSQL_ATTR_SSL_CA` in `.env` points at the
  RDS CA bundle installed in section 3. MySQL 8.4 authenticates with
  `caching_sha2_password`, which works cleanly over TLS.
- The application user is created once from the instance with
  `sudo training-create-db-user` (installed in section 3). It reads
  `DB_PASSWORD` from `shared/.env`, prompts for the `admin` password, and
  runs:

  ```sql
  CREATE USER IF NOT EXISTS 'dev_training_app'@'%' IDENTIFIED BY '<DB_PASSWORD>';
  ALTER USER 'dev_training_app'@'%' IDENTIFIED BY '<DB_PASSWORD>';
  GRANT ALL PRIVILEGES ON dev_training_platform.* TO 'dev_training_app'@'%';
  ```

  The `ALTER USER` makes the user's password match `.env` even when the
  user already existed (`CREATE USER IF NOT EXISTS` leaves an existing
  password alone), and makes the helper the way to reset it.
  The `%` host is acceptable because the security group already restricts
  the source to the web instance.

### Secrets

No secrets manager is used. The only place Laravel reads secrets from is
`shared/.env` on the instance (section 5); keep it out of Git and never copy
it onto a developer machine.

So the instance can be rebuilt, keep a second copy of the secret values in
the team password manager, updated whenever one changes:

- `APP_KEY`: the most important one. Settings saved on the admin Google
  Drive page are encrypted with it; losing it makes them unreadable.
- `DB_PASSWORD` (`dev_training_app`) and the RDS master password.
- The `training-web` SSH key.
- Google OAuth client secret and Picker API key.
- Mail (SES SMTP) credentials.

The daily EBS snapshot (section 9) also contains `.env`, but only inside
AWS and only for its 14-day retention, so it is not a substitute for that
copy.

## 3. Server provisioning

Run once on a fresh instance, as `ec2-user` over SSH.

```bash
# 2 GB swap: headroom for composer install next to php-fpm and the worker
sudo dd if=/dev/zero of=/swapfile bs=1M count=2048
sudo chmod 600 /swapfile && sudo mkswap /swapfile && sudo swapon /swapfile
echo '/swapfile none swap sw 0 0' | sudo tee -a /etc/fstab
echo 'vm.swappiness=10' | sudo tee /etc/sysctl.d/99-swappiness.conf && sudo sysctl -p /etc/sysctl.d/99-swappiness.conf

# nginx, PHP 8.4 (Laravel 13 needs >= 8.3), cron, tools; the mariadb105 client is preinstalled
sudo dnf install -y nginx cronie git unzip dnf-automatic certbot \
  php8.4-fpm php8.4-cli php8.4-mysqlnd php8.4-mbstring php8.4-xml php8.4-intl \
  php8.4-gd php8.4-opcache php8.4-bcmath php8.4-zip php8.4-process php8.4-sodium

# Composer, with the installer signature checked
cd /tmp
EXPECTED=$(curl -fsSL https://composer.github.io/installer.sig)
curl -fsSL https://getcomposer.org/installer -o composer-setup.php
[ "$EXPECTED" = "$(php -r 'echo hash_file("sha384", "composer-setup.php");')" ] && \
  sudo php composer-setup.php --install-dir=/usr/local/bin --filename=composer
rm composer-setup.php

# Unattended security updates
sudo sed -i -e 's/^upgrade_type = .*/upgrade_type = security/' -e 's/^apply_updates = .*/apply_updates = yes/' /etc/dnf/automatic.conf
sudo systemctl enable --now dnf-automatic.timer

# RDS CA bundle for TLS to the database (MYSQL_ATTR_SSL_CA)
sudo install -d -m 755 /etc/pki/rds
sudo curl -fsSL https://truststore.pki.rds.amazonaws.com/global/global-bundle.pem -o /etc/pki/rds/global-bundle.pem

# Let the release steps (run as nginx) reload php-fpm after a switch (section 7)
echo 'nginx ALL=(root) NOPASSWD: /usr/bin/systemctl reload php-fpm' | sudo tee /etc/sudoers.d/training-release
sudo chmod 440 /etc/sudoers.d/training-release

sudo systemctl enable --now php-fpm nginx crond
```

`composer.json` declares no `ext-*` requirements, so the extension list above
is what Laravel and the installed packages need (`pdo_mysql`, `mbstring`,
`xml`, `curl`, `zip`, `bcmath`, `intl`, `gd`; `curl`, `openssl`, `fileinfo`
and `tokenizer` come with `php8.4-common`).

Node is **not** installed on the server: `public/build` is produced by
`npm run build` on the build side and shipped inside the release archive
(`atomic-release.sh prepare` refuses an archive without
`public/build/manifest.json`).

PHP-FPM pool (`/etc/php-fpm.d/www.conf`): change `user`, `group` and
`listen.acl_users` from `apache` to `nginx`; for 2 GB RAM set
`pm.max_children = 10`, `pm.start_servers = 3`, `pm.min_spare_servers = 2`,
`pm.max_spare_servers = 5`; and append
`php_admin_value[opcache.validate_timestamps] = 0` (releases are immutable;
a new release gets a fresh opcache because php-fpm is reloaded after
activation, section 7). That setting goes in the pool, not `php.ini`,
because AL2023 also enables opcache for the CLI. Then
`sudo chgrp nginx /var/lib/php/session /var/lib/php/wsdlcache /var/lib/php/opcache`.

PHP settings go in `/etc/php.d/99-training.ini`:

```ini
upload_max_filesize = 300M
post_max_size = 310M
max_execution_time = 120
upload_tmp_dir = /var/lib/php/upload
expose_php = Off
```

Evidence files may be up to `training.evidence_max_kb` (300 MB; weekly
videos are links and need no limit). `/tmp` on AL2023 is a RAM-backed tmpfs
of half the memory (457 MB here), too small for a 300 MB upload, so uploads
are buffered on disk: `sudo install -d -o nginx -g nginx -m 700 /var/lib/php/upload`.
nginx already buffers request bodies on disk (`/var/lib/nginx/tmp`).

Finally install the database-user helper used in section 2 as
`/usr/local/sbin/training-create-db-user` (root, mode 700):

```bash
#!/usr/bin/env bash
# Creates the application database user from DB_PASSWORD in shared/.env.
# Prompts for the RDS master (admin) password; run once.
set -euo pipefail
ENV=/var/www/training/shared/.env
DB_HOST=$(grep '^DB_HOST=' "$ENV" | cut -d= -f2-)
DB_PASSWORD=$(grep '^DB_PASSWORD=' "$ENV" | cut -d= -f2-)
mysql --ssl-ca=/etc/pki/rds/global-bundle.pem --ssl-verify-server-cert -h "$DB_HOST" -u admin -p <<SQL
CREATE USER IF NOT EXISTS 'dev_training_app'@'%' IDENTIFIED BY '$DB_PASSWORD';
ALTER USER 'dev_training_app'@'%' IDENTIFIED BY '$DB_PASSWORD';
GRANT ALL PRIVILEGES ON dev_training_platform.* TO 'dev_training_app'@'%';
SELECT CONCAT(user, "@", host, " ready") AS result FROM mysql.user WHERE user = 'dev_training_app';
SQL
```

## 4. Directory layout

```text
/var/www/training/
  current -> /var/www/training/releases/<sha>      # live symlink (nginx root is current/public)
  releases/
    <sha>/                                         # immutable release
  shared/
    .env                                           # production environment
    private/                                       # storage/app/private (submission evidence)
    logs/                                          # storage/logs and worker.log
  atomic-release.sh                                # copy of scripts/atomic-release.sh from the release being deployed
```

`atomic-release.sh` has to exist before `prepare` extracts the archive, so
each release first copies its own `scripts/atomic-release.sh` here (section
7). That keeps the script in step with the release that uses it.

Create it once:

```bash
sudo mkdir -p /var/www/training/{releases,shared/private,shared/logs}
sudo chown -R nginx:nginx /var/www/training
sudo chmod 750 /var/www/training/shared
# first release placeholder so `prepare` passes its "must be a symlink" check
sudo -u nginx ln -s /var/www/training/releases/bootstrap /var/www/training/current
```

This matches the paths `atomic-release.sh` expects:
`--app-link /var/www/training/current --releases-root /var/www/training/releases --shared-root /var/www/training/shared`.

## 5. Production `.env`

`/var/www/training/shared/.env`, owned by `nginx`, mode `640`. Generate the
secrets on the instance so they never leave it
(`APP_KEY="base64:$(openssl rand -base64 32)"`,
`DB_PASSWORD=$(openssl rand -base64 48 | tr -dc A-Za-z0-9 | head -c 32)`),
then copy them into the password manager. Differences from `.env.example`:

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://<host>        # currently https://3-142-192-133.sslip.io (section 2)
APP_KEY=                      # generated once; never rotate casually (encrypted settings)
LOG_CHANNEL=stack
LOG_STACK=daily
LOG_LEVEL=warning

DB_HOST=training-web-db.chs5dvwmkvnl.us-east-2.rds.amazonaws.com
DB_DATABASE=dev_training_platform
DB_USERNAME=dev_training_app
DB_PASSWORD=<generated; copy kept in the team password manager>
MYSQL_ATTR_SSL_CA=/etc/pki/rds/global-bundle.pem

SESSION_DRIVER=database       # already DB-backed; nothing instance-local
CACHE_STORE=database
QUEUE_CONNECTION=database
DB_QUEUE_RETRY_AFTER=90       # must stay > worker --timeout (section 6)
FILESYSTEM_DISK=local         # evidence stays on EBS; move to s3 only when adding instances

MAIL_MAILER=smtp              # Amazon SES SMTP or the company mail relay; `log` until set up
MAIL_HOST=email-smtp.<region>.amazonaws.com
MAIL_PORT=587
MAIL_SCHEME=tls
MAIL_FROM_ADDRESS=<verified sender>

BOOTSTRAP_ADMIN_TEMPORARY_PASSWORD=   # set only for the first-admin bootstrap, then clear it

GOOGLE_DRIVE_ENABLED=true     # false until the hostname and Google setup exist
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

Every artisan command, including `package:discover` inside `composer
install`, reads `application_settings` while booting, so the database must
be reachable and the credentials right before `prepare` can pass.

## 6. Processes

### nginx

`/etc/nginx/conf.d/training.conf`, with `<host>` the hostname (section 2).
The AL2023 `nginx.conf` keeps its own port-80 server, but it is not the
default server, so these take all requests; `conf.d/php-fpm.conf` already
defines the `php-fpm` upstream. Port 80 only answers Let's Encrypt
challenges and redirects everything else, including requests to the bare
IP, to `https://<host>`.

```nginx
server {
    listen 80 default_server;
    listen [::]:80 default_server;
    server_name _;
    server_tokens off;

    # Let's Encrypt HTTP-01 challenges (certbot --webroot, also used on renewal)
    location ^~ /.well-known/acme-challenge/ {
        root /var/www/letsencrypt;
    }

    location / {
        return 301 https://<host>$request_uri;
    }
}

server {
    listen 443 ssl default_server;
    listen [::]:443 ssl default_server;
    http2 on;
    server_name <host>;
    root /var/www/training/current/public;
    index index.php;

    ssl_certificate     /etc/letsencrypt/live/<host>/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/<host>/privkey.pem;
    ssl_protocols TLSv1.2 TLSv1.3;
    ssl_session_cache shared:SSL:10m;
    ssl_session_timeout 1d;

    client_max_body_size 310M;   # evidence uploads up to 300 MB (training.evidence_max_kb)
    server_tokens off;
    add_header X-Frame-Options SAMEORIGIN;
    add_header X-Content-Type-Options nosniff;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_pass php-fpm;
        fastcgi_read_timeout 120;
        # $realpath_root, not $document_root: resolve the symlink per request so a
        # release switch takes effect without a stale path in php-fpm
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        fastcgi_param DOCUMENT_ROOT $realpath_root;
    }

    location ~ /\.(?!well-known) { deny all; }
}
```

### TLS (Let's Encrypt)

The certificate is issued with the webroot method, so nginx keeps serving
while certbot works and the config above is never edited by certbot. For a
new hostname (the sslip.io name now; the company name later):

```bash
sudo install -d -o nginx -g nginx /var/www/letsencrypt
# the port-80 server above must already be live (it serves the challenge)
sudo certbot certonly --webroot -w /var/www/letsencrypt --non-interactive \
  --agree-tos --register-unsafely-without-email -d <host>
# then put <host> into training.conf, `sudo nginx -t && sudo systemctl reload nginx`
```

Renewal, once per server:

```bash
sudo install -d /etc/letsencrypt/renewal-hooks/deploy
printf '#!/bin/sh\nsystemctl reload nginx\n' | sudo tee /etc/letsencrypt/renewal-hooks/deploy/reload-nginx
sudo chmod 755 /etc/letsencrypt/renewal-hooks/deploy/reload-nginx
sudo systemctl enable --now certbot-renew.timer
sudo certbot renew --dry-run          # proves the challenge path works
```

Switching hostname: issue the certificate for the new name, update
`training.conf`, set `APP_URL=https://<new host>` in `shared/.env`, re-run
the release `prepare`/`activate` (the config cache holds `APP_URL`), update
the Google redirect URI and JavaScript origin (section 8), then
`sudo certbot delete --cert-name <old host>`. (If an ALB is added later,
terminate TLS with an ACM certificate there instead.)

### Queue worker (systemd)

AL2023 has no Supervisor package; systemd does the same job.
`/etc/systemd/system/training-worker.service`:

```ini
[Unit]
Description=Training platform queue worker
After=network-online.target php-fpm.service
Wants=network-online.target

[Service]
User=nginx
Group=nginx
WorkingDirectory=/var/www/training/current
ExecStart=/usr/bin/php /var/www/training/current/artisan queue:work database --sleep=3 --tries=3 --timeout=60 --max-time=3600 --max-jobs=500
Restart=always
RestartSec=5
KillSignal=SIGTERM
TimeoutStopSec=70
StandardOutput=append:/var/www/training/shared/logs/worker.log
StandardError=append:/var/www/training/shared/logs/worker.log

[Install]
WantedBy=multi-user.target
```

Notes:

- `--timeout=60` < `DB_QUEUE_RETRY_AFTER=90` so a job is never handed to a
  second worker while still running. Keep this ordering if either changes.
- `--max-time=3600` makes the worker exit and get restarted by systemd
  (`Restart=always`) hourly, which bounds memory growth. Every deploy also
  runs `php artisan queue:restart` (section 7) so the worker picks up the
  new release; without it the worker keeps executing the *old* release's
  code. The restarted process resolves the `current` symlink afresh.
- One process is enough: `GenerateParticipantNotificationsJob` is unique per
  participant and `SyncTrainingDataJob` is light. Add a second unit (or a
  templated `training-worker@.service`) only if
  `php artisan queue:monitor database:default --max=100` reports a backlog.
- `TimeoutStopSec=70` > `--timeout` so a graceful stop lets the current job
  finish.

Enable it after the first release is active (before that `current` points
at the placeholder and the unit would restart in a loop):
`sudo systemctl daemon-reload && sudo systemctl enable --now training-worker`.
Status: `systemctl status training-worker`; logs: `shared/logs/worker.log`.

This replaces the former shared-hosting `queue-cron.sh` (`--stop-when-empty`
from cron). Do not install that script on EC2.

### Scheduler (cron)

`sudo crontab -u nginx -e`:

```cron
* * * * * cd /var/www/training/current && /usr/bin/php artisan schedule:run >> /dev/null 2>&1
```

Laravel decides inside `schedule:run` which tasks are due, so the only entry
is this one; `notifications:sync-participants` runs at the top of every hour
from it. The `cd` goes through the `current` symlink, so cron always runs the
live release. (The `nginx` user's `/sbin/nologin` shell does not matter:
cron runs the line with `/bin/sh`.)

`schedule:run` must run on exactly one host. When a second instance is ever
added, use `->onOneServer()` with a shared cache store, or keep cron on one
designated instance.

## 7. Release procedure

Build off the machine, ship an archive, prepare, migrate, activate, restart
the worker.

### Build (developer machine)

```bash
SHA=$(git rev-parse --short HEAD)
npm ci && npm run build                                  # produces public/build
mkdir -p /tmp/release-$SHA
git archive --format=tar HEAD | tar -xf - -C /tmp/release-$SHA
cp -R public/build /tmp/release-$SHA/public/build
# macOS: leave out extended attributes, which GNU tar on the server warns about per file
COPYFILE_DISABLE=1 tar --no-xattrs --no-mac-metadata -czf release-$SHA.tar.gz -C /tmp/release-$SHA .
```

`vendor` is not shipped: `prepare` runs `composer install --no-dev` on the
instance from the committed `composer.lock`, with the server's PHP, so the
platform check matches the runtime.

Ship it: `scp -i training-web.pem release-$SHA.tar.gz ec2-user@<host or IP>:/tmp/`.

### On the instance

```bash
ROOT=/var/www/training

# 0. Take the release script from the archive (it must exist before prepare extracts).
sudo -u nginx tar -xzf /tmp/release-$SHA.tar.gz -C $ROOT --strip-components=2 ./scripts/atomic-release.sh

# 1. Prepare: extracts, links shared .env/private/logs, composer install, builds
#    caches and refuses if migrations are pending.
sudo -u nginx $ROOT/atomic-release.sh prepare \
  --archive /tmp/release-$SHA.tar.gz --sha $SHA \
  --app-link $ROOT/current --releases-root $ROOT/releases --shared-root $ROOT/shared

# 2. Migrate (only when prepare stopped on "unexpected pending migrations"). The
#    script never migrates on its own. Write schema changes expand/migrate/contract
#    (add first, switch code, drop later) so the still-live old release keeps
#    working during the switch and `rollback` stays possible. Re-running prepare
#    wipes and re-extracts the release directory, composer install included.
sudo -u nginx php $ROOT/releases/$SHA/artisan migrate --force
sudo -u nginx $ROOT/atomic-release.sh prepare ...     # same arguments; now passes

# 3. Activate: single mv -T of the symlink, then `queue:restart` so the worker
#    reloads onto the new release, then the after-switch command (drop the old
#    opcache; validate_timestamps=0; allowed by the sudoers line in section 3), then
#    the HTTP health check.
sudo -u nginx $ROOT/atomic-release.sh activate \
  --app-link $ROOT/current --release $ROOT/releases/$SHA \
  --after-switch 'sudo systemctl reload php-fpm' \
  --health-url https://<host>/up

# 4. Prune: keep the live release plus the 4 newest others for rollback.
LIVE=$(readlink -f $ROOT/current)
ls -dt $ROOT/releases/*/ | grep -vx "$LIVE/" | tail -n +5 | xargs -r sudo rm -rf
rm /tmp/release-$SHA.tar.gz
```

`/up` is the framework health route registered in `bootstrap/app.php`.

### Rollback

```bash
sudo -u nginx /var/www/training/atomic-release.sh rollback \
  --app-link /var/www/training/current \
  --release /var/www/training/releases/<previous sha> \
  --after-switch 'sudo systemctl reload php-fpm' \
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
1 of the L4 curriculum (Mon 9 Nov 2026) asks for one, so set this up before
then:

- A Google Cloud project in the company's Google Workspace organization.
- An OAuth consent screen set to *Internal* (only company Google accounts
  can connect), with the scopes `openid`, `email`, `profile` and
  `https://www.googleapis.com/auth/drive.file`.
- The Google Drive API and the Google Picker API enabled.
- A *Web application* OAuth client with the authorized redirect URI
  `https://<host>/profile/google-drive/callback` and the production origin
  (`https://<host>`) as an authorized JavaScript origin. With the sslip.io
  bridge (section 2) that is
  `https://3-142-192-133.sslip.io/profile/google-drive/callback`; if Google
  rejects the domain when saving the client, Google Drive waits for the
  company hostname.
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

From the active release (`/var/www/training/current`), as `nginx`:

```bash
php artisan db:seed --class=L4TrainingProgramSeeder --force
```

It seeds the *L4 Accelerator Training* curriculum, 4 weeks from Mon 9 Nov
to Sun 6 Dec 2026, when no curriculum of that name exists, and otherwise
leaves it alone, so edits made on the admin Curriculum pages stay. The one
exception is a curriculum none of whose weeks has dates yet: it dates its
weeks from Mon 9 Nov 2026, once. Re-running it is safe.

- Never run a bare `php artisan db:seed` in production: it also runs the demo
  account provisioner.
- Never run `app:ensure-development-accounts` or
  `app:ensure-development-baseline` there; both refuse the production
  environment.
- Create the first admin: set `BOOTSTRAP_ADMIN_TEMPORARY_PASSWORD` in
  `shared/.env`, run `php artisan config:cache` (the release caches its
  config, so `.env` edits are invisible until then), run
  `php artisan admin:bootstrap` (it creates the admin account whose email
  is fixed in `BootstrapAdminCommand`, and refuses in production unless
  `APP_URL` is `https`), clear the variable from `.env` (section 5) and run
  `config:cache` and `sudo systemctl reload php-fpm` again. Sign in and
  change the password. Further admins are created from the admin user
  pages. Done 2026-10-29: the admin is `mprestoza@fullscale.ph`.

## 9. Backups, monitoring, recovery

| What                      | How                                                                                  | Restore test                                      |
|---------------------------|--------------------------------------------------------------------------------------|---------------------------------------------------|
| Database                  | RDS automated backups, 7 days + a manual snapshot before every migrating release     | Restore snapshot to a temp instance, run `migrate:status` |
| Uploads (`shared/private`)| AWS Data Lifecycle Manager daily EBS snapshot of the root volume, 14-day retention **(admin)** | Mount a snapshot volume on a temp instance |
| `.env`                    | Secret values in the team password manager (section 2, Secrets); the file itself is in the EBS snapshot | Compare the password manager copy against `shared/.env` after each change |
| Logs                      | CloudWatch agent tailing `shared/logs/laravel-*.log` and `worker.log`; 30-day retention (needs the role policy in section 2) | —                                              |

CloudWatch alarms worth having from day one **(admin)**:

- `StatusCheckFailed_System` → recover instance (section 2).
- RDS `FreeStorageSpace` < 2 GB, `CPUUtilization` > 80 % for 15 min.
- EC2 `disk_used_percent` (CloudWatch agent) > 80 %: uploads and logs are
  the growth sources on a 20 GB volume.
- A log metric filter on `laravel-*.log` for `.ERROR:` → alarm when > 0 in 5
  minutes.
- Route 53 or an external uptime check on `https://<host>/up`.

Queue health: `php artisan queue:monitor database:default --max=100`
scheduled hourly, or just watch `worker.log` for `DONE`/`FAIL` lines.
Failed jobs land in `failed_jobs`; retry with `php artisan queue:retry all`.

## 10. Go-live order

Status on 2026-10-29: steps 1 to 4 and 6 are done. Release `839a182` is live at
`https://3-142-192-133.sslip.io` (`.env` with mail on `log` and Google
Drive off), the schema is migrated, the worker and the cron entry run, and
nginx serves HTTPS for that name with automatic renewal (section 6), the
L4 program is seeded and the first admin exists. Next is step 5.

1. Create the application database user: `sudo training-create-db-user`
   (section 2).
2. Prepare and activate the first release (section 7, with the first-release
   notes of section 8); the `current` placeholder symlink from section 4
   makes `prepare` pass its symlink check.
3. Enable the worker and install the cron entry (section 6).
4. Verify: `https://<host>/up` returns 200, `systemctl status training-worker`
   is `active (running)`, `php artisan schedule:list` shows the hourly
   command.
5. Set up Google (section 8) and mail (section 5) and run their checks.
6. Seed the program and bootstrap the admin (section 8).
7. **(admin)** Create the company DNS record at the Elastic IP and switch
   to it (section 2, Hostname; section 6, TLS).
8. **(admin)** Switch on the backups and alarms of section 9; move the RDS
   backup window (section 2).

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
- [x] `scripts/atomic-release.sh` committed with the executable bit, so the
      copy extracted in section 7 step 0 can be run directly.
- [ ] Remove `queue-cron.sh` (shared-hosting paths, `--stop-when-empty`
      workaround); the systemd worker replaces it.
- [ ] Add `Schedule::command('queue:monitor database:default --max=100')->hourly()`
      to `routes/console.php` so a backlog is logged.
- [ ] Optional CI: a GitHub Actions workflow that runs the test suite and
      builds the archive (section 7) on a tag, leaving the upload and
      `prepare`/`activate` as manual steps.

## 12. When to revisit

Move evidence to S3 (`FILESYSTEM_DISK=s3`, `AWS_BUCKET`) and put an ALB in
front **before** adding a second instance; sessions, cache and queue are
already in the database so they need no change. At that point the release
archive becomes a container image and ECS replaces the systemd worker and cron, which
is the Docker decision deferred here.

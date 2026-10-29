#!/usr/bin/env bash
# Deploys the committed HEAD to production, or switches production back to an earlier release.
#
#   scripts/deploy.sh                  test, build, upload, prepare, migrate (after asking), activate, prune
#   scripts/deploy.sh --skip-tests     the same without the local test run
#   scripts/deploy.sh rollback         list the releases on the server
#   scripts/deploy.sh rollback SHA     switch production back to release SHA
#
# Runs on the developer machine (macOS bash 3.2 or later) from anywhere inside the repository and reaches the server
# through the SSH host in DEPLOY_HOST (default `training`, see ~/.ssh/config). The steps it runs there are
# docs/AWS_DEPLOYMENT.md section 7. A change to shared/.env alone is not a deploy; that section says how to apply one.
set -euo pipefail

DEPLOY_HOST="${DEPLOY_HOST:-training}"
DEPLOY_URL="${DEPLOY_URL:-https://3-142-192-133.sslip.io}"
PHP="${PHP:-php}"
ROOT=/var/www/training
OTHER_RELEASES_KEPT=4
work=""

step() { printf '\n==> %s\n' "$*"; }
die() { printf '\nERROR: %s\n' "$*" >&2; exit 1; }
confirm() {
    local answer
    read -r -p "$1 [y/N] " answer
    [ "$answer" = y ] || [ "$answer" = Y ]
}

# Runs the script on standard input on the server with bash, passing the arguments; every command in it reads
# /dev/null, so nothing it starts can swallow the rest of the script.
remote() { ssh "$DEPLOY_HOST" bash -s -- "$@"; }

live_release() { ssh "$DEPLOY_HOST" readlink "$ROOT/current" | xargs basename; }

usage() { sed -n '2,11p' "$0" | sed 's/^# \{0,1\}//'; }

deploy() {
    local skip_tests="$1"
    cd "$(git rev-parse --show-toplevel)"

    step "Checking the working tree"
    [ -z "$(git status --porcelain)" ] || die "there are uncommitted changes; commit or stash them (only committed code is deployed)"
    local sha branch live
    sha="$(git rev-parse --short HEAD)"
    branch="$(git rev-parse --abbrev-ref HEAD)"
    live="$(live_release)" || die "could not reach $DEPLOY_HOST over SSH"
    [ "$sha" != "$live" ] || die "$sha is already live; nothing to deploy (for a .env change see docs/AWS_DEPLOYMENT.md section 7)"
    if [ "$branch" != main ]; then
        confirm "HEAD is on branch $branch, not main. Deploy it anyway?" || exit 1
    fi
    printf 'Deploying %s to %s (live now: %s)\n' "$sha" "$DEPLOY_URL" "$live"
    if git cat-file -e "$live^{commit}" 2>/dev/null; then
        git --no-pager log --oneline "$live..HEAD"
    fi
    confirm "Continue?" || exit 1

    if [ "$skip_tests" = 0 ]; then
        step "Running the test suite"
        "$PHP" artisan test --compact
    fi

    step "Building the release archive"
    local archive
    work="$(mktemp -d)"
    trap 'rm -rf "$work"' EXIT
    archive="$work/release-$sha.tar.gz"
    npm ci
    npm run build
    mkdir "$work/release"
    git archive --format=tar HEAD | tar -xf - -C "$work/release"
    cp -R public/build "$work/release/public/build"
    if tar --version 2>/dev/null | grep -q bsdtar; then
        # macOS: leave out extended attributes, which GNU tar on the server warns about once per file
        COPYFILE_DISABLE=1 tar --no-xattrs --no-mac-metadata -czf "$archive" -C "$work/release" .
    else
        tar -czf "$archive" -C "$work/release" .
    fi

    step "Uploading"
    scp -q "$archive" "$DEPLOY_HOST:/tmp/"

    step "Preparing release $sha on the server (the live site is not touched)"
    if ! prepare "$sha"; then
        grep -q "unexpected pending migrations" "$work/prepare.log" || die "prepare failed; the live site is unchanged (output above)"
        step "Release $sha has migrations to run"
        remote "$sha" <<'REMOTE'
cd /var/www/training/releases/$1 && sudo -u nginx php artisan migrate:status --no-ansi </dev/null | grep Pending
REMOTE
        printf '\nTake a manual RDS snapshot first: AWS console, RDS, training-web-db, Actions, Take snapshot.\n'
        printf 'The live release keeps running against the migrated database until the switch.\n'
        confirm "Snapshot taken. Run these migrations now?" || die "stopped before migrating; the live site is unchanged"
        remote "$sha" <<'REMOTE'
set -euo pipefail
sudo -u nginx php /var/www/training/releases/$1/artisan migrate --force --no-ansi </dev/null
REMOTE
        step "Preparing release $sha again"
        prepare "$sha" || die "prepare failed after migrating; the live site still runs $live (output above)"
    fi

    step "Activating $sha"
    remote "$sha" "$DEPLOY_URL" <<'REMOTE'
set -euo pipefail
ROOT=/var/www/training
sudo -u nginx $ROOT/atomic-release.sh activate --app-link $ROOT/current --release $ROOT/releases/$1 \
    --after-switch 'sudo systemctl reload php-fpm' --health-url "$2/up" </dev/null
REMOTE

    step "Checking the worker and pruning old releases"
    remote "$OTHER_RELEASES_KEPT" "$sha" <<'REMOTE'
set -euo pipefail
ROOT=/var/www/training
sleep 5
systemctl is-active --quiet training-worker || { echo "WARNING: training-worker is not running: sudo systemctl status training-worker"; }
LIVE=$(readlink -f $ROOT/current)
ls -dt $ROOT/releases/*/ | grep -vx "$LIVE/" | tail -n +$(($1 + 1)) | xargs -r sudo rm -rf </dev/null
rm -f /tmp/release-$2.tar.gz
echo "Releases on the server, newest first:"
ls -t $ROOT/releases
REMOTE

    step "Done: $sha is live at $DEPLOY_URL"
    printf 'Open the site and check what changed. To undo: scripts/deploy.sh rollback %s\n' "$live"
}

# Extracts the release script from the uploaded archive and runs prepare, showing its output without composer's
# per-package lines and keeping all of it in $work/prepare.log.
prepare() {
    local status
    set +e
    remote "$1" <<'REMOTE' 2>&1 | tee "$work/prepare.log" | grep -vE '^[[:space:]]+- (Installing|Downloading) '
set -euo pipefail
ROOT=/var/www/training
sudo -u nginx tar -xzf /tmp/release-$1.tar.gz -C $ROOT --strip-components=2 ./scripts/atomic-release.sh </dev/null
sudo -u nginx $ROOT/atomic-release.sh prepare --archive /tmp/release-$1.tar.gz --sha $1 \
    --app-link $ROOT/current --releases-root $ROOT/releases --shared-root $ROOT/shared </dev/null
REMOTE
    status="${PIPESTATUS[0]}"
    set -e
    return "$status"
}

rollback() {
    local target="$1" live
    live="$(live_release)" || die "could not reach $DEPLOY_HOST over SSH"
    if [ -z "$target" ]; then
        printf 'Releases on the server, newest first (live: %s):\n' "$live"
        ssh "$DEPLOY_HOST" ls -t "$ROOT/releases"
        printf '\nRoll back with: scripts/deploy.sh rollback SHA\n'
        return
    fi
    [ "$target" != "$live" ] || die "$target is already live"
    ssh "$DEPLOY_HOST" test -d "$ROOT/releases/$target" || die "there is no release $target on the server (scripts/deploy.sh rollback lists them)"
    printf 'Rolling back from %s to %s. Migrations are not undone.\n' "$live" "$target"
    confirm "Continue?" || exit 1
    remote "$target" "$DEPLOY_URL" <<'REMOTE'
set -euo pipefail
ROOT=/var/www/training
sudo -u nginx $ROOT/atomic-release.sh rollback --app-link $ROOT/current --release $ROOT/releases/$1 \
    --after-switch 'sudo systemctl reload php-fpm' --health-url "$2/up" </dev/null
REMOTE
    step "Done: $target is live at $DEPLOY_URL"
}

case "${1:-}" in
    "") deploy 0 ;;
    --skip-tests) deploy 1 ;;
    rollback) rollback "${2:-}" ;;
    -h|--help) usage ;;
    *) usage; exit 2 ;;
esac

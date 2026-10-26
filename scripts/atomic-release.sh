#!/usr/bin/env bash
set -euo pipefail

usage() {
    cat <<'EOF'
Usage:
  atomic-release.sh simulate
  atomic-release.sh prepare --archive ARCHIVE --sha SHA --app-link LINK --releases-root ROOT --shared-root ROOT [--php PHP] [--composer COMPOSER]
  atomic-release.sh activate --app-link LINK --release RELEASE [--health-url URL] [--php PHP] [--after-switch CMD]
  atomic-release.sh rollback --app-link LINK --release RELEASE [--health-url URL] [--php PHP] [--after-switch CMD]

The app link must already be a symlink for activate/rollback. The one-time
conversion of the existing live directory to this layout requires a planned
maintenance operation and is intentionally refused by this script.

After the symlink switch, activate and rollback run `php artisan queue:restart`
from the activated release so a long-running queue worker (Supervisor, systemd)
reloads onto it, then run the optional --after-switch shell command (for
example `sudo systemctl reload php8.4-fpm`) before the health check.
EOF
}

die() { printf 'ERROR: %s\n' "$*" >&2; exit 1; }

assert_file() { test -f "$1" || die "missing file: $1"; }
assert_dir() { test -d "$1" || die "missing directory: $1"; }

health_check() {
    local release="$1"
    local php_bin="${2:-php}"
    assert_file "$release/artisan"
    assert_file "$release/vendor/autoload.php"
    assert_file "$release/public/build/manifest.json"
    assert_dir "$release/storage/app/private"
    assert_dir "$release/storage/framework/cache/data"
    assert_dir "$release/storage/framework/sessions"
    assert_dir "$release/storage/framework/views"
    assert_dir "$release/storage/logs"
    test -w "$release/storage/framework/cache/data" || die "cache is not writable"
    test -w "$release/storage/framework/sessions" || die "sessions are not writable"
    test -w "$release/storage/framework/views" || die "views are not writable"
    (cd "$release" && "$php_bin" artisan about --no-ansi >/dev/null)
    (cd "$release" && "$php_bin" artisan route:cache >/dev/null)
    (cd "$release" && "$php_bin" artisan config:cache >/dev/null)
    (cd "$release" && "$php_bin" artisan view:cache >/dev/null)
    local migration_status
    migration_status="$(mktemp)"
    (cd "$release" && "$php_bin" artisan migrate:status --no-ansi > "$migration_status")
    ! grep -q Pending "$migration_status" || die "unexpected pending migrations"
}

simulate() {
    local root
    root="$(mktemp -d)"
    mkdir -p "$root/releases/a" "$root/releases/b" "$root/shared/private"
    printf 'A\n' > "$root/shared/private/A"
    printf 'A release\n' > "$root/releases/a/APP"
    ln -s "$root/releases/a" "$root/current"
    test -f "$root/current/APP"

    # Release B is built away from live traffic; A remains live and its data stays intact.
    printf 'B release\n' > "$root/releases/b/APP"
    test "$(cat "$root/current/APP")" = 'A release'
    test "$(cat "$root/shared/private/A")" = 'A'
    ln -s "$root/releases/b" "$root/current.next"
    rm -rf "$root/current"
    mv "$root/current.next" "$root/current"
    test "$(cat "$root/current/APP")" = 'B release'

    printf 'C\n' > "$root/shared/private/C"
    test "$(cat "$root/shared/private/A")" = 'A'
    test "$(cat "$root/shared/private/C")" = 'C'
    mkdir "$root/releases/c"
    # An incomplete release never gets a current pointer.
    if [ -f "$root/releases/c/READY" ]; then die 'incomplete release unexpectedly activated'; fi
    test "$(cat "$root/current/APP")" = 'B release'

    ln -s "$root/releases/a" "$root/current.rollback"
    rm -rf "$root/current"
    mv "$root/current.rollback" "$root/current"
    test "$(cat "$root/current/APP")" = 'A release'
    test "$(cat "$root/shared/private/A")" = 'A'
    test "$(cat "$root/shared/private/C")" = 'C'
    printf 'atomic simulation passed\n'
    rm -rf "$root"
}

MODE="${1:-}"
shift || true
case "$MODE" in
    simulate)
        simulate
        ;;
    prepare)
        archive= sha= app_link= releases_root= shared_root= php_bin=php composer_bin=composer
        while [ "$#" -gt 0 ]; do
            case "$1" in
                --archive) archive="$2"; shift 2 ;;
                --sha) sha="$2"; shift 2 ;;
                --app-link) app_link="$2"; shift 2 ;;
                --releases-root) releases_root="$2"; shift 2 ;;
                --shared-root) shared_root="$2"; shift 2 ;;
                --php) php_bin="$2"; shift 2 ;;
                --composer) composer_bin="$2"; shift 2 ;;
                *) usage; die "unknown argument: $1" ;;
            esac
        done
        [ -n "$archive" ] && [ -n "$sha" ] && [ -n "$app_link" ] && [ -n "$releases_root" ] && [ -n "$shared_root" ] || { usage; exit 2; }
        assert_file "$archive"
        assert_dir "$shared_root"
        mkdir -p "$shared_root/private" "$shared_root/logs"
        test -L "$app_link" || die "live app path must be a symlink before prepare"
        release="$releases_root/$sha"
        mkdir -p "$releases_root"
        rm -rf "$release"
        mkdir -p "$release"
        tar -xzf "$archive" -C "$release"
        assert_file "$release/composer.lock"
        assert_file "$release/public/build/manifest.json"
        assert_file "$shared_root/.env"
        rm -f "$release/.env"
        ln -s "$shared_root/.env" "$release/.env"
        rm -rf "$release/storage"
        mkdir -p "$release/storage/app" "$release/storage/framework/cache/data" "$release/storage/framework/sessions" "$release/storage/framework/views"
        ln -s "$shared_root/private" "$release/storage/app/private"
        ln -s "$shared_root/logs" "$release/storage/logs"
        if [ ! -f "$release/vendor/autoload.php" ]; then
            (cd "$release" && "$composer_bin" install --no-dev --prefer-dist --no-interaction --optimize-autoloader)
        fi
        health_check "$release" "$php_bin"
        printf 'release prepared: %s\n' "$release"
        ;;
    activate|rollback)
        app_link= release= health_url= php_bin=php after_switch=
        while [ "$#" -gt 0 ]; do
            case "$1" in
                --app-link) app_link="$2"; shift 2 ;;
                --release|--target) release="$2"; shift 2 ;;
                --health-url) health_url="$2"; shift 2 ;;
                --php) php_bin="$2"; shift 2 ;;
                --after-switch) after_switch="$2"; shift 2 ;;
                *) usage; die "unknown argument: $1" ;;
            esac
        done
        [ -n "$app_link" ] && [ -n "$release" ] || { usage; exit 2; }
        test -L "$app_link" || die "live app path must be a symlink"
        assert_dir "$release"
        test -f "$release/vendor/autoload.php" || die "release is incomplete"
        test -s "$release/public/build/manifest.json" || die "release manifest is missing"
        ln -s "$release" "$app_link.next.$$"
        mv -Tf "$app_link.next.$$" "$app_link"
        # The worker keeps running the release it started with until told to restart.
        (cd "$release" && "$php_bin" artisan queue:restart --no-ansi >/dev/null) || die "queue:restart failed; the queue worker is still on the previous release"
        if [ -n "$after_switch" ]; then
            bash -c "$after_switch" || die "after-switch command failed: $after_switch"
        fi
        if [ -n "$health_url" ]; then
            code="$(curl -L -s -o /dev/null -w '%{http_code}' "$health_url")"
            [ "$code" = 200 ] || die "post-switch health check returned HTTP $code"
        fi
        printf '%s complete: %s\n' "$MODE" "$release"
        ;;
    *)
        usage
        exit 2
        ;;
esac

#!/usr/bin/env bash
set -Eeuo pipefail

APP_DIR='/home/u720282675/domains/qa.mprestoza.com/qa-training-platform'
LOCK_FILE='/tmp/qa-training-platform-queue.lock'
LOG_FILE="$APP_DIR/storage/logs/queue-worker.log"

cd "$APP_DIR"
exec /usr/bin/flock -n "$LOCK_FILE" /opt/alt/php84/usr/bin/php artisan queue:work database --stop-when-empty --tries=3 --timeout=60 --sleep=3 >> "$LOG_FILE" 2>&1

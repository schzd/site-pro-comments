#!/usr/bin/env bash
# One-shot bootstrap executed by the `setup` compose service.
# Idempotent: safe to run on every `docker compose up`.
set -euo pipefail
cd /app

log() { printf '[setup] %s\n' "$*"; }

log "composer install"
composer install --no-interaction --no-progress --prefer-dist

# Generates *-local.php configs, web/index.php entry scripts and cookie keys.
# --overwrite=n keeps anything that already exists.
log "php init (Development)"
php init --env=Development --overwrite=n

log "migrations -> ${DB_DSN}"
php yii migrate --interactive=0

log "seed admin user '${ADMIN_USERNAME}'"
php yii seed/admin

log "done"

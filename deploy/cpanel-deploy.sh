#!/bin/bash
# =============================================================================
# Prompt Aid — cPanel "Deploy HEAD Commit" script (no Terminal/SSH needed).
#
# Run by .cpanel.yml from the Git Version Control clone of this repo. It:
#   1. finds the live Laravel app folder (the one holding your real .env),
#   2. backs up the live code to ~/promptaid-backups/ (for rollback),
#   3. puts the site in maintenance mode,
#   4. copies backend/ over the live app — WITHOUT touching .env, vendor/,
#      storage/ (uploads, logs), bootstrap/cache or public/storage,
#   5. runs database migrations and rebuilds Laravel's caches,
#   6. takes the site out of maintenance mode.
# Every step is logged to ~/promptaid-deploy.log.
#
# Settings: leave blank to auto-detect, or set them to be explicit.
#   APP_DIR    — live Laravel folder (contains artisan + .env),
#                e.g. /home/cpaneluser/promptaid-src/backend
#   PUBLIC_DIR — only if the domain's document root is a COPY of
#                backend/public (e.g. /home/cpaneluser/public_html) rather
#                than pointing straight at backend/public.
#   PHP_BIN    — PHP 8.2+ CLI, e.g. /opt/cpanel/ea-php83/root/usr/bin/php
# =============================================================================
APP_DIR=""
PUBLIC_DIR=""
PHP_BIN=""

set -u
REPO_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
LOG="$HOME/promptaid-deploy.log"
STAMP="$(date +%Y%m%d-%H%M%S)"

log() { echo "[$(date '+%Y-%m-%d %H:%M:%S')] $*" | tee -a "$LOG"; }
fail() { log "FAILED: $*"; log "Nothing else was changed after this point."; exit 1; }

log "================ Deploy $STAMP ================"
log "Repository: $REPO_DIR ($(cd "$REPO_DIR" && git log -1 --format='%h %s' 2>/dev/null))"

[ -f "$REPO_DIR/backend/artisan" ] || fail "backend/artisan not found in the repository clone."

# --- 1. Find the live app -----------------------------------------------------
is_app() { [ -f "$1/artisan" ] && [ -f "$1/.env" ] && [ -d "$1/vendor" ]; }

if [ -z "$APP_DIR" ]; then
  for c in "$HOME/promptaid-src/backend" "$HOME/promptaid/backend" "$HOME/prompt-aid/backend" "$HOME/promptaid-src" "$HOME/promptaid"; do
    if is_app "$c"; then APP_DIR="$c"; break; fi
  done
fi
if [ -z "$APP_DIR" ]; then
  # Look for this app (identified by its own files) anywhere up to 4 levels deep.
  mapfile -t found < <(find "$HOME" -maxdepth 4 -type f -name artisan -not -path "$REPO_DIR/*" -not -path "*/repositories/*" 2>/dev/null \
    | xargs -r -n1 dirname | while read -r d; do is_app "$d" && [ -f "$d/app/Support/Permissions.php" ] && echo "$d"; done)
  if [ "${#found[@]}" -eq 1 ]; then
    APP_DIR="${found[0]}"
  else
    log "Could not pick the live app folder automatically. Candidates: ${found[*]:-none}"
    fail "Set APP_DIR at the top of deploy/cpanel-deploy.sh, commit, and deploy again."
  fi
fi
is_app "$APP_DIR" || fail "APP_DIR=$APP_DIR is not a live Laravel app (needs artisan, .env and vendor/)."
log "Live app: $APP_DIR"

if [ -z "$PUBLIC_DIR" ] && [ -f "$HOME/public_html/index.php" ]; then
  REL="${APP_DIR#$HOME/}"
  if grep -q "$REL" "$HOME/public_html/index.php"; then
    PUBLIC_DIR="$HOME/public_html"
  fi
fi
[ -n "$PUBLIC_DIR" ] && log "Separate public folder: $PUBLIC_DIR"

# --- PHP 8.2+ -----------------------------------------------------------------
php_ok() { [ -x "$1" ] && "$1" -r 'exit(PHP_VERSION_ID >= 80200 ? 0 : 1);' 2>/dev/null; }
if [ -z "$PHP_BIN" ]; then
  for c in $(command -v php 2>/dev/null) /usr/local/bin/php /usr/bin/php \
           /opt/cpanel/ea-php84/root/usr/bin/php /opt/cpanel/ea-php83/root/usr/bin/php /opt/cpanel/ea-php82/root/usr/bin/php \
           /usr/local/bin/ea-php84 /usr/local/bin/ea-php83 /usr/local/bin/ea-php82; do
    if php_ok "$c"; then PHP_BIN="$c"; break; fi
  done
fi
php_ok "${PHP_BIN:-/nonexistent}" || fail "No PHP 8.2+ command-line binary found. Set PHP_BIN at the top of deploy/cpanel-deploy.sh."
log "PHP: $PHP_BIN ($("$PHP_BIN" -r 'echo PHP_VERSION;'))"
artisan() { (cd "$APP_DIR" && "$PHP_BIN" artisan "$@" 2>&1 | tee -a "$LOG"; exit "${PIPESTATUS[0]}"); }

# --- 2. Backup ----------------------------------------------------------------
BACKUP_DIR="$HOME/promptaid-backups"
mkdir -p "$BACKUP_DIR"
BACKUP="$BACKUP_DIR/code-before-$STAMP.tar.gz"
tar -czf "$BACKUP" -C "$APP_DIR" --exclude=./vendor --exclude=./storage --exclude=./node_modules --exclude=./public/storage . 2>>"$LOG" \
  || fail "Could not write the backup $BACKUP (disk full?)."
log "Backup of live code (incl. .env, excl. vendor/storage): $BACKUP"
if [ -n "$PUBLIC_DIR" ]; then
  tar -czf "$BACKUP_DIR/public-before-$STAMP.tar.gz" -C "$PUBLIC_DIR" --exclude=./storage . 2>>"$LOG"
  log "Backup of public folder: $BACKUP_DIR/public-before-$STAMP.tar.gz"
fi
# Keep the 10 newest backups of each kind.
ls -1t "$BACKUP_DIR"/code-before-*.tar.gz 2>/dev/null | tail -n +11 | xargs -r rm -f
ls -1t "$BACKUP_DIR"/public-before-*.tar.gz 2>/dev/null | tail -n +11 | xargs -r rm -f

# --- 3. Maintenance mode (always lifted again on exit) --------------------------
artisan down --retry=30 >/dev/null || log "(could not enter maintenance mode — continuing)"
trap 'artisan up >/dev/null; log "Site is back up."' EXIT

# --- 4. Copy code ---------------------------------------------------------------
EXCLUDES=(.env '.env.*' vendor/ node_modules/ storage/ bootstrap/cache/ public/storage public/build/ public/hot database/database.sqlite '*.sqlite')
if command -v rsync >/dev/null 2>&1; then
  args=(); for e in "${EXCLUDES[@]}"; do args+=(--exclude="$e"); done
  # No --delete: files that exist only on the server are left alone.
  rsync -a "${args[@]}" "$REPO_DIR/backend/" "$APP_DIR/" >>"$LOG" 2>&1 || fail "Copying files with rsync failed."
else
  (cd "$REPO_DIR/backend" && tar -cf - --exclude=./.env --exclude='./.env.*' --exclude=./vendor --exclude=./node_modules \
     --exclude=./storage --exclude=./bootstrap/cache --exclude=./public/storage --exclude=./public/build --exclude='*.sqlite' .) \
     | (cd "$APP_DIR" && tar -xf -) 2>>"$LOG" || fail "Copying files with tar failed."
fi
log "Code copied into $APP_DIR"

# Version stamp shown on Staff → System → System health.
mkdir -p "$APP_DIR/storage/app" 2>/dev/null
( cd "$REPO_DIR" && git log -1 --format='Commit %h (%ad) %s' --date=format:'%Y-%m-%d %H:%M' 2>/dev/null || echo "Commit unknown" ) > "$APP_DIR/storage/app/deployed.txt" 2>/dev/null \
  && echo "Deployed $(date '+%Y-%m-%d %H:%M %Z')" >> "$APP_DIR/storage/app/deployed.txt" \
  || log "(could not write storage/app/deployed.txt — continuing)"

if [ -n "$PUBLIC_DIR" ]; then
  # Refresh static assets in the separate public folder; never its index.php/.htaccess.
  if command -v rsync >/dev/null 2>&1; then
    rsync -a --exclude=index.php --exclude=.htaccess --exclude=storage --exclude=build/ "$REPO_DIR/backend/public/" "$PUBLIC_DIR/" >>"$LOG" 2>&1
  else
    (cd "$REPO_DIR/backend/public" && tar -cf - --exclude=./index.php --exclude=./.htaccess --exclude=./storage --exclude=./build .) | (cd "$PUBLIC_DIR" && tar -xf -)
  fi
  log "Public assets copied into $PUBLIC_DIR"
fi

# --- 5. Database + caches -------------------------------------------------------
artisan migrate --force || fail "Database migration failed (see above). Restore the code from $BACKUP if the site misbehaves."
artisan optimize:clear >/dev/null || true
artisan config:cache || fail "config:cache failed."
artisan route:cache || log "(route:cache failed — routes left uncached, the site still works)"
artisan view:cache || log "(view:cache failed — views compile on first visit instead)"

log "Deploy finished OK."

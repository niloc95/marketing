#!/usr/bin/env bash
# dev-loaddb.sh: the load test database, next to the one dev DB.
# -----------------------------------------------------------------------------
# Still one checkout and one dev server on :8095. This keeps a second
# database, webscheduler_loadtest, and swaps which one the server uses by
# rewriting database.default.database in .env and restarting the server.
# Only one is live at a time. Always switch back (load:off) when done.
#
#   npm run load:copy     copy the dev DB into webscheduler_loadtest (drops the old copy)
#   npm run load:on       point :8095 at webscheduler_loadtest
#   npm run load:off      point :8095 back at webscheduler_directory
#   npm run load:status   which DB is live, and row counts in both
#
# Copy and counts run in the app (php spark load:db), so no credentials are
# read here. Seed and check with: php spark load:seed, php spark load:check
set -euo pipefail

REPO="$(cd "$(dirname "$0")/.." && pwd)"
ENV_FILE="$REPO/.env"
DEV_DB="webscheduler_directory"
LOAD_DB="webscheduler_loadtest"
KEY='database\.default\.database'

live_db() { grep -E "^[[:space:]]*${KEY}[[:space:]]*=" "$ENV_FILE" | tail -1 | sed -E 's/^[^=]*=[[:space:]]*//; s/[[:space:]]+$//'; }

switch_to() {
  local target="$1"
  if [ "$(live_db)" = "$target" ]; then echo "Already on $target."; return; fi
  cp "$ENV_FILE" "$ENV_FILE.bak-loadswitch"
  # Only this one line changes; database.tests.* stays as it is.
  sed -i '' -E "s/^([[:space:]]*${KEY}[[:space:]]*=[[:space:]]*).*/\1${target}/" "$ENV_FILE"
  if [ "$(live_db)" != "$target" ]; then
    cp "$ENV_FILE.bak-loadswitch" "$ENV_FILE"; echo "Could not change .env, restored it." >&2; exit 1
  fi
  (cd "$REPO" && npm run --silent dev:restart)
  echo "Live DB is now $(live_db)."
}

cd "$REPO"
case "${1:-status}" in
  copy)   php spark load:db copy ;;
  on)
    php spark load:db status | grep -q "${LOAD_DB} .*profiles" || { echo "${LOAD_DB} does not exist. Run npm run load:copy first." >&2; exit 1; }
    switch_to "$LOAD_DB" ;;
  off)    switch_to "$DEV_DB" ;;
  status) php spark load:db status ;;
  *) echo "Usage: $0 copy|on|off|status" >&2; exit 1 ;;
esac

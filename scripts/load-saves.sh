#!/usr/bin/env bash
# load-saves.sh: many owners saving at the same moment, on the load test DB.
# -----------------------------------------------------------------------------
# WORKERS processes (default 12), each on its own business (profiles
# PROFILE_BASE+1 ..), add a service and save working hours over and over for
# SECS (default 20), all starting together. Every save must succeed: a
# failure is a deadlock or a rollback an owner would see as an error page.
#
#   npm run load:on && scripts/load-saves.sh
#   WORKERS=24 SECS=30 scripts/load-saves.sh
set -euo pipefail
cd "$(dirname "$0")/.."

WORKERS="${WORKERS:-12}"
SECS="${SECS:-20}"
base="${PROFILE_BASE:-100}"
at="$(php -r 'printf("%.3f", microtime(true) + 4);')"
out="$(mktemp)"
for i in $(seq 1 "$WORKERS"); do
  php spark load:race --profile $((base + i)) --saves --at "$at" --seconds "$SECS" 2>&1 | grep '^{' >> "$out" &
done
wait
saves=$(sed -E 's/.*"saves":([0-9]+).*/\1/' "$out" | paste -sd+ - | bc)
failed=$(sed -E 's/.*"failed":([0-9]+).*/\1/' "$out" | paste -sd+ - | bc)
echo "$WORKERS businesses for ${SECS}s: $saves saves, $failed failed"
grep -v '"failed":0' "$out" | sed 's/^/  /' || true
rm -f "$out"
[ "$failed" = 0 ]

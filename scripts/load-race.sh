#!/usr/bin/env bash
# load-race.sh: the double booking race on the load test DB.
# -----------------------------------------------------------------------------
# RACERS processes (default 20), each with its own DB connection, book the
# same free time for the same person at the same instant, half online and
# half as reception or the owner. Exactly one may win; the script checks
# that in the database too. ROUNDS rounds (default 5) on PROFILES (default
# 1..ROUNDS).
#
#   npm run load:on && scripts/load-race.sh
#   RACERS=40 ROUNDS=10 scripts/load-race.sh
set -euo pipefail
cd "$(dirname "$0")/.."

RACERS="${RACERS:-20}"
ROUNDS="${ROUNDS:-5}"
fail=0

for round in $(seq 1 "$ROUNDS"); do
  profile="${PROFILE_BASE:-0}"; profile=$((profile + round))
  slot="$(php spark load:race --profile "$profile" 2>/dev/null | tail -1)"
  if [ -z "$slot" ]; then echo "Round $round: profile $profile has no free time or is not built."; fail=1; continue; fi
  at="$(php -r 'printf("%.3f", microtime(true) + 3);')"
  out="$(mktemp)"
  for i in $(seq 0 $((RACERS - 1))); do
    php spark load:race --profile "$profile" --racer "$i" --at "$at" --slot "$slot" 2>&1 | grep '^{' >> "$out" &
  done
  wait
  won=$(grep -c '"won":true' "$out" || true)
  lost=$(grep -c '"won":false' "$out" || true)
  rows=$(php spark load:race --profile "$profile" --count --slot "$slot" 2>/dev/null | tail -1)
  winner=$(grep '"won":true' "$out" | sed -E 's/.*"path":"([a-z]+)".*/\1/' | tr '\n' ' ')
  reasons=$(grep '"won":false' "$out" | sed -E 's/.*"result":"(.*)","late_ms.*/\1/' | sort | uniq -c | sed 's/^ */    /')
  late=$(sed -E 's/.*"late_ms":([0-9-]+).*/\1/' "$out" | sort -n | tail -1)
  status=PASS; { [ "$won" = 1 ] && [ "$rows" = 1 ]; } || { status=FAIL; fail=1; }
  echo "$status round $round, profile $profile, slot $slot UTC: $won won ($winner), $lost refused, $rows live row(s) in the DB, slowest racer ${late} ms after the gun"
  echo "$reasons"
  rm -f "$out"
done
exit $fail

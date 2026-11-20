#!/usr/bin/env bash
# load-flood.sh: many people booking at once, through the real booking code,
# on the load test DB.
# -----------------------------------------------------------------------------
# WORKERS processes (default 16), PER_PROFILE (default 2) on each business
# (profiles PROFILE_BASE+1 ..), book without pause for SECS (default 30),
# all starting together: a fifth online, the rest by reception and the owner.
# Prints bookings a second and booking times. Then run php spark load:check:
# nothing booked under this pressure may be mixed up or doubled.
#
# The dev server (php -S) handles one request at a time, so concurrency is
# measured here, at the services and the database, not over HTTP.
#
#   npm run load:on && scripts/load-flood.sh && php spark load:check
#   WORKERS=32 SECS=60 scripts/load-flood.sh
set -euo pipefail
cd "$(dirname "$0")/.."

WORKERS="${WORKERS:-16}"
PER="${PER_PROFILE:-2}"
SECS="${SECS:-30}"
base="${PROFILE_BASE:-200}"
at="$(php -r 'printf("%.3f", microtime(true) + 5);')"
out="$(mktemp)"
for i in $(seq 0 $((WORKERS - 1))); do
  php spark load:race --profile $((base + 1 + i / PER)) --racer "$i" --flood --at "$at" --seconds "$SECS" 2>&1 | grep '^{' >> "$out" &
done
wait
php -r '
$rows = array_map(fn ($l) => json_decode($l, true), file($argv[1], FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
$secs = (float) $argv[2];
$sum = fn ($k) => array_sum(array_column($rows, $k));
printf("%d workers on %d businesses for %ds: %d booked (%.1f a second), %d lost a time to another worker, %d failed\n",
    count($rows), count(array_unique(array_column($rows, "profile"))), $secs, $sum("booked"), $sum("booked") / $secs, $sum("lost"), $sum("failed"));
foreach (["public", "reception", "owner"] as $p) {
    $n = 0; $med = []; $p95 = []; $max = 0;
    foreach ($rows as $r) { $s = $r["paths"][$p]; if ($s["n"] > 0) { $n += $s["n"]; $med[] = $s["ms"][0]; $p95[] = $s["ms"][1]; $max = max($max, $s["ms"][2]); } }
    sort($med); sort($p95);
    printf("  %-9s %6d booked, median %3d ms, 95%% under %3d ms (worst worker), slowest %4d ms\n", $p, $n, $med[intdiv(count($med), 2)] ?? 0, end($p95) ?: 0, $max);
}
foreach ($rows as $r) { if ($r["failed"] > 0) { echo "  FAILED ", json_encode($r["errors"]), "\n"; } }
exit($sum("failed") > 0 ? 1 : 0);
' "$out" "$SECS"
rc=$?
rm -f "$out"
exit $rc

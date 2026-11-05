#!/usr/bin/env bash
# Load test for online bookings (Phase 10), with ApacheBench.
#
#   scripts/bookings-load-test.sh                 local dev (http://localhost:8095)
#   BASE=https://127.0.0.1 HOST=local.webscheduler.co.za scripts/bookings-load-test.sh
#                                                 on the server, at release, with the owner's OK
#
# Needs SLUG (a profile that takes bookings) and SERVICE (one of its service
# ids); API_KEY (a key with catalogue.read, availability.read and
# appointments.read) adds the API. BOT_TOKEN (directory.verifiedBotToken)
# passes ScrapeGuard on /directory pages; read it into the variable, never
# print it. N requests at concurrency C (default 300 at 10).
#
# Read "Requests per second" and "Non-2xx responses"; ab's "Failed requests"
# are length mismatches on dynamic pages, not errors.
set -euo pipefail

BASE="${BASE:-http://localhost:8095}"
N="${N:-300}"
C="${C:-10}"
: "${SLUG:?Set SLUG to a profile that takes bookings}"
: "${SERVICE:?Set SERVICE to one of its service ids}"
DATE="${DATE:-$(date -v+3d +%F 2>/dev/null || date -d '+3 days' +%F)}"

headers=(-H "Accept-Encoding: gzip")
[[ -n "${HOST:-}" ]] && headers+=(-H "Host: ${HOST}")
[[ -n "${BOT_TOKEN:-}" ]] && headers+=(-H "X-WS-Verified-Bot: ${BOT_TOKEN}")

run() {
    local name="$1"; shift
    local out
    out=$(ab -k -q -n "$N" -c "$C" "${headers[@]}" "$@" 2>&1) || true
    printf '%-28s %8s req/s  median %6s ms  non-2xx %s\n' "$name" \
        "$(awk '/Requests per second/ {print $4}' <<<"$out")" \
        "$(awk '/^Total:/ {print $5}' <<<"$out")" \
        "$(awk '/Non-2xx responses/ {print $3}' <<<"$out" | grep . || echo 0)"
}

echo "Online bookings load test: $BASE, $N requests at $C concurrent, day $DATE"
run "profile page"           "$BASE/directory/$SLUG"
run "booking page"           "$BASE/directory/$SLUG/book?service=$SERVICE"
run "free days (JSON)"       "$BASE/directory/$SLUG/book/days?service=$SERVICE"
run "free times (JSON)"      "$BASE/directory/$SLUG/book/slots?service=$SERVICE&date=$DATE"
if [[ -n "${API_KEY:-}" ]]; then
    auth=(-H "Authorization: Bearer ${API_KEY}")
    # The API allows 120 requests a minute per key, so keep N at or under 120 here.
    N=$(( N > 110 ? 110 : N ))
    run "API availability"   "${auth[@]}" "$BASE/api/v1/availability?service_id=$SERVICE&date=$DATE"
fi

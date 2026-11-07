#!/usr/bin/env bash
# Monthly restore drill: can the newest nightly dump and files archive bring
# the site back? Restores the dump into a scratch database (name must end in
# _tests), compares every table with the live one by row count, lists the
# files archive, then drops the scratch database.
#
#   scripts/restore-drill.sh [dump.sql.gz] [files.tar.gz]
#   (default: the newest dump and files-latest.tar.gz in $DUMPS)
#
# MYCNF is a mysql option file for a user that can read LIVE_DB and create and
# drop DRILL_DB. Rows written since the 02:15 dump are expected, so a table
# passes when it holds at least 90% of today's rows (all rows below 100).
# One OK or FAIL line per run in $LOG, in backup-offsite.sh's format, so
# `php spark backups:check --log <LOG> --max-age 768` can mail a failure.
set -euo pipefail

DUMPS="${DUMPS:-/home/deploy/backups}"
MYCNF="${MYCNF:-/home/deploy/.my.cnf}"
LIVE_DB="${LIVE_DB:-webscheduler_directory}"
DRILL_DB="${DRILL_DB:-webscheduler_restore_drill_tests}"
LOG="${LOG:-$DUMPS/restore-drill.log}"
DUMP="${1:-$(ls -1t "$DUMPS"/db-*.sql.gz 2>/dev/null | head -n 1 || true)}"
FILES="${2:-$DUMPS/files-latest.tar.gz}"

fail() { echo "$(date -u +%Y-%m-%dT%H:%M:%S+00:00) FAIL $*" >> "$LOG"; echo "restore drill failed: $*" >&2; exit 1; }
sql() { mysql --defaults-file="$MYCNF" -N -B "$@"; }

[[ "$DRILL_DB" == *_tests ]] || fail "refusing: $DRILL_DB does not end in _tests"
[[ -s "$DUMP" ]] || fail "no dump in $DUMPS"
gzip -t "$DUMP" || fail "$DUMP is not a whole gzip file"

started=$SECONDS
sql -e "DROP DATABASE IF EXISTS \`$DRILL_DB\`; CREATE DATABASE \`$DRILL_DB\` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;" \
    || fail "cannot create $DRILL_DB"
trap 'sql -e "DROP DATABASE IF EXISTS \`$DRILL_DB\`;" || true' EXIT
gunzip -c "$DUMP" | mysql --defaults-file="$MYCNF" --default-character-set=utf8mb4 "$DRILL_DB" \
    || fail "restore of $(basename "$DUMP")"
seconds=$((SECONDS - started))

tables=0 short=()
while read -r table; do
    tables=$((tables + 1))
    live=$(sql -e "SELECT COUNT(*) FROM \`$LIVE_DB\`.\`$table\`")
    if ! got=$(sql -e "SELECT COUNT(*) FROM \`$DRILL_DB\`.\`$table\`" 2>/dev/null); then
        short+=("$table missing")
    elif (( live < 100 ? got < live : got * 10 < live * 9 )); then
        short+=("$table $got of $live")
    fi
done < <(sql -e "SELECT table_name FROM information_schema.tables WHERE table_schema = '$LIVE_DB' AND table_type = 'BASE TABLE'")
(( ${#short[@]} == 0 )) || fail "restored tables short: ${short[*]}"

[[ -s "$FILES" ]] || fail "no files archive at $FILES"
files=$(tar -tzf "$FILES" | wc -l | tr -d " ") || fail "files archive $(basename "$FILES") does not list"
(( files > 0 )) || fail "files archive $(basename "$FILES") is empty"

echo "restored $tables tables from $(basename "$DUMP") in ${seconds}s, all within range; files archive: $files entries"
echo "$(date -u +%Y-%m-%dT%H:%M:%S+00:00) OK db $(stat -c %s "$DUMP" 2>/dev/null || stat -f %z "$DUMP") files $files tables $tables seconds $seconds" >> "$LOG"

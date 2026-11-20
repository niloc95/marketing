#!/usr/bin/env bash
# cron-job.sh: run one scheduled job, record how it ended, and alert when it fails.
# -----------------------------------------------------------------------------
# Every line in deploy/cron/webscheduler goes through this (system watch,
# 20 Nov 2026). Cron's own mail is off (MAILTO=""), so before this a failing
# job was silent. Now:
#   writable/watch/job-NAME.json   when it last ended, exit code, seconds
#   writable/watch/job-NAME.out    its output from that run (last 200 lines)
#   a failure runs `php spark system:watch --job-failed NAME`, which emails
#   the admin (at most once an hour per job); the morning summary lists jobs
#   whose last run failed.
#
#   cron-job.sh NAME COMMAND [ARGS...]
#   cron-job.sh mysqldump bash -c 'set -o pipefail; mysqldump ... | gzip > ...'
#
# APP (default /var/www/listing/directory-app) is where spark and writable/ are.
set -uo pipefail

name="${1:?job name}"; shift
[ $# -gt 0 ] || { echo "cron-job.sh: no command for $name" >&2; exit 2; }
app="${APP:-/var/www/listing/directory-app}"
dir="$app/writable/watch"
mkdir -p "$dir"

start=$(date +%s)
"$@" > "$dir/job-$name.tmp" 2>&1
rc=$?
end=$(date +%s)
tail -n 200 "$dir/job-$name.tmp" > "$dir/job-$name.out" && rm -f "$dir/job-$name.tmp"
printf '{"at":%d,"exit":%d,"seconds":%d}\n' "$end" "$rc" "$((end - start))" > "$dir/job-$name.json"

if [ "$rc" -ne 0 ]; then
  (cd "$app" && /usr/bin/env php spark system:watch --job-failed "$name" --exit "$rc" --quiet) || true
fi
exit "$rc"

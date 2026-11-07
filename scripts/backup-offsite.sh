#!/usr/bin/env bash
# Copies last night's database dump and the uploaded files to S3 (af-south-1),
# so a backup survives losing the Lightsail box. Runs as deploy from cron after
# the 02:15 dump. The IAM user can only upload: it cannot read or delete, and
# the bucket's lifecycle rule expires objects after 30 days.
#
#   BUCKET=... scripts/backup-offsite.sh            (from deploy/cron/webscheduler)
#   DRYRUN=1 BUCKET=... scripts/backup-offsite.sh   (checks, no upload)
#
# Exits non zero on any failure and writes one line per run to $LOG.
set -euo pipefail

BUCKET="${BUCKET:?Set BUCKET}"
APP="${APP:-/var/www/listing/directory-app}"
WEB="${WEB:-/var/www/listing/public_html}"
DUMPS="${DUMPS:-/home/deploy/backups}"
LOG="${LOG:-$DUMPS/offsite.log}"
DAY="$(date +%F)"
DUMP="$DUMPS/db-$DAY.sql.gz"
FILES="$(mktemp --suffix=.tar.gz)"
trap 'rm -f "$FILES"' EXIT

fail() { echo "$(date -Is) FAIL $*" >> "$LOG"; echo "offsite backup failed: $*" >&2; exit 1; }

# A dump that stopped half way still gzips cleanly; mysqldump's last line proves it finished.
[[ -s "$DUMP" ]] || fail "no dump at $DUMP"
zcat "$DUMP" | tail -n 1 | grep -q '^-- Dump completed' || fail "dump $DUMP is incomplete"

# Uploads: public images and the private documents (verification, careers CVs, menus).
tar -czf "$FILES" -C / \
    "${WEB#/}/assets/listings" \
    "${WEB#/}/assets/hero/uploads" \
    "${APP#/}/writable/verification" \
    "${APP#/}/writable/careers" \
    "${APP#/}/writable/menus" \
    "${APP#/}/writable/uploads" || fail "tar of uploads"

if [[ -n "${DRYRUN:-}" ]]; then
    echo "dry run: would upload $DUMP ($(stat -c %s "$DUMP") bytes) and files ($(stat -c %s "$FILES") bytes) to s3://$BUCKET"
    exit 0
fi

aws s3 cp --only-show-errors "$DUMP" "s3://$BUCKET/db/db-$DAY.sql.gz" || fail "upload of $DUMP"
aws s3 cp --only-show-errors "$FILES" "s3://$BUCKET/files/files-$DAY.tar.gz" || fail "upload of files"

echo "$(date -Is) OK db $(stat -c %s "$DUMP") files $(stat -c %s "$FILES")" >> "$LOG"

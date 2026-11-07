<?php

namespace App\Libraries;

/**
 * Did last night's offsite backup reach S3?
 *
 * scripts/backup-offsite.sh writes one line per run to its log, "<ISO time> OK
 * db <bytes> files <bytes>" or "<ISO time> FAIL <reason>". The script itself
 * cannot report a run that never started (cron line missing, script not
 * executable, box down at 02:40), so this reads the log afterwards and
 * treats silence as a failure too.
 */
class OffsiteBackupCheck
{
    /** A night's run plus slack: the script runs at 02:40, the check at 03:10. */
    public const MAX_AGE_HOURS = 26;

    /** A dump smaller than this is a broken dump, whatever the log says. */
    public const MIN_DB_BYTES = 100_000;

    /** Null when the last run is a recent OK, else the reason, for the email. */
    public function problem(string $logPath, ?int $now = null): ?string
    {
        $now ??= time();
        if (! is_file($logPath) || ! is_readable($logPath)) {
            return 'no backup log at ' . $logPath . ' (the backup has never run, or cannot be read)';
        }

        $lines = file($logPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        $last  = trim((string) end($lines));
        if ($last === '') {
            return 'the backup log is empty';
        }

        $parts = preg_split('/\s+/', $last, 3);
        $at    = strtotime($parts[0] ?? '');
        if ($at === false) {
            return 'last log line is not readable: ' . Mailer::oneLine($last);
        }
        if (($parts[1] ?? '') !== 'OK') {
            return 'last run failed on ' . gmdate('j M H:i', $at) . ' UTC: ' . Mailer::oneLine($parts[2] ?? '');
        }
        if ($now - $at > self::MAX_AGE_HOURS * 3600) {
            return 'no backup since ' . gmdate('j M H:i', $at) . ' UTC';
        }
        if (preg_match('/\bdb (\d+)\b/', $parts[2] ?? '', $m) !== 1 || (int) $m[1] < self::MIN_DB_BYTES) {
            return 'last dump was only ' . ($m[1] ?? '?') . ' bytes';
        }

        return null;
    }
}

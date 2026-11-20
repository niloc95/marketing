<?php

namespace App\Libraries;

/**
 * When a scheduled job last finished a run (system watch, 20 Nov 2026).
 *
 * A file per job in writable/watch/, written by the job itself at the end of
 * a run, so a job that stopped (cron gone, stuck on a lock, crashing before
 * the end) shows as a stale time in /health and the system watch. A file,
 * not the cache: the cache being unwritable is one of the faults we watch.
 */
final class Heartbeat
{
    public static function beat(string $job): void
    {
        $dir = self::dir();
        if (! is_dir($dir)) {
            @mkdir($dir, 02775, true);
        }
        @file_put_contents($dir . self::file($job), (string) time(), LOCK_EX);
    }

    /** Unix time of the last finished run, or null when it never ran here. */
    public static function last(string $job): ?int
    {
        $raw = @file_get_contents(self::dir() . self::file($job));

        return is_string($raw) && ctype_digit(trim($raw)) ? (int) trim($raw) : null;
    }

    public static function forget(string $job): void
    {
        @unlink(self::dir() . self::file($job));
    }

    public static function dir(): string
    {
        return WRITEPATH . 'watch' . DIRECTORY_SEPARATOR;
    }

    private static function file(string $job): string
    {
        return 'beat-' . preg_replace('/[^a-z0-9_-]+/', '-', strtolower($job));
    }
}

<?php

/**
 * Restore drill for online bookings (Phase 10): can a database dump bring
 * every booking table back, whole?
 *
 *   php scripts/bookings-restore-drill.php                    dump the dev DB first, then restore it
 *   php scripts/bookings-restore-drill.php path/to/db.sql.gz  restore a nightly dump (production's format)
 *
 * It restores into the test database only (database.tests.* in .env, whose
 * name must end in _tests, as scripts/reset-test-db.php insists), compares
 * every xs_booking_* table, xs_accounts and xs_business_members against the
 * source by row count and CHECKSUM TABLE, prints how long the restore took,
 * and then resets the test database so the next phpunit run starts clean.
 *
 * Credentials come from .env and go to mysql and mysqldump through a temporary
 * option file (0600), never on the command line.
 *
 * On production (at release, with the owner's OK): copy this file to the
 * server and run it against a scratch database the same way, with the newest
 * /home/deploy/backups/db-YYYY-MM-DD.sql.gz. See docs/bookings/launch.md.
 */

$root = dirname(__DIR__);
$env  = [];
foreach (file($root . '/.env') ?: [] as $line) {
    if (preg_match('/^\s*([A-Za-z0-9_.]+)\s*=\s*(.*)$/', $line, $m)) {
        $env[$m[1]] = trim(trim($m[2]), "\"'");
    }
}
$cfg = static fn (string $group): array => [
    'host' => $env["database.{$group}.hostname"] ?? 'localhost',
    'user' => $env["database.{$group}.username"] ?? '',
    'pass' => $env["database.{$group}.password"] ?? '',
    'name' => $env["database.{$group}.database"] ?? '',
    'port' => (int) ($env["database.{$group}.port"] ?? 3306),
];
$src  = $cfg('default');
$dst  = $cfg('tests');
$dump = $argv[1] ?? null;

if (! str_ends_with($dst['name'], '_tests')) {
    fwrite(STDERR, "Refusing: '{$dst['name']}' is not a test database.\n");
    exit(1);
}

$optionFile = static function (array $c): string {
    $file = tempnam(sys_get_temp_dir(), 'drill');
    chmod($file, 0600);
    file_put_contents($file, "[client]\nhost={$c['host']}\nport={$c['port']}\nuser={$c['user']}\npassword=\"" . addcslashes($c['pass'], '"\\') . "\"\n");

    return $file;
};
$run = static function (string $cmd): void {
    passthru($cmd, $code);
    if ($code !== 0) {
        fwrite(STDERR, "Failed: {$code}\n");
        exit($code);
    }
};

$srcOpts = $optionFile($src);
$dstOpts = $optionFile($dst);
register_shutdown_function(static function () use ($srcOpts, $dstOpts): void {
    @unlink($srcOpts);
    @unlink($dstOpts);
});

if ($dump === null) {
    $dump = sys_get_temp_dir() . '/bookings-drill-' . date('Ymd-His') . '.sql.gz';
    echo "Dumping {$src['name']} as the nightly job does ...\n";
    $run(sprintf('mysqldump --defaults-extra-file=%s --single-transaction --default-character-set=utf8mb4 --routines --triggers --no-tablespaces %s | gzip > %s',
        escapeshellarg($srcOpts), escapeshellarg($src['name']), escapeshellarg($dump)));
}
if (! is_file($dump)) {
    fwrite(STDERR, "No dump at {$dump}\n");
    exit(1);
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$admin = new mysqli($dst['host'], $dst['user'], $dst['pass'], '', $dst['port']);
$admin->query("DROP DATABASE IF EXISTS `{$dst['name']}`");
$admin->query("CREATE DATABASE `{$dst['name']}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

echo 'Restoring ' . basename($dump) . " into {$dst['name']} ...\n";
$started = microtime(true);
$run(sprintf('%s %s | mysql --defaults-extra-file=%s --default-character-set=utf8mb4 %s',
    str_ends_with($dump, '.gz') ? 'gunzip -c' : 'cat', escapeshellarg($dump), escapeshellarg($dstOpts), escapeshellarg($dst['name'])));
$seconds = microtime(true) - $started;

$restored = new mysqli($dst['host'], $dst['user'], $dst['pass'], $dst['name'], $dst['port']);
$tables   = array_column($restored->query("SHOW TABLES WHERE Tables_in_{$dst['name']} LIKE 'xs\\_booking\\_%' OR Tables_in_{$dst['name']} IN ('xs_accounts', 'xs_business_members')")->fetch_all(), 0);
$source   = $argc > 1 ? null : new mysqli($src['host'], $src['user'], $src['pass'], $src['name'], $src['port']);
$failed   = 0;
printf("\n%-40s %10s %14s %s\n", 'table', 'rows', 'checksum', $source ? 'source' : '');
foreach ($tables as $t) {
    $rows = (int) $restored->query("SELECT COUNT(*) FROM `{$t}`")->fetch_row()[0];
    $sum  = (string) $restored->query("CHECKSUM TABLE `{$t}`")->fetch_row()[1];
    $note = '';
    if ($source) {
        $srcRows = (int) $source->query("SELECT COUNT(*) FROM `{$t}`")->fetch_row()[0];
        $srcSum  = (string) $source->query("CHECKSUM TABLE `{$t}`")->fetch_row()[1];
        $ok      = $srcRows === $rows && $srcSum === $sum;
        $failed += $ok ? 0 : 1;
        $note    = $ok ? 'same' : "DIFFERENT ({$srcRows} rows, {$srcSum})";
    }
    printf("%-40s %10d %14s %s\n", $t, $rows, $sum, $note);
}
printf("\n%d booking tables restored in %.1f s%s.\n", count($tables), $seconds, $source ? ($failed === 0 ? ', all the same as the source' : ", {$failed} DIFFERENT") : '');
if (count($tables) < 15) {
    echo "Fewer booking tables than expected: is the dump from before the bookings migrations?\n";
    $failed++;
}

$admin->query("DROP DATABASE IF EXISTS `{$dst['name']}`");
$admin->query("CREATE DATABASE `{$dst['name']}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
echo "Reset {$dst['name']} for the next phpunit run.\n";

exit($failed === 0 ? 0 : 1);

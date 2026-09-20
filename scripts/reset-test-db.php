<?php

/**
 * Drop and recreate the PHPUnit test database.
 *
 * The test schema is disposable by design — DatabaseTestTrait migrates it up and
 * back down around every test — so this is the same operation the suite already
 * performs, just from the outside. It is the repair for the one state the suite
 * cannot fix itself: if two PHPUnit processes ever run at once, or a run is
 * killed mid-migration, the xs_migrations ledger and the actual schema disagree.
 * The runner then cannot go up (the table already exists) or down (the column
 * was never renamed), and every database test errors in setUp until the schema
 * is thrown away.
 *
 * Usage:  php scripts/reset-test-db.php
 *
 * Credentials come from .env's database.tests.* group, never from arguments, so
 * no password reaches the shell history, a permission rule or a CI log.
 *
 * The guard below is the point of the whole file: this refuses to run against
 * any database whose name is not the configured test database AND does not end
 * in _tests. A typo in .env therefore cannot drop the development database —
 * which sits on the same server, under the same user, one line away.
 */

$envPath = dirname(__DIR__) . '/.env';
if (! is_file($envPath)) {
    fwrite(STDERR, "No .env at {$envPath}\n");
    exit(1);
}

// parse_ini_file() chokes on this file — a comment in it contains parentheses —
// so the four keys are read directly.
$env = [];
foreach (file($envPath) as $line) {
    if (preg_match('/^\s*([A-Za-z0-9_.]+)\s*=\s*(.*)$/', $line, $m)) {
        $env[$m[1]] = trim(trim($m[2]), "\"'");
    }
}

$host = $env['database.tests.hostname'] ?? '';
$user = $env['database.tests.username'] ?? '';
$pass = $env['database.tests.password'] ?? '';
$name = $env['database.tests.database'] ?? '';
$port = (int) ($env['database.tests.port'] ?? 3306);

if ($name === '') {
    fwrite(STDERR, "database.tests.database is not set in .env — refusing.\n");
    exit(1);
}

// Belt and braces. The suffix check is what makes a mistyped .env harmless:
// the development database is webscheduler_directory, and it does not end _tests.
if (! str_ends_with($name, '_tests')) {
    fwrite(STDERR, "Refusing: '{$name}' is not a test database (its name must end in _tests).\n");
    exit(1);
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

// Connected with no database selected, so a failure here cannot touch one.
$db = new mysqli($host, $user, $pass, '', $port);

$db->query("DROP DATABASE IF EXISTS `{$name}`");
$db->query("CREATE DATABASE `{$name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

echo "Reset {$name}. The next phpunit run will migrate it from scratch.\n";
echo "Run only one phpunit process at a time — concurrent runs are what corrupts it.\n";

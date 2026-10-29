<?php

namespace Tests\Support;

use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\MigrationRunner;
use RuntimeException;

/**
 * The test database's schema, built once per PHPUnit run, and the cheap reset
 * that stands in for rebuilding it before every test.
 *
 * Until Oct 2026 every DB test class set $refresh = true and $migrateOnce =
 * false, so CodeIgniter regressed all ~70 migrations and ran them again before
 * every single test: ~140 schema changes, each fsynced twice on this machine's
 * MySQL, times ~1,600 tests. A full suite took 20 to 40+ minutes. Now the
 * schema is built once and each test starts from empty tables, which is the
 * same state: no migration seeds rows into an empty database (the two that
 * insert only backfill from rows that already exist).
 *
 * AUTO_INCREMENT is not reset between tests. A test must use the id an insert
 * returns, never assume 1.
 */
final class TestSchema
{
    private static bool $built = false;

    /** @var list<string>|null every table but the migrations log */
    private static ?array $tables = null;

    /**
     * Drop everything and migrate to latest, once per process. Rebuilding
     * from nothing (rather than migrating forward) means a branch with other
     * migrations can never leave a stale schema behind.
     */
    public static function build(BaseConnection $db, MigrationRunner $migrations): void
    {
        if (self::$built) {
            return;
        }

        $name = (string) $db->getDatabase();
        if (! str_ends_with($name, '_tests')) {
            throw new RuntimeException("Refusing to rebuild '{$name}': the test database's name must end in _tests.");
        }

        // Test churn is not worth keeping in the binlogs that recover the dev
        // database (they rescued the 15 Oct 2026 data loss). Best effort: it
        // needs a privileged user, which the local root is.
        try {
            $db->query('SET SESSION sql_log_bin = 0');
        } catch (\Throwable) {
        }

        $db->query('SET FOREIGN_KEY_CHECKS = 0');
        foreach (self::baseTables($db) as $table) {
            $db->query('DROP TABLE IF EXISTS `' . $table . '`');
        }
        $db->query('SET FOREIGN_KEY_CHECKS = 1');

        $migrations->setNamespace('App');
        $migrations->latest('tests');

        self::$built  = true;
        self::$tables = null;
    }

    /**
     * Empty every table in one transaction: one commit per test instead of a
     * rebuild. DELETE rather than TRUNCATE, which is DDL and costs a file
     * operation per table.
     */
    public static function reset(BaseConnection $db): void
    {
        self::$tables ??= array_values(array_filter(
            self::baseTables($db),
            static fn (string $t): bool => $t !== $db->prefixTable('migrations')
        ));

        $db->query('SET FOREIGN_KEY_CHECKS = 0');
        $db->query('START TRANSACTION');
        foreach (self::$tables as $table) {
            $db->query('DELETE FROM `' . $table . '`');
        }
        $db->query('COMMIT');
        $db->query('SET FOREIGN_KEY_CHECKS = 1');
    }

    /** @return list<string> the database's own tables, full names */
    private static function baseTables(BaseConnection $db): array
    {
        $rows = $db->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")->getResultArray();

        return array_map(static fn (array $r): string => (string) reset($r), $rows);
    }
}

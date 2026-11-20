<?php

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * The load test database (owner, 20 Nov 2026): a second database next to
 * the one dev DB, filled by load:seed, so the system can be watched under
 * real volume without touching the dev data. Still one checkout and one
 * server on :8095; `npm run load:on|off` swaps which database it uses.
 *
 *   php spark load:db copy     copy the dev DB into webscheduler_loadtest (drops the old copy)
 *   php spark load:db status   which DB is live, and counts in both
 *   php spark load:db top      the costliest queries (--reset to start counting again)
 *
 * The copy goes through the app's own connection (same MySQL server):
 * SHOW CREATE TABLE keeps every index and foreign key, then INSERT ...
 * SELECT copies the rows. No credentials leave .env.
 */
class LoadDb extends BaseCommand
{
    public const DEV_DB  = 'webscheduler_directory';
    public const LOAD_DB = 'webscheduler_loadtest';

    protected $group       = 'Directory';
    protected $name        = 'load:db';
    protected $description = 'Copy the dev DB into the load test DB, or show which is live.';
    protected $usage       = 'load:db copy|status|top [--reset]';

    public function run(array $params): int
    {
        if (ENVIRONMENT === 'production') {
            CLI::error('The load test database is for local dev only.');

            return EXIT_ERROR;
        }

        return match ($params[0] ?? 'status') {
            'copy'   => $this->copy(),
            'status' => $this->status(),
            'top'    => $this->top(),
            default  => $this->usage(),
        };
    }

    private function usage(): int
    {
        CLI::error('Usage: php spark load:db copy|status');

        return EXIT_ERROR;
    }

    private function copy(): int
    {
        $db = db_connect();
        if ($db->getDatabase() !== self::DEV_DB) {
            CLI::error('The live DB is ' . $db->getDatabase() . '. Run npm run load:off first, then copy.');

            return EXIT_ERROR;
        }
        $src = self::DEV_DB;
        $dst = self::LOAD_DB;

        CLI::write("Copying {$src} into a fresh {$dst} ...");
        $db->query("DROP DATABASE IF EXISTS `{$dst}`");
        $db->query("CREATE DATABASE `{$dst}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

        $tables = array_column($db->query(
            "SELECT table_name AS t FROM information_schema.tables WHERE table_schema = ? AND table_type = 'BASE TABLE' ORDER BY table_name",
            [$src],
        )->getResultArray(), 't');

        $db->query('SET FOREIGN_KEY_CHECKS = 0');
        $db->query("USE `{$dst}`");
        try {
            foreach ($tables as $t) {
                $create = $db->query("SHOW CREATE TABLE `{$src}`.`{$t}`")->getRowArray()['Create Table'];
                $db->query($create);
                $db->query("INSERT INTO `{$dst}`.`{$t}` SELECT * FROM `{$src}`.`{$t}`");
                $n = (int) $db->query("SELECT COUNT(*) AS n FROM `{$dst}`.`{$t}`")->getRowArray()['n'];
                CLI::write(sprintf('  %-48s %10d', $t, $n));
            }
        } finally {
            $db->query("USE `{$src}`");
            $db->query('SET FOREIGN_KEY_CHECKS = 1');
        }

        $views = $db->query("SELECT COUNT(*) AS n FROM information_schema.views WHERE table_schema = ?", [$src])->getRowArray();
        if ((int) $views['n'] > 0) {
            CLI::error('The dev DB has ' . $views['n'] . ' views, which were not copied.');
        }

        CLI::write(count($tables) . ' tables copied.', 'green');

        return $this->status();
    }

    /**
     * Where the database time goes: the statements with the most total time
     * since MySQL started (performance_schema), with rows examined per call,
     * so a query that scans the whole table instead of one business shows.
     */
    private function top(): int
    {
        // --reset: start the counts again, to see only what runs from now on.
        if (CLI::getOption('reset') !== null) {
            db_connect()->query('TRUNCATE TABLE performance_schema.events_statements_summary_by_digest');
            CLI::write('Counts reset.');

            return EXIT_SUCCESS;
        }
        $rows = db_connect()->query('SELECT DIGEST_TEXT AS q, COUNT_STAR AS calls, ROUND(SUM_TIMER_WAIT / 1e12, 1) AS total_s,
                ROUND(AVG_TIMER_WAIT / 1e9, 2) AS avg_ms, ROUND(SUM_ROWS_EXAMINED / GREATEST(COUNT_STAR, 1)) AS rows_per_call
            FROM performance_schema.events_statements_summary_by_digest
            WHERE SCHEMA_NAME = ? AND DIGEST_TEXT NOT LIKE \'%performance_schema%\'
            ORDER BY SUM_TIMER_WAIT DESC LIMIT 15', [self::LOAD_DB])->getResultArray();
        foreach ($rows as $r) {
            CLI::write(sprintf('%8.1fs %9d calls %8.2f ms %9d rows/call  %s', $r['total_s'], $r['calls'], $r['avg_ms'], $r['rows_per_call'], mb_substr(preg_replace('/\s+/', ' ', (string) $r['q']), 0, 230)));
        }

        // Does the data fit in memory? Disk reads per logical read, and the size on disk.
        $db   = db_connect();
        $pool = (int) $db->query("SELECT @@innodb_buffer_pool_size AS n")->getRowArray()['n'];
        $st   = array_column($db->query("SHOW GLOBAL STATUS WHERE Variable_name IN ('Innodb_buffer_pool_reads', 'Innodb_buffer_pool_read_requests')")->getResultArray(), 'Value', 'Variable_name');
        $size = (int) $db->query('SELECT SUM(data_length + index_length) AS n FROM information_schema.tables WHERE table_schema = ?', [self::LOAD_DB])->getRowArray()['n'];
        CLI::newLine();
        CLI::write(sprintf('InnoDB buffer pool %d MB, load DB %d MB on disk, %.3f%% of reads went to disk.',
            $pool / 1048576, $size / 1048576, 100 * (int) $st['Innodb_buffer_pool_reads'] / max(1, (int) $st['Innodb_buffer_pool_read_requests'])));

        return EXIT_SUCCESS;
    }

    private function status(): int
    {
        $db = db_connect();
        CLI::write('Live DB: ' . $db->getDatabase());
        foreach ([self::DEV_DB, self::LOAD_DB] as $name) {
            $exists = $db->query('SELECT 1 FROM information_schema.schemata WHERE schema_name = ?', [$name])->getRowArray();
            if ($exists === null) {
                CLI::write(sprintf('  %-24s not there', $name));

                continue;
            }
            $row = $db->query("SELECT
                (SELECT COUNT(*) FROM `{$name}`.xs_directory_listings) AS profiles,
                (SELECT COUNT(*) FROM `{$name}`.xs_directory_listings WHERE signup_source = 'loadtest') AS load_profiles,
                (SELECT COUNT(*) FROM `{$name}`.xs_booking_providers) AS providers,
                (SELECT COUNT(*) FROM `{$name}`.xs_business_members) AS members,
                (SELECT COUNT(*) FROM `{$name}`.xs_booking_appointments) AS appointments,
                (SELECT COUNT(*) FROM information_schema.referential_constraints WHERE constraint_schema = '{$name}') AS fks")->getRowArray();
            CLI::write(sprintf(
                '  %-24s profiles %d (loadtest %d), providers %d, sign ins %d, appointments %d, foreign keys %d',
                $name,
                $row['profiles'],
                $row['load_profiles'],
                $row['providers'],
                $row['members'],
                $row['appointments'],
                $row['fks'],
            ));
        }

        return EXIT_SUCCESS;
    }
}

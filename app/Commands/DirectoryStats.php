<?php

namespace App\Commands;

use App\Services\GoogleDataService;
use App\Services\ListingStatsService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Nightly profile statistics.
 *
 *   php spark directory:stats [--backfill 480] [--no-google] [--quiet]
 *
 * 1. Deletes our own daily totals and Google's older than 25 months, which
 *    the Privacy policy promises ("Profile statistics").
 * 2. Pulls Search Console and GA4 for every day not pulled yet
 *    (GoogleDataService::catchUp()), back Config\Google::$catchUpDays days,
 *    or --backfill days on the first run (Search Console keeps 16 months,
 *    so 480 is the most worth asking for). Does nothing until config Google
 *    is set.
 *
 * Missing a night costs nothing: the next one catches the days up. Use
 * --backfill 480, never --backfill=480 (the CLI quirk GeocodeListings notes).
 */
class DirectoryStats extends BaseCommand
{
    protected $group       = 'Directory';
    protected $name        = 'directory:stats';
    protected $description = 'Delete old profile statistics and pull the latest Search Console and GA4 data.';
    protected $usage       = 'directory:stats [--backfill <days>] [--no-google] [--quiet]';
    protected $options     = [
        '--backfill'  => 'Look this many days back for days never pulled (first run: 480).',
        '--no-google' => 'Only delete old statistics; do not call Google.',
        '--quiet'     => 'Only report problems (for cron).',
    ];

    public function run(array $params)
    {
        $quiet  = array_key_exists('quiet', $params) || in_array('--quiet', $params, true);
        $google = new GoogleDataService();

        $pruned = (new ListingStatsService())->prune() + $google->prune();
        if (! $quiet) {
            CLI::write('Deleted ' . $pruned . ' row(s) of statistics older than ' . ListingStatsService::KEEP_MONTHS . ' months.', 'green');
        }

        if (array_key_exists('no-google', $params) || CLI::getOption('no-google') !== null) {
            return;
        }
        if (! $google->isConfigured()) {
            if (! $quiet) {
                CLI::write('Google is not configured (google.credentialsPath), so nothing was pulled.', 'yellow');
            }

            return;
        }

        $backfill = CLI::getOption('backfill') ?? ($params['backfill'] ?? null);
        $days     = is_numeric($backfill) ? max(1, min(500, (int) $backfill)) : null;

        foreach ($google->catchUp($days) as $pull) {
            $what = $pull['source'] . ' ' . $pull['site'] . ' ' . $pull['day'];
            if ($pull['rows'] === null) {
                CLI::error('Failed: ' . $what . ' (see writable/logs). It is tried again next run.');
            } elseif (! $quiet) {
                CLI::write('Pulled ' . $what . ': ' . $pull['rows'] . ' row(s).');
            }
        }
    }
}

<?php

namespace App\Commands;

use App\Services\ListingStatsService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Nightly housekeeping for profile statistics.
 *
 *   php spark directory:stats
 *
 * Deletes daily totals older than ListingStatsService::KEEP_MONTHS, which the
 * Privacy policy promises ("Profile statistics"). Missing a night costs
 * nothing but a day's delay on a deletion already past its window.
 */
class DirectoryStats extends BaseCommand
{
    protected $group       = 'Directory';
    protected $name        = 'directory:stats';
    protected $description = 'Delete profile statistics older than the retention period.';
    protected $usage       = 'directory:stats [--quiet]';
    protected $options     = ['--quiet' => 'Only report problems (for cron).'];

    public function run(array $params)
    {
        $pruned = (new ListingStatsService())->prune();

        if (! array_key_exists('quiet', $params) && ! in_array('--quiet', $params, true)) {
            CLI::write('Deleted ' . $pruned . ' day(s) of profile statistics older than ' . ListingStatsService::KEEP_MONTHS . ' months.', 'green');
        }
    }
}

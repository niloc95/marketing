<?php

namespace App\Commands;

use App\Services\ReviewService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Nightly housekeeping for customer reviews.
 *
 *   php spark reviews:prune
 *
 * Deletes reviews nobody confirmed within Config\Reviews::$unconfirmedDays,
 * wipes the reviewer's name and email from rejected and hidden reviews after
 * $decidedRetentionDays (POPIA retention; the privacy policy promises both),
 * then sends the admin one digest of the day's review reports.
 *
 * Run it from the same daily cron as jobs:expire.
 */
class ReviewsPrune extends BaseCommand
{
    protected $group       = 'Directory';
    protected $name        = 'reviews:prune';
    protected $description = 'Delete unconfirmed reviews, wipe reviewer details past retention, and send the report digest.';
    protected $usage       = 'reviews:prune [--quiet]';
    protected $options     = ['--quiet' => 'Print nothing (for cron).'];

    public function run(array $params)
    {
        $svc    = new ReviewService();
        $result = $svc->prune();
        $digest = $svc->reportDigest();

        if (! array_key_exists('quiet', $params) && ! in_array('--quiet', $params, true)) {
            CLI::write(sprintf(
                'Deleted %d unconfirmed review(s), wiped reviewer details on %d, digest covered %d report(s).',
                $result['deleted'],
                $result['wiped'],
                $digest
            ));
        }
    }
}

<?php

namespace App\Commands;

use App\Services\JobBoardService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Daily housekeeping for the Jobs board.
 *
 *   php spark jobs:expire
 *
 * Emails renew reminders, moves posts past their closing date to 'expired',
 * deletes posts whose email was never confirmed, and wipes poster contact
 * details a year after a post ended (POPIA retention). Then emails the admin
 * one digest of any "Report this post" clicks not yet sent.
 *
 * Like the badge sweep, missing a run is safe for visitors: the public list
 * and post pages compare valid_through against today themselves, so an
 * expired post stops showing at midnight whether or not this ran.
 */
class JobsExpire extends BaseCommand
{
    protected $group       = 'Directory';
    protected $name        = 'jobs:expire';
    protected $description = 'Expire Jobs board posts, send renew reminders, apply retention, and email the report digest.';
    protected $usage       = 'jobs:expire [--quiet]';
    protected $options     = ['--quiet' => 'Print nothing (for cron).'];

    public function run(array $params)
    {
        $svc      = new JobBoardService();
        $out      = $svc->expireDue();
        $reported = $svc->reportDigest();

        if (! array_key_exists('quiet', $params) && ! in_array('--quiet', $params, true)) {
            CLI::write(sprintf(
                'Reminded %d, expired %d, deleted %d unconfirmed, wiped contact details on %d, sent %d reports in the digest.',
                $out['reminded'],
                $out['expired'],
                $out['purged'],
                $out['wiped'],
                $reported
            ));
        }
    }
}

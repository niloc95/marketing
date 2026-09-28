<?php

namespace App\Commands;

use App\Services\ReferralService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Daily POPIA retention for "Recommend a business".
 *
 *   php spark referrals:prune
 *
 * Wipes the business's and the referrer's contact details from referrals
 * 12 months after submission, or 30 days after an admin dismissed them. The
 * rows stay so the admin counts stay honest. Suppressions are never pruned:
 * they are what keeps a "don't contact me again" honoured.
 *
 * Run it from the same daily cron as jobs:expire.
 */
class ReferralsPrune extends BaseCommand
{
    protected $group       = 'Directory';
    protected $name        = 'referrals:prune';
    protected $description = 'Wipe contact details from referrals past their retention period.';
    protected $usage       = 'referrals:prune [--quiet]';
    protected $options     = ['--quiet' => 'Print nothing (for cron).'];

    public function run(array $params)
    {
        $wiped = (new ReferralService())->prune();

        if (! array_key_exists('quiet', $params) && ! in_array('--quiet', $params, true)) {
            CLI::write(sprintf('Wiped contact details on %d referral(s).', $wiped));
        }
    }
}

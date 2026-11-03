<?php

namespace App\Commands;

use App\Services\PaystackPayments;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Ask Paystack for renewals its webhook did not deliver, and bank them.
 *
 *   php spark directory:paystack:catch-up
 *
 * directory:verifications:sweep already does this every night before it
 * lapses anything; this runs only that step, so it is safe to run by hand on
 * production (it sends no reminders and deletes nothing). Every charge goes
 * through PaystackPayments::confirmReference(), so a charge already banked
 * answers 'duplicate' and running it twice changes nothing.
 */
class PaystackCatchUp extends BaseCommand
{
    protected $group       = 'Directory';
    protected $name        = 'directory:paystack:catch-up';
    protected $description = 'Bank Paystack renewals whose webhook never arrived.';
    protected $usage       = 'directory:paystack:catch-up';

    public function run(array $params)
    {
        $outcomes = (new PaystackPayments())->catchUpRenewals();

        foreach ($outcomes as $outcome) {
            CLI::write($outcome);
        }
        $summary = sprintf('Paystack catch up: %d charge%s checked.', count($outcomes), count($outcomes) === 1 ? '' : 's');
        log_message('info', $summary);
        CLI::write($summary, 'green');

        return EXIT_SUCCESS;
    }
}

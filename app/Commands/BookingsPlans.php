<?php

namespace App\Commands;

use App\Services\Bookings\Plans\BookingPlanService;
use App\Services\PaystackPayments;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Daily for the Bookings plan (Phase 10): remind admins before their free
 * trial ends, and bank renewals whose Paystack webhook never came. Does
 * nothing while the plan is not required.
 *
 *   35 3 * * * php spark bookings:plans --quiet
 */
class BookingsPlans extends BaseCommand
{
    protected $group       = 'Bookings';
    protected $name        = 'bookings:plans';
    protected $description = 'Remind trials that end soon and catch up missed Bookings plan renewals.';
    protected $usage       = 'bookings:plans [--quiet]';
    protected $options     = ['--quiet' => 'Print nothing unless something happened.'];

    public function run(array $params)
    {
        $payments = new PaystackPayments();
        $out      = (new BookingPlanService())->sweep(static fn (string $reference): string => $payments->confirmReference($reference));

        if (CLI::getOption('quiet') === null || $out['reminded'] + count($out['checked']) > 0) {
            CLI::write(sprintf('Trial reminders %d. Renewals checked: %s', $out['reminded'], $out['checked'] === [] ? 'none' : implode('; ', $out['checked'])));
        }
    }
}

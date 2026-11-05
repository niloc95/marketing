<?php

namespace App\Commands;

use App\Services\Bookings\Payments\DepositService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Deposits (Phase 8), every five minutes: asks Paystack about payments left
 * open (a customer who paid and closed the tab before coming back), then
 * frees every time whose deposit was not paid in time. PayFast needs no
 * asking: it sends its notification on its own. Does nothing while
 * bookings.enabled is off; a MySQL lock stops overlapping runs.
 *
 *   php spark bookings:payments [--quiet]
 */
class BookingsPayments extends BaseCommand
{
    protected $group       = 'Bookings';
    protected $name        = 'bookings:payments';
    protected $description = 'Check open deposit payments and free times whose deposit was not paid.';
    protected $usage       = 'bookings:payments [--quiet]';
    protected $options     = ['--quiet' => 'Print nothing unless a time was freed.'];

    public function run(array $params)
    {
        if (! config('Bookings')->enabled()) {
            return;
        }
        $db   = db_connect();
        $lock = $db->query('SELECT GET_LOCK(?, 0) AS got', ['webscheduler_bookings_payments'])->getRow();
        if ((int) ($lock->got ?? 0) !== 1) {
            return;
        }
        try {
            $stats = DepositService::sweep();
        } finally {
            $db->query('SELECT RELEASE_LOCK(?)', ['webscheduler_bookings_payments']);
        }

        if (CLI::getOption('quiet') === null || $stats['released'] > 0) {
            CLI::write(sprintf('Checked %d open Paystack payments, freed %d unpaid times.', $stats['checked'], $stats['released']));
        }
    }
}

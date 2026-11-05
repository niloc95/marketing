<?php

namespace App\Commands;

use App\Services\Bookings\Privacy\Retention;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Nightly POPIA retention for online bookings (decision D12): removes the
 * personal details of customers whose last appointment ended more than
 * Config\Bookings::$retentionMonths ago, and older audit lines.
 *
 *   45 4 * * * php spark bookings:retention --quiet
 */
class BookingsRetention extends BaseCommand
{
    protected $group       = 'Bookings';
    protected $name        = 'bookings:retention';
    protected $description = 'Remove the personal details of booking customers past the retention period.';
    protected $usage       = 'bookings:retention [--quiet]';
    protected $options     = ['--quiet' => 'Print nothing unless something was removed.'];

    public function run(array $params)
    {
        if (! config('Bookings')->enabled()) {
            return;
        }
        $out = (new Retention())->run();
        if (CLI::getOption('quiet') === null || $out['customers'] + $out['audit'] > 0) {
            CLI::write(sprintf('Customers cleared %d, audit lines deleted %d.', $out['customers'], $out['audit']));
        }
    }
}

<?php

namespace App\Commands;

use App\Services\Bookings\Notifications\NotificationDispatcher;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Queue due reminders and send online booking messages, for every business
 * (Bookings' notifications:dispatch-queue, which ran once per install).
 * Cron runs it each minute:
 *
 *   * * * * * php spark bookings:dispatch --quiet
 *
 * A MySQL named lock keeps two runs from overlapping (a slow provider can
 * make one run longer than a minute); the second simply exits. Each run
 * stops starting new businesses after 50 seconds.
 */
class BookingsDispatch extends BaseCommand
{
    protected $group       = 'Bookings';
    protected $name        = 'bookings:dispatch';
    protected $description = 'Queue due booking reminders and send booking messages.';
    protected $usage       = 'bookings:dispatch [--quiet]';
    protected $options     = ['--quiet' => 'Print nothing unless something was sent or failed.'];

    public function run(array $params)
    {
        $db   = db_connect();
        $lock = $db->query('SELECT GET_LOCK(?, 0) AS got', ['webscheduler_bookings_dispatch'])->getRow();
        if ((int) ($lock->got ?? 0) !== 1) {
            if (CLI::getOption('quiet') === null) {
                CLI::write('Another run is still going.');
            }

            return;
        }
        try {
            $stats = (new NotificationDispatcher())->run(500, microtime(true) + 50);
        } finally {
            $db->query('SELECT RELEASE_LOCK(?)', ['webscheduler_bookings_dispatch']);
        }

        $quiet = CLI::getOption('quiet') !== null;
        if (! $quiet || $stats['sent'] + $stats['failed'] > 0) {
            CLI::write(sprintf(
                'Reminders queued %d, sent %d, failed %d, trying again %d, not needed %d.',
                $stats['reminders'], $stats['sent'], $stats['failed'], $stats['retrying'], $stats['cancelled'],
            ));
        }
    }
}

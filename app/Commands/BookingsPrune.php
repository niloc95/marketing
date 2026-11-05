<?php

namespace App\Commands;

use App\Services\Bookings\Notifications\DeliveryLog;
use App\Services\Bookings\Notifications\NotificationCentre;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Nightly clean up of online booking messages (Bookings'
 * notifications:purge-delivery-logs): delivery logs, finished queue rows and
 * read notification centre rows older than Config\BookingNotifications::$logDays.
 * Queued rows are never touched.
 *
 *   php spark bookings:prune [--days 90]
 */
class BookingsPrune extends BaseCommand
{
    protected $group       = 'Bookings';
    protected $name        = 'bookings:prune';
    protected $description = 'Delete old booking delivery logs, finished queue rows and read notices.';
    protected $usage       = 'bookings:prune [--days <n>] [--quiet]';
    protected $options     = ['--days' => 'Keep this many days (default 90).', '--quiet' => 'Print nothing.'];

    public function run(array $params)
    {
        $days   = max(7, (int) (CLI::getOption('days') ?? config('BookingNotifications')->logDays));
        $before = gmdate('Y-m-d H:i:s', time() - $days * 86400);

        $logs = DeliveryLog::purge($before);
        $db   = db_connect();
        $db->table('xs_booking_notification_queue')->whereIn('status', ['sent', 'failed', 'cancelled'])->where('updated_at <', $before)->delete();
        $queue = $db->affectedRows();
        $inbox = NotificationCentre::purge($before);

        if (CLI::getOption('quiet') === null) {
            CLI::write(sprintf('Deleted %d log lines, %d finished messages and %d read notices older than %d days.', $logs, $queue, $inbox, $days));
        }
    }
}

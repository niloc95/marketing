<?php

namespace App\Commands;

use App\Services\Bookings\BookingSettings;
use App\Services\Bookings\BusinessMemberService;
use App\Services\Bookings\Notifications\NotificationDispatcher;
use App\Services\Bookings\Notifications\NotificationRules;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use CodeIgniter\Events\Events;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Booking emails under load (owner, 20 Nov 2026), on the load test DB.
 *
 *   php spark load:mail fire [--flood 20] [--workers 8] [--keep-on]
 *
 * Holds the bookings:dispatch lock throughout, so the dev jobs agent sends
 * nothing on the side. Then:
 *  1. turns booking emails on for every load test practice (the default
 *     customer rules, a reminder 24 hours before, notices to the owner and
 *     both reception sign ins);
 *  2. books for --flood seconds with scripts/load-flood.sh (confirmations,
 *     and team notices for the online ones);
 *  3. runs the real NotificationDispatcher until nothing is due, as cron
 *     would but back to back, timing each run (cron: one run a minute);
 *  4. checks EVERY email sent, not only what Mailpit keeps: to the
 *     appointment's own customer (or a team member of that practice who may
 *     see that person: an admin, or reception assigned to them), naming the
 *     practice, the person, the service and the local time;
 *  5. turns the emails off again unless --keep-on (else the jobs agent
 *     would keep sending reminders into Mailpit every minute).
 *
 * Mail goes to Mailpit (localhost:1025) on dev; nothing leaves the machine.
 *
 *   php spark load:mail off    emails off again on every load test practice
 */
class LoadMail extends BaseCommand
{
    protected $group       = 'Directory';
    protected $name        = 'load:mail';
    protected $description = 'Fire booking emails on the load test DB and check each one.';
    protected $usage       = 'load:mail fire|off [--flood 20] [--workers 8] [--keep-on]';

    private const LOCK = 'webscheduler_bookings_dispatch';

    public function run(array $params): int
    {
        $db = db_connect();
        if (ENVIRONMENT === 'production' || $db->getDatabase() !== LoadDb::LOAD_DB) {
            CLI::error('Only on the load test DB (npm run load:on).');

            return EXIT_ERROR;
        }
        if (! str_contains((string) config('Email')->SMTPHost, 'localhost') && (string) config('Email')->SMTPHost !== '127.0.0.1') {
            CLI::error('Mail does not go to Mailpit here (SMTPHost ' . config('Email')->SMTPHost . '). Refusing.');

            return EXIT_ERROR;
        }

        return match ($params[0] ?? '') {
            'fire'  => $this->fire(),
            'off'   => $this->switch(false),
            default => $this->usageError(),
        };
    }

    private function usageError(): int
    {
        CLI::error('Usage: php spark load:mail fire|off');

        return EXIT_ERROR;
    }

    /** @return list<array<string,mixed>> load test listings with their tallies */
    private function practices(): array
    {
        $out = [];
        foreach (glob(LoadSeed::DIR . 'profile-*.json') ?: [] as $f) {
            $t = json_decode((string) file_get_contents($f), true);
            if (is_array($t)) {
                $out[] = $t;
            }
        }

        return $out;
    }

    private function switch(bool $on): int
    {
        $rules = config('BookingNotifications')->defaultRules;
        $n     = 0;
        foreach ($this->practices() as $t) {
            $id       = (int) $t['listing_id'];
            $customer = [];
            foreach ($rules['customer'] as $key => $value) {
                [$event, $channel]            = explode('.', $key);
                $customer[$event][$channel] = $on && $value;
            }
            $team = [];
            foreach ($rules['team'] as $key => $value) {
                $team[explode('.', $key)[0]] = $on && $value;
            }
            (new NotificationRules($id))->save($customer, $team, null);
            BookingSettings::for($id)->save(['notifications.reminder_primary' => $on ? '1440' : '0'], null);
            $members = new BusinessMemberService();
            foreach ([$t['owner'], $t['reception']['1'], $t['reception']['2']] as $m) {
                $members->setNotify($id, (int) $m['member'], $on, null);
            }
            $n++;
        }
        CLI::write(sprintf('Booking emails %s on %d load test practices.', $on ? 'ON' : 'off', $n), $on ? 'yellow' : 'green');

        return EXIT_SUCCESS;
    }

    private function fire(): int
    {
        $db  = db_connect();
        $got = (int) ($db->query('SELECT GET_LOCK(?, 70) AS got', [self::LOCK])->getRow()->got ?? 0);
        if ($got !== 1) {
            CLI::error('bookings:dispatch is running and did not finish in 70 s.');

            return EXIT_ERROR;
        }

        try {
            $startLog = (int) ($db->query('SELECT COALESCE(MAX(id), 0) AS n FROM xs_booking_delivery_logs')->getRow()->n);
            $before   = (int) $db->query("SELECT COUNT(*) AS n FROM xs_booking_notification_queue WHERE status = 'queued'")->getRow()->n;
            if ($before > 0) {
                CLI::write("{$before} messages were already queued; they are sent and checked too.");
            }
            $this->switch(true);

            $secs    = (int) (CLI::getOption('flood') ?? 20);
            $workers = (int) (CLI::getOption('workers') ?? 8);
            if ($secs > 0) {
                CLI::write("Booking for {$secs} s with {$workers} workers (scripts/load-flood.sh) ...");
                passthru(sprintf('cd %s && WORKERS=%d SECS=%d PROFILE_BASE=%d bash scripts/load-flood.sh', escapeshellarg(ROOTPATH), $workers, $secs, mt_rand(0, 400)));
            }
            $queued = (int) $db->query("SELECT COUNT(*) AS n FROM xs_booking_notification_queue WHERE status = 'queued'")->getRow()->n;
            CLI::write("Queued after the bookings: {$queued} (reminders are queued by the first run).");

            // Every email, as it leaves (CI4 triggers 'email' after a successful send).
            $sent = [];
            Events::on('email', static function (array $archive) use (&$sent): void {
                $sent[] = ['to' => array_values((array) ($archive['recipients'] ?? [])), 'subject' => (string) ($archive['subject'] ?? ''), 'body' => (string) ($archive['body'] ?? '')];
            });

            $runs = [];
            $t0   = microtime(true);
            do {
                $t     = microtime(true);
                $stats = (new NotificationDispatcher())->run(500, microtime(true) + 50);
                $runs[] = $stats + ['s' => microtime(true) - $t];
                CLI::write(sprintf('  run %2d: reminders queued %5d, sent %4d, failed %d, waiting %4d, not needed %d, %.1f s',
                    count($runs), $stats['reminders'], $stats['sent'], $stats['failed'], $stats['retrying'], $stats['cancelled'], microtime(true) - $t));
                $left = (int) $db->query("SELECT COUNT(*) AS n FROM xs_booking_notification_queue WHERE status = 'queued' AND run_after <= UTC_TIMESTAMP()")->getRow()->n;
            } while ($left > 0 && count($runs) < 400);
            $total = microtime(true) - $t0;

            $failures = $this->check($sent, $startLog);
            $sentN    = array_sum(array_column($runs, 'sent'));
            CLI::newLine();
            CLI::write(sprintf('%d emails sent in %d dispatcher runs, %.0f s (%.0f a second while sending). Cron runs once a minute and sends %d at most a run, so on production this would take about %d minutes.',
                $sentN, count($runs), $total, $sentN / max($total, 0.001), config('BookingNotifications')->perRunLimit['email'], count($runs)));
            CLI::write('Failed sends: ' . array_sum(array_column($runs, 'failed')) . '. Every email was checked against the database:');
            foreach ($failures as $label => $list) {
                CLI::write(sprintf('  %s  %-58s %s', $list === [] ? 'PASS' : 'FAIL', $label, $list === [] ? '' : count($list) . ' wrong, e.g. ' . json_encode(array_slice($list, 0, 3))), $list === [] ? 'green' : 'red');
            }

            return array_filter($failures) === [] ? EXIT_SUCCESS : EXIT_ERROR;
        } finally {
            if (CLI::getOption('keep-on') === null) {
                $this->switch(false);
            }
            $db->query('SELECT RELEASE_LOCK(?)', [self::LOCK]);
        }
    }

    /**
     * Pair each email with the delivery log the dispatcher wrote after it
     * (one process, in order), and check it against the appointment.
     *
     * @param list<array{to:list<string>,subject:string,body:string}> $sent
     *
     * @return array<string,list<mixed>>
     */
    private function check(array $sent, int $startLog): array
    {
        $db   = db_connect();
        $logs = $db->query("SELECT l.id, l.listing_id, l.appointment_id, l.recipient_type, l.event, q.member_id
            FROM xs_booking_delivery_logs l LEFT JOIN xs_booking_notification_queue q ON q.id = l.queue_id
            WHERE l.id > ? AND l.outcome = 'sent' AND l.channel = 'email' ORDER BY l.id", [$startLog])->getResultArray();

        $out = [
            'One email for each sent delivery log'                   => [],
            'To the appointment\'s own customer'                     => [],
            'Team notice to a member of that practice'               => [],
            'Reception only hears about the people they look after'  => [],
            'Names the practice, person and service of the booking'  => [],
            'Shows the booking\'s own local time'                    => [],
            'Customer link carries that customer\'s own key'         => [],
        ];
        if (count($logs) !== count($sent)) {
            $out['One email for each sent delivery log'][] = ['emails' => count($sent), 'logs' => count($logs)];

            return $out;
        }

        $tz      = new DateTimeZone('Africa/Johannesburg');
        $ids     = array_values(array_unique(array_map('intval', array_column($logs, 'appointment_id'))));
        $appts   = [];
        foreach (array_chunk($ids, 1000) as $chunk) {
            $rows = $db->query('SELECT a.id, a.listing_id, a.provider_id, a.start_at, a.provider_name, a.service_name, l.display_name, c.email AS customer_email, c.hash AS customer_hash
                FROM xs_booking_appointments a JOIN xs_directory_listings l ON l.id = a.listing_id LEFT JOIN xs_booking_customers c ON c.id = a.customer_id
                WHERE a.id IN (' . implode(',', $chunk) . ')')->getResultArray();
            foreach ($rows as $r) {
                $appts[(int) $r['id']] = $r;
            }
        }
        $members = [];
        foreach ($db->query("SELECT m.id, m.listing_id, m.role, a.email FROM xs_business_members m JOIN xs_accounts a ON a.id = m.account_id")->getResultArray() as $m) {
            $members[(int) $m['id']] = $m;
        }
        $assigned = [];
        foreach ($db->query('SELECT member_id, provider_id FROM xs_booking_staff_assignments')->getResultArray() as $s) {
            $assigned[(int) $s['member_id']][] = (int) $s['provider_id'];
        }

        foreach ($logs as $i => $log) {
            $mail = $sent[$i];
            $a    = $appts[(int) $log['appointment_id']] ?? null;
            $ref  = ['log' => (int) $log['id'], 'appointment' => (int) $log['appointment_id']];
            if ($a === null || (int) $a['listing_id'] !== (int) $log['listing_id']) {
                $out['To the appointment\'s own customer'][] = $ref + ['why' => 'appointment not of that practice'];

                continue;
            }
            $to   = array_map('strtolower', $mail['to']);
            $text = html_entity_decode(strip_tags($mail['subject'] . "\n" . $mail['body']), ENT_QUOTES | ENT_HTML5);

            if ($log['recipient_type'] === 'customer') {
                if ($to !== [strtolower((string) $a['customer_email'])]) {
                    $out['To the appointment\'s own customer'][] = $ref + ['to' => $to, 'expected' => $a['customer_email']];
                }
                if (preg_match_all('#book/customer/([0-9a-f]{64})#', $mail['body'], $m) && array_diff(array_unique($m[1]), [$a['customer_hash']]) !== []) {
                    $out['Customer link carries that customer\'s own key'][] = $ref;
                }
            } else {
                $member = $members[(int) $log['member_id']] ?? null;
                if ($member === null || (int) $member['listing_id'] !== (int) $a['listing_id'] || $to !== [strtolower((string) $member['email'])]) {
                    $out['Team notice to a member of that practice'][] = $ref + ['to' => $to];
                } elseif ($member['role'] === 'staff' && ! in_array((int) $a['provider_id'], $assigned[(int) $member['id']] ?? [], true)) {
                    $out['Reception only hears about the people they look after'][] = $ref + ['to' => $to];
                }
            }
            foreach (['display_name', 'provider_name', 'service_name'] as $k) {
                if (! str_contains($text, (string) $a[$k])) {
                    $out['Names the practice, person and service of the booking'][] = $ref + ['missing' => $a[$k]];

                    break;
                }
            }
            $local = (new DateTimeImmutable((string) $a['start_at'], new DateTimeZone('UTC')))->setTimezone($tz)->format('H:i');
            if (! str_contains($text, $local)) {
                $out['Shows the booking\'s own local time'][] = $ref + ['expected' => $local];
            }
        }

        return $out;
    }
}

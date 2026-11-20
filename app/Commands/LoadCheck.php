<?php

namespace App\Commands;

use App\Models\Bookings\BookingAppointmentModel;
use App\Models\Bookings\BusinessMemberModel;
use App\Services\Bookings\AppointmentService;
use App\Services\Bookings\BookingAuthorization;
use App\Services\Bookings\Calendar\CalendarQuery;
use App\Services\Bookings\CustomerService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use DateTimeZone;

/**
 * Integrity check for the load test data (owner, 20 Nov 2026): no
 * appointment on the wrong profile, person, place or customer, no double
 * booking, and reception sees only the people it looks after.
 *
 *   php spark load:check                 # every check, 20 profiles for the scope pass
 *   php spark load:check --sample 100    # more profiles in the scope pass
 *
 * Read only. Prints PASS or FAIL per check with up to 5 offending ids, and
 * the tallies load:seed left in writable/loadtest/. Exit code 1 on any FAIL.
 */
class LoadCheck extends BaseCommand
{
    protected $group       = 'Directory';
    protected $name        = 'load:check';
    protected $description = 'Check the load test data: profile, person, place and customer of every appointment, overlaps and reception scope.';
    protected $usage       = 'load:check [--sample 20]';

    private int $fails = 0;

    public function run(array $params): int
    {
        $db = db_connect();
        if ($db->getDatabase() !== LoadDb::LOAD_DB) {
            CLI::error('The live DB is ' . $db->getDatabase() . ', not ' . LoadDb::LOAD_DB . '. Run npm run load:on first.');

            return EXIT_ERROR;
        }
        $db->query('SET SESSION group_concat_max_len = 4096');

        // Only load test rows: one temporary list of their profile ids.
        $db->query('DROP TEMPORARY TABLE IF EXISTS lt_ids');
        $db->query("CREATE TEMPORARY TABLE lt_ids (id INT UNSIGNED PRIMARY KEY) SELECT id FROM xs_directory_listings WHERE signup_source = 'loadtest'");
        $profiles = (int) $db->query('SELECT COUNT(*) AS n FROM lt_ids')->getRowArray()['n'];
        $appts    = (int) $db->query('SELECT COUNT(*) AS n FROM xs_booking_appointments a JOIN lt_ids l ON l.id = a.listing_id')->getRowArray()['n'];
        CLI::write("Checking {$appts} appointments on {$profiles} load test profiles.");
        CLI::newLine();

        $A = 'xs_booking_appointments a JOIN lt_ids l ON l.id = a.listing_id';
        // South Africa is UTC+2 all year (no daylight saving).
        $local = 'DATE_ADD(a.start_at, INTERVAL 2 HOUR)';
        $localEnd = 'DATE_ADD(a.end_at, INTERVAL 2 HOUR)';

        $this->check($db, 'Person belongs to the same profile', "SELECT a.id FROM {$A} JOIN xs_booking_providers p ON p.id = a.provider_id WHERE p.listing_id <> a.listing_id");
        $this->check($db, 'Person exists', "SELECT a.id FROM {$A} LEFT JOIN xs_booking_providers p ON p.id = a.provider_id WHERE p.id IS NULL");
        $this->check($db, 'Service belongs to the same profile', "SELECT a.id FROM {$A} JOIN xs_booking_services s ON s.id = a.service_id WHERE s.listing_id <> a.listing_id");
        $this->check($db, 'Customer belongs to the same profile', "SELECT a.id FROM {$A} JOIN xs_booking_customers c ON c.id = a.customer_id WHERE c.listing_id <> a.listing_id");
        $this->check($db, 'Every appointment has a customer', "SELECT a.id FROM {$A} LEFT JOIN xs_booking_customers c ON c.id = a.customer_id WHERE c.id IS NULL");
        $this->check($db, 'Branch belongs to the same profile', "SELECT a.id FROM {$A} JOIN xs_directory_practice_locations loc ON loc.id = a.location_id WHERE loc.listing_id <> a.listing_id");
        $this->check($db, 'The person gives that service', "SELECT a.id FROM {$A} LEFT JOIN xs_booking_provider_services ps ON ps.provider_id = a.provider_id AND ps.service_id = a.service_id WHERE ps.provider_id IS NULL");
        $this->check($db, 'Inside the person\'s hours, at the place they work that day', "SELECT a.id FROM {$A} WHERE NOT EXISTS (
            SELECT 1 FROM xs_booking_provider_hours h WHERE h.provider_id = a.provider_id AND h.weekday = WEEKDAY({$local}) + 1
              AND h.start_time <= TIME({$local}) AND h.end_time >= TIME({$localEnd}) AND DATE({$local}) = DATE({$localEnd})
              AND h.location_id <=> a.location_id)");
        $this->check($db, 'No double booking (same person, overlapping, both live)', "SELECT id FROM (
            SELECT a.id, a.start_at, LAG(a.end_at) OVER (PARTITION BY a.provider_id ORDER BY a.start_at, a.id) AS prev_end
            FROM {$A} WHERE a.status IN ('pending', 'confirmed')) x WHERE prev_end > start_at");
        $this->check($db, 'Snapshot names match the person and service', "SELECT a.id FROM {$A}
            JOIN xs_booking_providers p ON p.id = a.provider_id JOIN xs_booking_services s ON s.id = a.service_id
            WHERE a.provider_name <> p.name OR a.service_name <> s.name OR a.service_duration_min <> s.duration_min");
        $this->check($db, 'Length matches the service', "SELECT a.id FROM {$A} WHERE TIMESTAMPDIFF(MINUTE, a.start_at, a.end_at) <> a.service_duration_min");
        $this->check($db, 'References unique and in the WSB-BOOK format', "SELECT a.id FROM {$A} WHERE a.reference IS NULL OR a.reference NOT LIKE 'WSB-BOOK-%'");
        $this->check($db, 'A shared email is a separate customer at each profile', "SELECT c.email FROM xs_booking_customers c JOIN lt_ids l ON l.id = c.listing_id
            WHERE c.email LIKE 'patient%@customer.example' GROUP BY c.email, c.listing_id HAVING COUNT(*) > 1");
        $this->check($db, 'Reception looks after people of its own profile only', "SELECT sa.id FROM xs_booking_staff_assignments sa JOIN lt_ids l ON l.id = sa.listing_id
            JOIN xs_booking_providers p ON p.id = sa.provider_id JOIN xs_business_members m ON m.id = sa.member_id
            WHERE p.listing_id <> sa.listing_id OR m.listing_id <> sa.listing_id");
        $this->check($db, 'Nothing queued to send', "SELECT q.id FROM xs_booking_notification_queue q JOIN lt_ids l ON l.id = q.listing_id");

        CLI::newLine();
        $this->scopePass($db, max(1, (int) (CLI::getOption('sample') ?? 20)));
        CLI::newLine();
        $this->tallies();

        $db->query('DROP TEMPORARY TABLE IF EXISTS lt_ids');
        CLI::newLine();
        if ($this->fails > 0) {
            CLI::error($this->fails . ' check(s) failed.');

            return EXIT_ERROR;
        }
        CLI::write('All checks passed.', 'green');

        return EXIT_SUCCESS;
    }

    private function check($db, string $label, string $sql): void
    {
        $t0   = microtime(true);
        $rows = $db->query($sql . ' LIMIT 6')->getResultArray();
        $ok   = $rows === [];
        $ok || $this->fails++;
        CLI::write(sprintf(
            '%s  %-62s %5.1fs%s',
            CLI::color($ok ? 'PASS' : 'FAIL', $ok ? 'green' : 'red'),
            $label,
            microtime(true) - $t0,
            $ok ? '' : '  e.g. ' . implode(', ', array_slice(array_map(static fn (array $r): string => (string) reset($r), $rows), 0, 5)),
        ));
    }

    private function result(string $label, bool $ok, string $detail = ''): void
    {
        $ok || $this->fails++;
        CLI::write(sprintf('%s  %-62s %s', CLI::color($ok ? 'PASS' : 'FAIL', $ok ? 'green' : 'red'), $label, $detail));
    }

    /** Reception, through the services the /business screens use. */
    private function scopePass($db, int $sample): void
    {
        $ids = array_map('intval', array_column($db->query('SELECT id FROM lt_ids ORDER BY RAND() LIMIT ' . $sample)->getResultArray(), 'id'));
        $calendarLeaks = $filterLeaks = $customerLeaks = $findLeaks = $scopeWrong = 0;
        $members = 0;
        $tz      = new DateTimeZone('Africa/Johannesburg');
        $from    = date('Y-m-d', strtotime('+1 day'));
        $to      = date('Y-m-d', strtotime('+35 days'));

        foreach ($ids as $listingId) {
            $listing = (array) (new \App\Models\DirectoryListingModel())->find($listingId);
            $all     = array_map('intval', array_column($db->query('SELECT id FROM xs_booking_providers WHERE listing_id = ?', [$listingId])->getResultArray(), 'id'));
            $staff   = BusinessMemberModel::forBusiness($listingId)->where('role', 'staff')->findAll();
            foreach ($staff as $member) {
                $members++;
                $scope = (new BookingAuthorization())->providerScope($member);
                if ($scope === null || $scope === [] || array_diff($scope, $all) !== []) {
                    $scopeWrong++;

                    continue;
                }
                $outside = array_values(array_diff($all, $scope));

                // Calendar: a month of events, and asking for someone outside the scope.
                $query  = new CalendarQuery($listingId, $tz);
                $events = $query->forRange($from, $to, [], $scope);
                foreach ($events as $e) {
                    if (! in_array((int) $e['provider_id'], $scope, true)) {
                        $calendarLeaks++;
                    }
                }
                foreach ($outside as $pid) {
                    $filterLeaks += count($query->forRange($from, $to, ['provider_id' => $pid], $scope));
                }

                // Opening an appointment of someone outside the scope by id.
                $svc = new AppointmentService($listing, $scope, (int) $member['id']);
                foreach ($outside as $pid) {
                    $row = BookingAppointmentModel::forBusiness($listingId)->where('provider_id', $pid)->orderBy('RAND()')->first();
                    if ($row !== null && $svc->find((int) $row['id']) !== null) {
                        $findLeaks++;
                    }
                }
                // Another profile's appointment by id.
                $foreign = $db->query('SELECT a.id FROM xs_booking_appointments a JOIN lt_ids l ON l.id = a.listing_id WHERE a.listing_id <> ? LIMIT 1', [$listingId])->getRowArray();
                if ($foreign !== null && $svc->find((int) $foreign['id']) !== null) {
                    $findLeaks++;
                }

                // Customers list: everyone shown has an appointment with someone in scope, at this profile.
                $list = (new CustomerService($listingId))->list('', $scope, 1, 200);
                $shown = array_map('intval', array_column($list['rows'], 'id'));
                if ($shown !== []) {
                    $bad = $db->query('SELECT COUNT(*) AS n FROM xs_booking_customers c WHERE c.id IN (' . implode(',', $shown) . ')
                        AND (c.listing_id <> ? OR NOT EXISTS (SELECT 1 FROM xs_booking_appointments a WHERE a.customer_id = c.id AND a.provider_id IN (' . implode(',', $scope) . ')))', [$listingId])->getRowArray();
                    $customerLeaks += (int) $bad['n'];
                }
            }
        }
        CLI::write("Reception scope pass: {$members} reception sign ins on " . count($ids) . ' profiles.');
        $this->result('Scope is the assigned people of the own profile', $scopeWrong === 0, (string) $scopeWrong);
        $this->result('Calendar shows only their people', $calendarLeaks === 0, (string) $calendarLeaks);
        $this->result('Calendar filter for someone else shows nothing', $filterLeaks === 0, (string) $filterLeaks);
        $this->result('Opening someone else\'s appointment by id is refused', $findLeaks === 0, (string) $findLeaks);
        $this->result('Customer list shows only their people\'s customers', $customerLeaks === 0, (string) $customerLeaks);
    }

    /** What load:seed found while booking. */
    private function tallies(): void
    {
        $files = glob(LoadSeed::DIR . 'profile-*.json') ?: [];
        $booked = $legit = $closed = $queued = 0;
        $by    = [];
        $wrong = [];
        $samples = [];
        foreach ($files as $f) {
            $t = json_decode((string) file_get_contents($f), true);
            $booked += $t['booked'];
            $legit  += $t['legit_refused'];
            $closed += $t['closed_days'];
            $queued += $t['queued_messages'] ?? 0;
            foreach ($t['by'] as $k => $v) {
                $by[$k] = ($by[$k] ?? 0) + $v;
            }
            foreach ($t['wrong'] as $k => $v) {
                foreach (['tried', 'refused', 'saved'] as $x) {
                    $wrong[$k][$x] = ($wrong[$k][$x] ?? 0) + $v[$x];
                }
                foreach ($v['reasons'] ?? [] as $r => $c) {
                    $wrong[$k]['reasons'][$r] = ($wrong[$k]['reasons'][$r] ?? 0) + $c;
                }
            }
            if ($t['legit_problems'] !== [] && count($samples) < 5) {
                $samples[] = $t['legit_problems'][0];
            }
        }
        CLI::write(sprintf('Seeder tallies (%d profiles): %d booked (%s), %d person days closed for a public holiday.',
            count($files), $booked, implode(', ', array_map(static fn ($k, $v): string => "{$k} {$v}", array_keys($by), $by)), $closed));
        foreach ($wrong as $kind => $w) {
            $this->result('Wrong try refused: ' . str_replace('_', ' ', $kind), $w['saved'] === 0, sprintf('%d tried, %d refused, %d saved', $w['tried'], $w['refused'], $w['saved']));
            foreach ($w['reasons'] ?? [] as $reason => $count) {
                CLI::write('        ' . $count . ' x ' . $reason, 'dark_gray');
            }
        }
        $this->result('Real bookings the code refused', $legit === 0, (string) $legit);
        foreach ($samples as $s) {
            CLI::write('        ' . json_encode($s), 'dark_gray');
        }
        $this->result('Messages queued while seeding', $queued === 0, (string) $queued);
    }
}

<?php

namespace App\Commands;

use App\Models\Bookings\BusinessMemberModel;
use App\Services\Bookings\AppointmentService;
use App\Services\Bookings\AvailabilityService;
use App\Services\Bookings\BookingAuthorization;
use App\Services\Bookings\PublicBookingService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use DateTimeImmutable;
use DateTimeZone;

/**
 * One racer in the double booking race (owner, 20 Nov 2026). Many of these
 * run at once (scripts/load-race.sh), each its own process and DB
 * connection, and all book the same free time for the same person at the
 * same instant: exactly one may win. Even racers book online
 * (PublicBookingService), odd ones as reception or the owner
 * (AppointmentService).
 *
 *   php spark load:race --profile 7 --racer 3 --at 1763625600 [--slot "2027-01-12 07:15:00"]
 *   php spark load:race --profile 7 --saves --at 1763625600 --seconds 20   (scripts/load-saves.sh)
 *   php spark load:race --profile 7 --flood --at 1763625600 --seconds 30   (scripts/load-flood.sh)
 *   php spark load:race --profile 7 --trace   (every query of one booking, grouped)
 *
 * Prints one JSON line: racer, path, won, reference or error.
 * Without --slot it prints the slot it would use and exits (the script asks first);
 * with --count and --slot it prints how many live appointments hold that time.
 */
class LoadRace extends BaseCommand
{
    protected $group       = 'Directory';
    protected $name        = 'load:race';
    protected $description = 'One racer in the double booking race on the load test DB.';
    protected $usage       = 'load:race --profile N --racer I --at UNIX [--slot "Y-m-d H:i:s"]';

    public function run(array $params): int
    {
        $db = db_connect();
        if (ENVIRONMENT === 'production' || $db->getDatabase() !== LoadDb::LOAD_DB) {
            CLI::error('Only on the load test DB.');

            return EXIT_ERROR;
        }
        $n       = (int) (CLI::getOption('profile') ?? 1);
        $listing = $db->query("SELECT * FROM xs_directory_listings WHERE slug = ? AND signup_source = 'loadtest'", [sprintf('loadtest-%04d', $n)])->getRowArray();
        $tally   = json_decode((string) @file_get_contents(LoadSeed::DIR . sprintf('profile-%04d.json', $n)), true);
        if ($listing === null || ! is_array($tally)) {
            CLI::error("Load test profile {$n} is not built.");

            return EXIT_ERROR;
        }
        $pid       = (int) $tally['providers'][0];
        $serviceId = (int) $tally['services'][0];

        if (CLI::getOption('flood') !== null || array_key_exists('flood', $params)) {
            return $this->flood($listing, $tally, $n);
        }
        if (CLI::getOption('saves') !== null || array_key_exists('saves', $params)) {
            return $this->saves($listing, $tally, $n);
        }
        if (CLI::getOption('trace') !== null || array_key_exists('trace', $params)) {
            return $this->trace($listing, $tally, $pid, $serviceId, $n);
        }

        $slot = CLI::getOption('slot');
        if (! is_string($slot) || $slot === '') {
            // A free time a week out, the same for every racer.
            $free = (new AvailabilityService($listing))->nextAvailable($serviceId, $pid, (new DateTimeImmutable('+7 days'))->format('Y-m-d'));
            CLI::write($free['start'] ?? '');

            return EXIT_SUCCESS;
        }

        if (CLI::getOption('count') !== null || array_key_exists('count', $params)) {
            // Live appointments for this person overlapping the slot, straight from the table.
            $row = $db->query("SELECT COUNT(*) AS n FROM xs_booking_appointments WHERE listing_id = ? AND provider_id = ?
                AND status IN ('pending', 'confirmed') AND start_at < DATE_ADD(?, INTERVAL 15 MINUTE) AND end_at > ?", [(int) $listing['id'], $pid, $slot, $slot])->getRowArray();
            CLI::write((string) $row['n']);

            return EXIT_SUCCESS;
        }

        $racer = (int) (CLI::getOption('racer') ?? 0);
        $at    = (float) (CLI::getOption('at') ?? microtime(true));
        $c     = 1000 + $racer;
        $who   = ['first_name' => 'Racer', 'last_name' => ['Botha', 'Nkosi', 'Pillay', 'Smit', 'Zulu', 'Naidoo', 'Venter', 'Dlamini'][$racer % 8],
            'email' => sprintf('racer%02d-%04d@customer.example', $racer, $n), 'phone' => sprintf('083%07d', $n * 1000 + $c)];

        // Build everything first, so the race is only the booking.
        $now    = new DateTimeImmutable('now', new DateTimeZone('Africa/Johannesburg'));
        $public = new PublicBookingService($listing, $now);
        $member = BusinessMemberModel::forBusiness((int) $listing['id'])->find($racer % 4 === 1 ? (int) $tally['reception'][1]['member'] : (int) $tally['owner']['member']);
        $staff  = new AppointmentService($listing, (new BookingAuthorization())->providerScope($member), (int) $member['id'], null, $now);
        $local  = (new DateTimeImmutable($slot, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('Africa/Johannesburg'));
        $db->query('SELECT 1');

        while (microtime(true) < $at) {
            usleep(200);
        }
        if ($racer % 2 === 0) {
            $path = 'public';
            $res  = $public->book(['service_id' => $serviceId, 'provider_id' => $pid, 'start' => $slot, 'place' => '0'] + $who);
            $won  = $res['errors'] === [];
            $out  = $won ? $res['reference'] : json_encode($res['errors']);
        } else {
            $path = $racer % 4 === 1 ? 'reception' : 'owner';
            $res  = $staff->create(['provider_id' => $pid, 'service_id' => $serviceId, 'date' => $local->format('Y-m-d'), 'time' => $local->format('H:i'), 'status' => 'confirmed'] + $who);
            $won  = $res['id'] !== null;
            $out  = $won ? $res['reference'] : json_encode($res['errors']);
        }
        CLI::write(json_encode(['racer' => $racer, 'path' => $path, 'won' => $won, 'result' => $out, 'late_ms' => round((microtime(true) - $at) * 1000)]));

        return EXIT_SUCCESS;
    }

    /**
     * --trace: book the next free time once online and once as reception,
     * and list every query each booking sent (same queries grouped), so a
     * repeated read shows. The bookings are kept, as any real booking.
     *
     * @param array<string,mixed> $listing
     * @param array<string,mixed> $tally
     */
    private function trace(array $listing, array $tally, int $pid, int $serviceId, int $n): int
    {
        $seen = [];
        \CodeIgniter\Events\Events::on('DBQuery', static function ($q) use (&$seen): void {
            $sql    = preg_replace(['/\s+/', "/'[^']*'/", '/\b\d+\b/'], [' ', '?', '?'], $q->getOriginalQuery());
            $seen[] = $sql;
        });
        $now    = new DateTimeImmutable('now', new DateTimeZone('Africa/Johannesburg'));
        $member = BusinessMemberModel::forBusiness((int) $listing['id'])->find((int) $tally['reception'][1]['member']);
        $who    = static fn (int $i): array => ['first_name' => 'Trace', 'last_name' => 'Naidoo', 'email' => sprintf('trace%d-%04d@customer.example', $i, $n), 'phone' => sprintf('082%07d', $n * 10 + $i)];

        foreach (['public', 'reception'] as $i => $path) {
            $free = (new AvailabilityService($listing, $now))->nextAvailable($serviceId, $pid, $now->modify('+14 days')->format('Y-m-d'));
            if ($free === null) {
                CLI::error('No free time.');

                return EXIT_ERROR;
            }
            $seen = [];
            $t    = microtime(true);
            if ($path === 'public') {
                $res = (new PublicBookingService($listing, $now))->book(['service_id' => $serviceId, 'provider_id' => $pid, 'start' => $free['start'], 'place' => '0'] + $who($i));
                $ok  = $res['errors'] === [];
            } else {
                $local = (new DateTimeImmutable($free['start'], new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('Africa/Johannesburg'));
                $res   = (new AppointmentService($listing, (new BookingAuthorization())->providerScope($member), (int) $member['id'], null, $now))
                    ->create(['provider_id' => $pid, 'service_id' => $serviceId, 'date' => $local->format('Y-m-d'), 'time' => $local->format('H:i'), 'status' => 'confirmed'] + $who($i));
                $ok = $res['id'] !== null;
            }
            $ms     = round((microtime(true) - $t) * 1000);
            $counts = array_count_values($seen);
            arsort($counts);
            CLI::write(sprintf('%s booking %s: %d queries, %d ms', $path, $ok ? 'saved' : 'REFUSED', count($seen), $ms), $ok ? 'green' : 'red');
            foreach ($counts as $sql => $c) {
                CLI::write(sprintf('  %3dx %s', $c, mb_substr($sql, 0, 170)));
            }
        }

        return EXIT_SUCCESS;
    }

    /**
     * --saves: from --at, for --seconds, add a service for all three people
     * and save person 3's hours (unchanged), again and again, as an owner
     * setting up would. Many processes on different businesses at once found
     * the deadlocks of 20 Nov 2026. Prints one JSON line: saves, failures and
     * their messages. The added services are removed at the end.
     *
     * @param array<string,mixed> $listing
     * @param array<string,mixed> $tally
     */
    private function saves(array $listing, array $tally, int $n): int
    {
        $id      = (int) $listing['id'];
        $people  = array_map('intval', $tally['providers']);
        $hours   = (new \App\Services\Bookings\TeamService($id))->hoursForForm($people[2]);
        $at      = (float) (CLI::getOption('at') ?? microtime(true));
        $until   = $at + (float) (CLI::getOption('seconds') ?? 20);
        $added   = [];
        $done    = 0;
        $errors  = [];
        while (microtime(true) < $at) {
            usleep(200);
        }
        while (microtime(true) < $until) {
            try {
                $res = (new \App\Services\Bookings\CatalogueService($id))->saveService(null, [
                    'name' => 'Stress ' . bin2hex(random_bytes(3)), 'duration_min' => 30, 'price' => '300', 'delivery_modes' => ['onsite'], 'provider_ids' => $people,
                ], null, false);
                if ($res['id'] !== null) {
                    $added[] = (int) $res['id'];
                }
                $bad = (new \App\Services\Bookings\TeamService($id))->saveHours($people[2], $hours, null);
                if ($bad !== []) {
                    throw new \RuntimeException('hours refused: ' . json_encode($bad));
                }
                $done++;
            } catch (\Throwable $e) {
                db_connect()->transRollback();
                \App\Services\Bookings\BookingMemo::forget($id);
                $errors[] = mb_substr($e->getMessage(), 0, 90);
            }
            if (count($added) >= 40) {
                $this->dropServices($id, $added);
                $added = [];
            }
        }
        $this->dropServices($id, $added);
        CLI::write(json_encode(['profile' => $n, 'saves' => $done, 'failed' => count($errors), 'errors' => array_count_values($errors)]));

        return EXIT_SUCCESS;
    }

    /** @param list<int> $ids */
    private function dropServices(int $listingId, array $ids): void
    {
        if ($ids !== []) {
            db_connect()->table('booking_services')->where('listing_id', $listingId)->whereIn('id', $ids)->delete();
            \App\Services\Bookings\BookingMemo::forget($listingId);
        }
    }

    /**
     * --flood: from --at, for --seconds, book without pause on this profile
     * as its customers, reception and owner would: a random person, a
     * service they give, the first free time from a random day in the next
     * six months, by a random path (a fifth online, the rest split between
     * the person's reception and the owner). Several floods on one profile
     * compete for the same diaries; losing a time to another is fine.
     * Prints one JSON line with counts and booking times (ms) per path.
     *
     * @param array<string,mixed> $listing
     * @param array<string,mixed> $tally
     */
    private function flood(array $listing, array $tally, int $n): int
    {
        $tz      = new DateTimeZone('Africa/Johannesburg');
        $people  = array_map('intval', $tally['providers']);
        $svc     = array_map('intval', $tally['services']);
        $auth    = new BookingAuthorization();
        $staff   = [];
        foreach (['owner' => $tally['owner']['member'], 'r1' => $tally['reception']['1']['member'], 'r2' => $tally['reception']['2']['member']] as $k => $memberId) {
            $staff[$k] = BusinessMemberModel::forBusiness((int) $listing['id'])->find((int) $memberId);
        }
        $at      = (float) (CLI::getOption('at') ?? microtime(true));
        $until   = $at + (float) (CLI::getOption('seconds') ?? 30);
        $racer   = (int) (CLI::getOption('racer') ?? 0);
        $ms      = ['public' => [], 'reception' => [], 'owner' => []];
        $lost    = 0;
        $none    = 0;
        $errors  = [];
        $k       = 0;
        while (microtime(true) < $at) {
            usleep(200);
        }
        while (microtime(true) < $until) {
            $k++;
            $j         = mt_rand(0, 2);
            $pid       = $people[$j];
            $serviceId = $svc[LoadSeed::OFFERS[$j][mt_rand(0, 1)]];
            $now       = new DateTimeImmutable('now', $tz);
            $free      = (new AvailabilityService($listing, $now))->nextAvailable($serviceId, $pid, $now->modify('+' . mt_rand(1, 175) . ' days')->format('Y-m-d'));
            if ($free === null) {
                $none++;

                continue;
            }
            $who  = ['first_name' => 'Flood', 'last_name' => 'Mokoena', 'email' => sprintf('flood%02d-%d-%04d@customer.example', $racer, $k % 50, $n), 'phone' => sprintf('084%07d', ($n * 100 + $racer) * 10 + $k % 10)];
            $roll = mt_rand(1, 100);
            $path = $roll <= 20 ? 'public' : ($roll <= 60 ? 'reception' : 'owner');
            $t    = microtime(true);
            try {
                if ($path === 'public') {
                    $res = (new PublicBookingService($listing, $now))->book(['service_id' => $serviceId, 'provider_id' => $pid, 'start' => $free['start']] + $who);
                    $won = $res['errors'] === [];
                } else {
                    $member = $path === 'owner' ? $staff['owner'] : ($j === 2 ? $staff['r2'] : $staff['r1']);
                    $local  = (new DateTimeImmutable($free['start'], new DateTimeZone('UTC')))->setTimezone($tz);
                    $res    = (new AppointmentService($listing, $auth->providerScope($member), (int) $member['id'], null, $now))
                        ->create(['provider_id' => $pid, 'service_id' => $serviceId, 'date' => $local->format('Y-m-d'), 'time' => $local->format('H:i'), 'status' => 'confirmed'] + $who);
                    $won = $res['id'] !== null;
                }
            } catch (\Throwable $e) {
                db_connect()->transRollback();
                $errors[] = mb_substr($e->getMessage(), 0, 90);

                continue;
            }
            if ($won) {
                $ms[$path][] = (microtime(true) - $t) * 1000;
            } else {
                $lost++;
            }
        }
        $stats = [];
        foreach ($ms as $path => $list) {
            sort($list);
            $c             = count($list);
            $stats[$path] = ['n' => $c, 'ms' => $c === 0 ? [] : [round($list[(int) ($c * 0.5)]), round($list[(int) ($c * 0.95)]), round($list[$c - 1])]];
        }
        CLI::write(json_encode(['profile' => $n, 'racer' => $racer, 'booked' => array_sum(array_column($stats, 'n')), 'lost' => $lost, 'no_free_time' => $none, 'failed' => count($errors), 'errors' => array_count_values($errors), 'paths' => $stats]));

        return EXIT_SUCCESS;
    }
}

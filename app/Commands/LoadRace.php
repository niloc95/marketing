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
}

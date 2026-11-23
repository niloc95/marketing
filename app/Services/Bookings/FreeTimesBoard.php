<?php

namespace App\Services\Bookings;

use DateTimeImmutable;

/**
 * Free times as one column per place, a week at a time (owner, 23 Nov 2026,
 * decision T33): the same board on the public booking page
 * (Controllers\Book, link mode) and in every /business Book form
 * (Business\Appointments::board, button mode), drawn by book/_board.php.
 *
 * A column is a place where the service is given (by the chosen person, or
 * by anyone who offers it); a column lists only the days with a free time,
 * and each time shows only the time: the column says where.
 *
 * Customers ($public) see the customer rules (notice, horizon, bookable
 * people only); the business may book any free minute, any time ahead
 * (capped at ten years, so Later always ends).
 */
final class FreeTimesBoard
{
    /** Days a board shows at a time. */
    public const DAYS = 7;

    private AvailabilityService $engine;

    private int $listingId;

    private DateTimeImmutable $now;

    /** @var array<int,array{id:int,name:string,address:string}>|null */
    private ?array $places = null;

    /** @param array<string,mixed> $listing */
    public function __construct(private array $listing, private bool $public = true, ?DateTimeImmutable $now = null)
    {
        $this->listingId = (int) $listing['id'];
        $this->now       = $now ?? new DateTimeImmutable('now');
        // Read only, a week at a time: hours and place windows asked once.
        $this->engine = (new AvailabilityService($listing, $this->now))->remember();
    }

    /**
     * Every place of the business (0 = the profile's own address, then its
     * branches), with the address the public may see.
     *
     * @return array<int,array{id:int,name:string,address:string}>
     */
    public function places(): array
    {
        if ($this->places !== null) {
            return $this->places;
        }
        helper(['map', 'directory_ui']);
        $names = (new TeamService($this->listingId))->locationChoices($this->listing);
        $rows  = [0 => listing_public_view($this->listing)];
        foreach ((new \App\Models\DirectoryPracticeLocationModel())->forListing($this->listingId) as $loc) {
            $rows[(int) $loc['id']] = $loc;
        }
        $out = [];
        foreach ($names as $id => $name) {
            $out[$id] = ['id' => $id, 'name' => (string) $name, 'address' => isset($rows[$id]) ? map_address_text($rows[$id]) : ''];
        }

        return $this->places = $out;
    }

    /**
     * The places a service is given at: where someone who offers it (the
     * given person, or anyone) works a period that includes it (T29), in the
     * places' own order.
     *
     * @param list<int> $people
     *
     * @return list<array{id:int,name:string,address:string}>
     */
    public function placesFor(int $serviceId, array $people): array
    {
        helper('directory_hours');
        $ids = [];
        foreach ($people as $personId) {
            foreach ($this->engine->weekPeriods($personId) as $periods) {
                foreach ($periods as $p) {
                    if ($p['service_ids'] === null || in_array($serviceId, $p['service_ids'], true)) {
                        $ids[(int) ($p['location_id'] ?? 0)] = true;
                    }
                }
            }
        }

        return array_values(array_filter($this->places(), static fn (array $pl): bool => isset($ids[$pl['id']])));
    }

    /**
     * One week of free times from $from (today at the earliest), a column per
     * place. $providerId null = anyone who offers the service (each time
     * then lists who is free).
     *
     * @return array{from:string,to:string,prev:?string,next:?string,places:list<array{id:int,name:string,address:string,days:list<array{date:string,label:string,times:list<array{time:string,start:string,provider_ids:list<int>}>}>}>}
     */
    public function week(int $serviceId, ?int $providerId, string $from = ''): array
    {
        $tz    = $this->engine->timezone();
        $today = $this->now->setTimezone($tz)->format('Y-m-d');
        $last  = $this->public ? $this->engine->customerHorizon() : (new DateTimeImmutable($today, $tz))->modify('+10 years')->format('Y-m-d');
        $from  = preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) && checkdate((int) substr($from, 5, 2), (int) substr($from, 8, 2), (int) substr($from, 0, 4)) ? $from : $today;
        $from  = min(max($from, $today), $last);
        $to    = min((new DateTimeImmutable($from, $tz))->modify('+' . (self::DAYS - 1) . ' days')->format('Y-m-d'), $last);

        $offering = $this->engine->providersFor($serviceId, $this->public);
        $people   = $providerId === null ? $offering : (in_array($providerId, $offering, true) ? [$providerId] : []);

        // Never a time already gone (customers' notice is the engine's; the
        // business may book any minute still to come).
        $nowUtc  = $this->now->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        $columns = [];
        foreach ($this->placesFor($serviceId, $people) as $place) {
            $days = [];
            for ($d = new DateTimeImmutable($from, $tz); $d->format('Y-m-d') <= $to; $d = $d->modify('+1 day')) {
                $date  = $d->format('Y-m-d');
                $slots = $providerId !== null
                    ? $this->engine->slots($providerId, $serviceId, $date, $this->public, null, $place['id'])
                    : $this->engine->slotsForAnyone($serviceId, $date, $this->public, $place['id']);
                $slots = array_values(array_filter($slots, static fn (array $s): bool => (string) $s['start'] > $nowUtc));
                if ($slots === [] || $people === []) {
                    continue;
                }
                $days[] = [
                    'date'  => $date,
                    'label' => $d->format('D j M'),
                    'times' => array_map(static fn (array $s): array => [
                        'time'         => (string) $s['time'],
                        'start'        => (string) $s['start'],
                        'provider_ids' => isset($s['provider_ids']) ? array_map('intval', $s['provider_ids']) : [(int) $s['provider_id']],
                    ], $slots),
                ];
            }
            $columns[] = $place + ['days' => $days];
        }

        $back = (new DateTimeImmutable($from, $tz))->modify('-' . self::DAYS . ' days')->format('Y-m-d');

        return [
            'from'   => $from,
            'to'     => $to,
            'prev'   => $from > $today ? max($back, $today) : null,
            'next'   => $to < $last ? (new DateTimeImmutable($to, $tz))->modify('+1 day')->format('Y-m-d') : null,
            'places' => $columns,
        ];
    }
}

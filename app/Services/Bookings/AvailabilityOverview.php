<?php

namespace App\Services\Bookings;

use DateTimeImmutable;

/**
 * "When can customers book?" (/business/availability): the free times for
 * all services or one, everyone or one person, on a day or across a week, a
 * month or a year, and why a day has none.
 *
 * Read only, through the engine (AvailabilityService::remember(), so a year
 * of days asks the database once per person and day). Customers' rules
 * (notice, the booking horizon, people marked not bookable, services whose
 * duration is not confirmed) apply unless the business asks to see its own
 * view.
 *
 * Why a day has no times, in the order they are checked:
 * - `past`: before today;
 * - `too_far`: after the last day customers may book (Booking rules);
 * - `closed`: no place is open (opening hours or the public holiday answer);
 * - `off`: open, but nobody chosen works then for these services;
 * - `full`: they work, and every time is taken.
 */
final class AvailabilityOverview
{
    public const VIEWS = ['day' => 'Day', 'week' => 'Week', 'month' => 'Month', 'year' => 'Year'];

    private AvailabilityService $engine;

    /** @var array<int,string> location id (0 = main address) => name */
    private array $places;

    /** @var array<int,string> provider id => name */
    private array $names = [];

    /** @var list<int> people customers can book (not archived, bookable) */
    private array $bookable = [];

    private string $today;

    /**
     * @param array<string,mixed>       $listing
     * @param list<array<string,mixed>> $services the services to look at
     * @param list<int>                 $providerIds the people to look at
     */
    public function __construct(
        private array $listing,
        private array $services,
        private array $providerIds,
        private bool $public,
        ?DateTimeImmutable $now = null,
    ) {
        $this->engine = (new AvailabilityService($listing, $now))->remember();
        $this->today  = ($now ?? new DateTimeImmutable('now'))->setTimezone($this->engine->timezone())->format('Y-m-d');
        $this->places = (new TeamService((int) $listing['id']))->locationChoices($listing);
        foreach ((new TeamService((int) $listing['id']))->providers() as $p) {
            $this->names[(int) $p['id']] = (string) $p['name'];
            if ((int) $p['bookable'] === 1) {
                $this->bookable[] = (int) $p['id'];
            }
        }
    }

    public function engine(): AvailabilityService
    {
        return $this->engine;
    }

    /**
     * One day: per service, its free times, each with who and where.
     *
     * @return array{date:string,count:int,reason:?string,services:list<array{id:int,name:string,times:list<array{time:string,start:string,people:list<string>,places:list<string>}>}>}
     */
    public function day(string $date): array
    {
        $out    = [];
        $starts = [];
        foreach ($this->services as $s) {
            $byStart = [];
            foreach ($this->offering($s) as $pid) {
                foreach ($this->engine->slots($pid, (int) $s['id'], $date, $this->public) as $slot) {
                    $t                       = $byStart[$slot['start']] ??= ['time' => $slot['time'], 'start' => $slot['start'], 'people' => [], 'places' => []];
                    $t['people'][]           = $this->names[$pid] ?? '';
                    $t['places'][]           = $this->places[(int) ($slot['location_id'] ?? 0)] ?? '';
                    $t['places']             = array_values(array_unique($t['places']));
                    $byStart[$slot['start']] = $t;
                }
            }
            ksort($byStart);
            if ($byStart !== []) {
                $out[]  = ['id' => (int) $s['id'], 'name' => (string) $s['name'], 'times' => array_values($byStart)];
                $starts += array_fill_keys(array_keys($byStart), true);
            }
        }

        return ['date' => $date, 'count' => count($starts), 'reason' => $starts === [] ? $this->reason($date) : null, 'services' => $out];
    }

    /**
     * Every day from one date to another: how many start times are free (any
     * of the services, anyone chosen), the first one, and why there are none.
     *
     * @return list<array{date:string,count:int,first:?string,reason:?string}>
     */
    public function range(string $from, string $to): array
    {
        $out  = [];
        $day  = new DateTimeImmutable($from, $this->engine->timezone());
        $last = new DateTimeImmutable($to, $this->engine->timezone());
        for (; $day <= $last; $day = $day->modify('+1 day')) {
            $date   = $day->format('Y-m-d');
            $starts = [];
            if ($this->blocked($date) === null) {
                foreach ($this->services as $s) {
                    foreach ($this->offering($s) as $pid) {
                        foreach ($this->engine->slots($pid, (int) $s['id'], $date, $this->public) as $slot) {
                            $starts[$slot['start']] = $slot['time'];
                        }
                    }
                }
            }
            ksort($starts);
            $out[] = ['date' => $date, 'count' => count($starts), 'first' => $starts === [] ? null : reset($starts), 'reason' => $starts === [] ? $this->reason($date) : null];
        }

        return $out;
    }

    /** The first and last date a view shows: the day, its week, its month, or twelve months from its month. @return array{0:string,1:string} */
    public static function span(string $view, string $date): array
    {
        $d = new DateTimeImmutable($date);

        return match ($view) {
            'week'  => [$d->modify('monday this week')->format('Y-m-d'), $d->modify('sunday this week')->format('Y-m-d')],
            'month' => [$d->format('Y-m-01'), $d->format('Y-m-t')],
            // Twelve months from the chosen one: a year ahead, not mostly past.
            'year'  => [$d->format('Y-m-01'), $d->modify('first day of this month')->modify('+11 months')->format('Y-m-t')],
            default => [$d->format('Y-m-d'), $d->format('Y-m-d')],
        };
    }

    /** Why a day has no free times (see the class comment). */
    public function reason(string $date): string
    {
        if (($blocked = $this->blocked($date)) !== null) {
            return $blocked;
        }
        $works = false;
        foreach ($this->services as $s) {
            foreach ($this->offering($s) as $pid) {
                $works = $works || $this->engine->works($pid, $date, (int) $s['id']);
            }
        }
        if ($works) {
            return 'full';
        }
        $open = $this->engine->businessWindow($date) !== null;
        foreach (array_keys($this->places) as $placeId) {
            $open = $open || ($placeId > 0 && $this->engine->businessWindow($date, $placeId) !== null);
        }

        return $open ? 'off' : 'closed';
    }

    /** Past, or (for customers) beyond the horizon; null otherwise. */
    private function blocked(string $date): ?string
    {
        if ($date < $this->today) {
            return 'past';
        }

        return $this->public && $date > $this->engine->customerHorizon() ? 'too_far' : null;
    }

    /**
     * The chosen people who offer a service. Customers: bookable people
     * only, and nobody for a service whose duration is not confirmed (T29),
     * so such a day reads "off", not "full".
     *
     * @param array<string,mixed> $service
     *
     * @return list<int>
     */
    private function offering(array $service): array
    {
        $people = array_intersect($this->providerIds, array_map('intval', $service['provider_ids']));
        if ($this->public) {
            if ((int) ($service['length_checked'] ?? 1) !== 1) {
                return [];
            }
            $people = array_intersect($people, $this->bookable);
        }

        return array_values($people);
    }
}

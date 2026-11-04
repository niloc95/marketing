<?php

namespace App\Services\Bookings\Calendar;

use App\Services\Bookings\BookingSettings;
use App\Services\Bookings\TeamService;
use DateTimeImmutable;
use DateTimeZone;

/**
 * The day view's render model: one column per person, each with its own
 * working hours and the appointments laid out side by side where they
 * overlap. Ported from Bookings' DayViewService (with SlotInjectionTrait).
 *
 * Model (JSON safe):
 *   date, dayName, dayLabel, weekdayName, weekday (0 Sunday), isToday, isPast,
 *   timezone, businessHours {startTime, endTime, isOpen},
 *   grid {dayStart, dayEnd, totalMinutes, pixelsPerMinute, containerHeight, slots},
 *   providerColumns [{provider {id, name, color}, workingHours, grid}],
 *   appointments [...], totalAppointments
 *
 * Differences from Bookings: the columns are everyone in the member's scope
 * (Bookings showed only people with appointments that day, so a free person
 * had no column to book into), plus anyone archived who still has an
 * appointment shown; and every appointment gets `_topPx` and `_heightPx`
 * from its local time in the business's zone.
 */
class DayView
{
    private int $listingId;

    private DateTimeZone $tz;

    private CalendarRange $range;

    private CalendarHours $hours;

    private CalendarQuery $query;

    private EventLayout $layout;

    /**
     * @param array<string,mixed> $listing the profile (id, timezone, trading_hours)
     * @param list<int>|null      $scope   provider ids the member may see, null for all
     */
    public function __construct(
        array $listing,
        private ?array $scope,
        ?DateTimeImmutable $now = null,
        ?CalendarRange $range = null,
        ?CalendarHours $hours = null,
        ?CalendarQuery $query = null,
        ?EventLayout $layout = null,
    ) {
        $this->listingId = (int) $listing['id'];
        $tz              = (string) ($listing['timezone'] ?? '');
        $this->tz        = new DateTimeZone(in_array($tz, timezone_identifiers_list(), true) ? $tz : 'Africa/Johannesburg');
        $this->range     = $range ?? self::rangeFor($this->listingId, $this->tz, $now);
        $this->hours     = $hours ?? new CalendarHours($listing, $this->range);
        $this->query     = $query ?? new CalendarQuery($this->listingId, $this->tz);
        $this->layout    = $layout ?? new EventLayout();
    }

    /** The business's range: its zone, first day of the week and clock style. */
    public static function rangeFor(int $listingId, DateTimeZone $tz, ?DateTimeImmutable $now = null): CalendarRange
    {
        $settings = BookingSettings::for($listingId);

        return new CalendarRange(
            $tz,
            $settings->get('localization.first_day') === 'sunday' ? 0 : 1,
            $now,
            $settings->get('localization.time_format') === '12h',
        );
    }

    /**
     * @param array<string,mixed>            $filters provider_id, service_id, location, status
     * @param list<array<string,mixed>>|null $events  formatted appointments starting on $date (the week view passes them)
     * @param TimeGrid|null                  $grid    the week's shared grid
     *
     * @return array<string,mixed>
     */
    public function build(string $date, array $filters = [], ?array $events = null, ?TimeGrid $grid = null): array
    {
        $events ??= CalendarQuery::byDate($this->query->forRange($date, $date, $filters, $this->scope))[$date] ?? [];
        $providers = $this->providers($filters, $events);
        $grid    ??= $this->hours->grid([$date], array_column($providers, 'id'), $events);

        $byProvider = [];
        foreach ($events as $event) {
            $byProvider[$event['provider_id']][] = $event;
        }

        $columns    = [];
        $positioned = [];
        foreach ($providers as $provider) {
            $laidOut = $this->position($this->layout->resolve($byProvider[$provider['id']] ?? []), $date, $grid);
            array_push($positioned, ...$laidOut);

            $providerGrid          = $grid->dayGrid($date);
            $providerGrid['slots'] = $this->inject($providerGrid['slots'], $laidOut, $grid->resolution());

            $columns[] = [
                'provider'     => ['id' => $provider['id'], 'name' => $provider['name'], 'color' => $provider['color']],
                'workingHours' => $this->hours->workingHours($provider['id'], $date),
                'grid'         => $providerGrid,
            ];
        }
        usort($positioned, static fn (array $a, array $b): int => [$a['start_utc'], $a['id']] <=> [$b['start_utc'], $b['id']]);

        $day      = new DateTimeImmutable($date, $this->tz);
        $weekday  = $this->range->dayOfWeek($date);
        $business = $this->hours->businessHours($date);

        return [
            'date'              => $date,
            'dayName'           => $day->format('l'),
            'dayLabel'          => $day->format('l j F Y'),
            'weekdayName'       => $weekday['string'],
            'weekday'           => $weekday['int'],
            'isToday'           => $date === $this->range->today(),
            'isPast'            => $date < $this->range->today(),
            'timezone'          => $this->tz->getName(),
            'businessHours'     => ['startTime' => $business['startTime'], 'endTime' => $business['endTime'], 'isOpen' => $business['isActive']],
            'grid'              => $grid->dayGrid($date),
            'providerColumns'   => $columns,
            'appointments'      => $positioned,
            'totalAppointments' => count($positioned),
        ];
    }

    /**
     * The people shown: everyone in scope (or the one filtered), in team
     * order, then anyone else who has an appointment here (archived people).
     * A placeholder column when there is nobody.
     *
     * @param array<string,mixed>       $filters
     * @param list<array<string,mixed>> $events
     *
     * @return list<array{id:int,name:string,color:string}>
     */
    public function providers(array $filters, array $events = []): array
    {
        $only = (int) ($filters['provider_id'] ?? 0);
        $out  = [];
        foreach ((new TeamService($this->listingId))->providers() as $p) {
            $id = (int) $p['id'];
            if (($this->scope === null || in_array($id, $this->scope, true)) && ($only === 0 || $only === $id)) {
                $out[$id] = ['id' => $id, 'name' => (string) $p['name'], 'color' => \App\Services\Bookings\CatalogueService::hex($p['color'] ?? null)];
            }
        }
        foreach ($events as $event) {
            $out[$event['provider_id']] ??= ['id' => $event['provider_id'], 'name' => $event['provider_name'], 'color' => $event['provider_color']];
        }

        return $out !== [] ? array_values($out) : [['id' => 0, 'name' => 'Everyone', 'color' => CalendarQuery::DEFAULT_COLOR]];
    }

    /**
     * Absolute position on the grid from the local start and end. Shown at
     * least 15 minutes tall (Bookings' rule); an appointment running past
     * midnight stops at the bottom of the day.
     *
     * @param list<array<string,mixed>> $events
     *
     * @return list<array<string,mixed>>
     */
    private function position(array $events, string $date, TimeGrid $grid): array
    {
        $dayStart = CalendarRange::minutes($grid->dayStart());
        $ppm      = CalendarRange::PIXELS_PER_MINUTE;

        return array_map(function (array $event) use ($date, $dayStart, $ppm): array {
            $start    = (new DateTimeImmutable((string) $event['start']))->setTimezone($this->tz);
            $end      = (new DateTimeImmutable((string) $event['end']))->setTimezone($this->tz);
            $startMin = CalendarRange::minutes($start->format('H:i'));
            $endMin   = $end->format('Y-m-d') > $date ? 1440 : CalendarRange::minutes($end->format('H:i'));
            $duration = max($endMin - $startMin, 15);

            return $event + [
                '_startMin' => $startMin,
                '_endMin'   => $startMin + $duration,
                '_topPx'    => ($startMin - $dayStart) * $ppm,
                '_heightPx' => $duration * $ppm,
            ];
        }, $events);
    }

    /**
     * Put each appointment in the grid line at or before its start.
     *
     * @param list<array<string,mixed>> $slots
     * @param list<array<string,mixed>> $events positioned
     *
     * @return list<array<string,mixed>>
     */
    private function inject(array $slots, array $events, int $resolution): array
    {
        $index = array_flip(array_column($slots, 'time'));
        foreach ($events as $event) {
            $min = $event['_startMin'] - ($event['_startMin'] % $resolution);
            $key = sprintf('%02d:%02d', intdiv($min, 60), $min % 60);
            if (isset($index[$key])) {
                $slots[$index[$key]]['appointments'][] = $event;
            }
        }

        return $slots;
    }
}

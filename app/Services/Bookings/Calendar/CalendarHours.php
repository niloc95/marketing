<?php

namespace App\Services\Bookings\Calendar;

use App\Models\Bookings\BookingProviderHoursModel;
use App\Services\Bookings\AvailabilityService;
use App\Services\Bookings\BookingSettings;

/**
 * Whose hours the calendar shades and how much of the day it draws.
 * Replaces Bookings' ProviderWorkingHoursTrait and the window half of its
 * TimeGridService.
 *
 * - A person's hours for a date are their weekly row for that weekday
 *   (breaks included). Bookings fell back to the business's hours, marked
 *   inactive, when a person had none; so does this. A period counts only
 *   when its place (the profile or a branch) is open that date, so a day
 *   the main address is closed still shows the people working at a branch.
 * - The window drawn is the earliest open or start to the latest close or
 *   end, widened to whole hours and to every appointment shown, so an
 *   appointment booked outside hours is still on the grid. With nothing to
 *   go on it is 08:00 to 17:00, Bookings' default.
 *
 * Provider hours are always read with a provider filter (Bookings' rule).
 */
class CalendarHours
{
    public const DEFAULT_START = '08:00';
    public const DEFAULT_END   = '17:00';

    private int $listingId;

    private AvailabilityService $engine;

    /** @var array<int,array<int,array<string,mixed>>> provider id => weekday (1 to 7) => row */
    private array $rows = [];

    /** @var array<string,array{0:string,1:string}|null> */
    private array $windows = [];

    /** @var list<array<string,array{closed:bool,open:string}>>|null the profile's and each branch's week, read once */
    private ?array $placeWeeks = null;

    /** @param array<string,mixed> $listing */
    public function __construct(private array $listing, private CalendarRange $range, ?AvailabilityService $engine = null)
    {
        $this->listingId = (int) $listing['id'];
        $this->engine    = $engine ?? new AvailabilityService($listing);
    }

    /**
     * Read the weekly hours of these people in one query.
     *
     * @param list<int> $providerIds
     */
    public function load(array $providerIds): void
    {
        $ids = array_values(array_diff(array_unique(array_filter($providerIds, static fn (int $id): bool => $id > 0)), array_keys($this->rows)));
        if ($ids === []) {
            return;
        }
        foreach ($ids as $id) {
            $this->rows[$id] = [];
        }
        foreach (BookingProviderHoursModel::forBusiness($this->listingId)->whereIn('provider_id', $ids)->findAll() as $row) {
            // Several periods a day since T29: the day's rows, in time order.
            $this->rows[(int) $row['provider_id']][(int) $row['weekday']][] = $row;
        }
    }

    /**
     * The business's window for a date, or null when it is closed. ['', '']
     * when the profile gives no hours for the day.
     *
     * @return array{0:string,1:string}|null
     */
    public function businessWindow(string $date): ?array
    {
        if (! array_key_exists($date, $this->windows)) {
            $this->windows[$date] = $this->engine->businessWindow($date);
        }

        return $this->windows[$date];
    }

    /**
     * The business's hours for a date, for display: its window, or the
     * defaults when the profile gives none or the day is closed.
     *
     * @return array{startTime:string,endTime:string,breakStart:?string,breakEnd:?string,breaks:list<array{start:string,end:string}>,source:string,isActive:bool,location_id:?int}
     */
    public function businessHours(string $date): array
    {
        $window = $this->businessWindow($date);
        $open   = $window !== null && $window[0] !== '' && $window[1] !== '';

        return [
            'startTime'   => $open ? $window[0] : self::DEFAULT_START,
            'endTime'     => $open ? $window[1] : self::DEFAULT_END,
            'breakStart'  => null,
            'breakEnd'    => null,
            'breaks'      => [],
            'source'      => $window === null ? 'closed' : 'business_hours',
            'isActive'    => $window !== null,
            'location_id' => null,
        ];
    }

    /**
     * One person's working hours on a date. Provider 0 is the placeholder
     * column shown when there is nobody, and works the business's hours.
     *
     * @return array{startTime:string,endTime:string,breakStart:?string,breakEnd:?string,breaks:list<array{start:string,end:string}>,source:string,isActive:bool,location_id:?int}
     */
    public function workingHours(int $providerId, string $date): array
    {
        $business = $this->businessHours($date);
        if ($providerId === 0) {
            return $business;
        }

        // Only periods whose place is open that day: a branch period counts
        // on a day the main address is closed, as the engine books it (T29).
        $row = $this->openPeriods($providerId, $date);
        if ($row === []) {
            return ['isActive' => false] + $business;
        }

        // The day from its first start to its last end; the gaps between
        // periods (another place, or lunch) show as breaks, like a break.
        usort($row, static fn (array $a, array $b): int => strcmp((string) $a['start_time'], (string) $b['start_time']));
        $breaks = [];
        foreach ($row as $i => $period) {
            foreach (json_decode((string) $period['breaks'], true) ?: [] as $b) {
                $breaks[] = ['start' => (string) $b['start'], 'end' => (string) $b['end']];
            }
            if ($i > 0 && substr((string) $row[$i - 1]['end_time'], 0, 5) < substr((string) $period['start_time'], 0, 5)) {
                $breaks[] = ['start' => substr((string) $row[$i - 1]['end_time'], 0, 5), 'end' => substr((string) $period['start_time'], 0, 5)];
            }
        }
        usort($breaks, static fn (array $a, array $b): int => strcmp($a['start'], $b['start']));
        $first = $row[0];

        return [
            'startTime'   => substr((string) $first['start_time'], 0, 5),
            'endTime'     => substr((string) max(array_column($row, 'end_time')), 0, 5),
            'breakStart'  => $breaks[0]['start'] ?? null,
            'breakEnd'    => $breaks[0]['end'] ?? null,
            'breaks'      => $breaks,
            'source'      => 'provider_hours',
            'isActive'    => true,
            'location_id' => $first['location_id'] !== null ? (int) $first['location_id'] : null,
        ];
    }

    /**
     * Whether a weekday (1 Monday to 7 Sunday) is ever a working day: the
     * profile or a branch opens that weekday, or one of these people has
     * hours on it. The week view leaves the others out (owner, 6 Nov 2026:
     * no Saturday and Sunday columns for a practice closed at weekends).
     *
     * @param list<int> $providerIds
     */
    public function weekdayUsed(int $weekday, array $providerIds): bool
    {
        $this->load($providerIds);
        foreach ($providerIds as $id) {
            if (isset($this->rows[$id][$weekday])) {
                return true;
            }
        }
        if ($this->placeWeeks === null) {
            helper('directory_hours');
            $this->placeWeeks = [hours_decode(is_array($this->listing['trading_hours'] ?? null) ? json_encode($this->listing['trading_hours']) : ($this->listing['trading_hours'] ?? null))];
            foreach ((new \App\Models\DirectoryPracticeLocationModel())->forListing($this->listingId) as $branch) {
                $this->placeWeeks[] = hours_decode($branch['trading_hours'] ?? null);
            }
        }
        $key = \App\Services\Bookings\TeamService::PROFILE_DAY_KEYS[$weekday];
        foreach ($this->placeWeeks as $week) {
            $day = $week[$key] ?? null;
            if (is_array($day) && ! $day['closed'] && $day['open'] !== '') {
                return true;
            }
        }

        return false;
    }

    /** Whether anyone of these people has a period that day at a place that is open. */
    public function anyoneWorks(string $date, array $providerIds): bool
    {
        $this->load($providerIds);
        foreach ($providerIds as $id) {
            if ($this->openPeriods($id, $date) !== []) {
                return true;
            }
        }

        return false;
    }

    /**
     * A person's periods on a date, in time order, leaving out those at a
     * place that is closed then (the profile's hours or a branch's, and
     * public holidays).
     *
     * @return list<array<string,mixed>>
     */
    private function openPeriods(int $providerId, string $date): array
    {
        $this->load([$providerId]);
        $weekday = (int) (new \DateTimeImmutable($date, $this->range->timezone()))->format('N');
        $open    = [];
        foreach ($this->rows[$providerId][$weekday] ?? [] as $period) {
            $location = $period['location_id'] !== null ? (int) $period['location_id'] : null;
            $key      = $location === null ? $date : $date . '@' . $location;
            if (! array_key_exists($key, $this->windows)) {
                $this->windows[$key] = $this->engine->businessWindow($date, $location);
            }
            if ($this->windows[$key] !== null) {
                $open[] = $period;
            }
        }

        return $open;
    }

    /**
     * The grid for some dates, people and appointments (local `start` and
     * `end` ISO strings).
     *
     * @param list<string>              $dates
     * @param list<int>                 $providerIds
     * @param list<array<string,mixed>> $events
     */
    public function grid(array $dates, array $providerIds, array $events): TimeGrid
    {
        $this->load($providerIds);
        $start = null;
        $end   = null;
        $widen = static function (?string $from, ?string $to) use (&$start, &$end): void {
            if ($from === null || $to === null || $from === '' || $to === '') {
                return;
            }
            $s     = CalendarRange::minutes($from);
            $e     = CalendarRange::minutes($to);
            $start = $start === null ? $s : min($start, $s);
            $end   = $end === null ? $e : max($end, $e);
        };

        foreach ($dates as $date) {
            $window = $this->businessWindow($date);
            if ($window !== null) {
                $widen($window[0], $window[1]);
            }
            foreach ($providerIds as $id) {
                $hours = $this->workingHours($id, $date);
                if ($hours['source'] === 'provider_hours') {
                    $widen($hours['startTime'], $hours['endTime']);
                }
            }
        }

        $tz = $this->range->timezone();
        foreach ($events as $event) {
            $s       = (new \DateTimeImmutable((string) $event['start']))->setTimezone($tz);
            $e       = (new \DateTimeImmutable((string) $event['end']))->setTimezone($tz);
            $endTime = $e->format('Y-m-d') > $s->format('Y-m-d') ? '24:00' : $e->format('H:i');
            $widen($s->format('H:i'), $endTime);
        }

        $start ??= CalendarRange::minutes(self::DEFAULT_START);
        $end   ??= CalendarRange::minutes(self::DEFAULT_END);
        $start = intdiv($start, 60) * 60;
        $end   = min(1440, (int) ceil($end / 60) * 60);
        if ($end <= $start) {
            $end = min(1440, $start + 60);
        }

        return new TimeGrid(
            $this->range,
            sprintf('%02d:%02d', intdiv($start, 60), $start % 60),
            sprintf('%02d:%02d', intdiv($end, 60), $end % 60),
            $this->resolution(),
        );
    }

    /** Grid lines every 30 minutes, or 15 when the business books on a finer step. */
    public function resolution(): int
    {
        $step = BookingSettings::for($this->listingId)->int('booking.slot_step');

        return $step > 0 && $step <= 15 ? 15 : 30;
    }
}

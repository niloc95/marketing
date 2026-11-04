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
 *   inactive, when a person had none; so does this, and a day the business
 *   is closed (profile hours or a public holiday) is inactive for everyone.
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

    /** @param array<string,mixed> $listing */
    public function __construct(array $listing, private CalendarRange $range, ?AvailabilityService $engine = null)
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
            $this->rows[(int) $row['provider_id']][(int) $row['weekday']] = $row;
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
        if ($providerId === 0 || ! $business['isActive']) {
            return $business;
        }

        $this->load([$providerId]);
        $weekday = (int) (new \DateTimeImmutable($date, $this->range->timezone()))->format('N');
        $row     = $this->rows[$providerId][$weekday] ?? null;
        if ($row === null) {
            return ['isActive' => false] + $business;
        }

        $breaks = array_values(array_map(
            static fn (array $b): array => ['start' => (string) $b['start'], 'end' => (string) $b['end']],
            json_decode((string) $row['breaks'], true) ?: [],
        ));

        return [
            'startTime'   => substr((string) $row['start_time'], 0, 5),
            'endTime'     => substr((string) $row['end_time'], 0, 5),
            'breakStart'  => $breaks[0]['start'] ?? null,
            'breakEnd'    => $breaks[0]['end'] ?? null,
            'breaks'      => $breaks,
            'source'      => 'provider_hours',
            'isActive'    => true,
            'location_id' => $row['location_id'] !== null ? (int) $row['location_id'] : null,
        ];
    }

    /** Whether anyone of these people works on a date the business is open. */
    public function anyoneWorks(string $date, array $providerIds): bool
    {
        if ($this->businessWindow($date) === null) {
            return false;
        }
        $this->load($providerIds);
        $weekday = (int) (new \DateTimeImmutable($date, $this->range->timezone()))->format('N');
        foreach ($providerIds as $id) {
            if (isset($this->rows[$id][$weekday])) {
                return true;
            }
        }

        return false;
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

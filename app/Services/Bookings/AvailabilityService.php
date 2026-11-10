<?php

namespace App\Services\Bookings;

use App\Models\Bookings\BookingAppointmentModel;
use App\Models\Bookings\BookingBlockedTimeModel;
use DateTimeImmutable;
use DateTimeZone;

/**
 * When someone can be booked: the scheduling engine (docs/bookings/, Phase 3).
 *
 * Ported from Bookings' AvailabilityService and ConflictService. The day is
 * built the same way: the provider's weekly hours, minus breaks, clamped to
 * the business's hours, minus time off and other appointments, cut into
 * times. What differs, on purpose:
 *
 * - **Intervals are half open** ([start, end)). Bookings' dateTimesOverlap()
 *   used <= and >=, so a booking ending at 10:00 hid the 10:00 slot.
 * - **Busy time is anything overlapping the day**, not only appointments
 *   starting on it, so an evening booking running past midnight still counts.
 * - **Gaps are per service** (`buffer_before`, `buffer_after`) plus the
 *   business's buffer after every appointment, and each appointment keeps the
 *   gaps it was booked with (`hold_before_min`, `hold_after_min`). Bookings
 *   stored per service gaps but its engine never read them.
 * - **The business window is the profile's own hours per weekday**, and a
 *   public holiday follows the owner's holiday answer. With no answer the
 *   day takes no online bookings: we cannot say the business is open.
 * - **Minimum notice and the booking horizon** apply to customers (public);
 *   the business itself can book any time a person works.
 * - Everything is computed in the business's time zone and compared as UTC
 *   timestamps, so a daylight saving change (.app countries) cannot shift a
 *   slot.
 *
 * Read only. Creating an appointment goes through BookingLock::reserve(),
 * which asks fits() again inside a lock.
 */
class AvailabilityService
{
    public const PAST            = 'past';
    public const TOO_SOON        = 'too_soon';
    public const TOO_FAR         = 'too_far';
    public const CLOSED          = 'closed';
    public const OUTSIDE_HOURS   = 'outside_hours';
    public const BUSY            = 'busy';
    public const NOT_OFFERED     = 'not_offered';
    public const NOT_ON_THE_GRID = 'not_on_the_grid';

    private int $listingId;

    private DateTimeZone $tz;

    private DateTimeImmutable $now;

    private BookingSettings $settings;

    private CatalogueService $catalog;

    private TeamService $team;

    /** @var array<string,mixed> */
    private array $cache = [];

    /** Remember hours, busy time and opening hours per day (remember()). */
    private bool $remember = false;

    /**
     * @param array<string,mixed> $listing the profile (trading_hours, timezone, holidays)
     */
    public function __construct(private array $listing, ?DateTimeImmutable $now = null)
    {
        helper('directory_hours'); // hours_decode(), holidays: not autoloaded
        $this->listingId = (int) $listing['id'];
        $this->tz        = \App\Services\Bookings\BusinessLocale::zone($listing);
        $this->now       = ($now ?? new DateTimeImmutable('now'))->setTimezone($this->tz);
        $this->settings  = BookingSettings::for($this->listingId);
        $this->catalog   = new CatalogueService($this->listingId);
        $this->team      = new TeamService($this->listingId);
    }

    /**
     * Keep each person's hours, busy time and each place's window in memory
     * for the life of this object: for read only pages that ask about many
     * days (the availability overview, a year of them). Never for writes:
     * BookingLock builds its own engine inside the lock.
     */
    public function remember(): static
    {
        $this->remember = true;

        return $this;
    }

    /** The last local date customers may book (`business.future_limit` days ahead). */
    public function customerHorizon(): string
    {
        return $this->now->modify('+' . $this->settings->int('business.future_limit') . ' days')->format('Y-m-d');
    }

    /** Whether a person works at all that day (for that service): a period, inside its place's hours. */
    public function works(int $providerId, string $date, ?int $serviceId = null): bool
    {
        return $this->validDate($date) && $this->windows($providerId, $date, $serviceId) !== [];
    }

    public function timezone(): DateTimeZone
    {
        return $this->tz;
    }

    /**
     * Free start times for one provider, service and local date.
     *
     * @return list<array{start:string,end:string,time:string,location_id:?int,provider_id:int}>
     *         start and end in UTC (Y-m-d H:i:s), time the local HH:MM
     */
    public function slots(int $providerId, int $serviceId, string $date, bool $public = true, ?int $excludeAppointmentId = null): array
    {
        $service = $this->bookable($providerId, $serviceId, $public, $excludeAppointmentId !== null);
        if ($service === null || ! $this->validDate($date) || ($public && $this->dateProblem($date) !== null)) {
            return [];
        }

        $duration = (int) $service['duration_min'] * 60;
        [$before, $after] = $this->holds($service);
        $step     = ($this->settings->int('booking.slot_step') ?: (int) $service['duration_min']) * 60;
        $earliest = $public ? $this->earliestStart() : PHP_INT_MIN;
        $busy     = $this->busy($providerId, $date, $excludeAppointmentId);
        $out      = [];

        foreach ($this->windows($providerId, $date, $serviceId) as $w) {
            // The gaps must fit inside working time too: setting up before the
            // first booking and clearing up after the last are work.
            for ($t = $w['start'] + $before; $t + $duration + $after <= $w['end']; $t += $step) {
                if ($t < $earliest || $this->overlapsAny($t - $before, $t + $duration + $after, $busy)) {
                    continue;
                }
                $out[] = $this->slot($t, $t + $duration, $w['location_id'], $providerId);
            }
        }

        return $out;
    }

    /**
     * Free start times for a service with anyone who offers it ("anyone
     * available"). Each time lists every provider free then, in team order.
     *
     * @return list<array{start:string,end:string,time:string,provider_ids:list<int>}>
     */
    public function slotsForAnyone(int $serviceId, string $date, bool $public = true): array
    {
        $byStart = [];
        foreach ($this->providersFor($serviceId, $public) as $providerId) {
            foreach ($this->slots($providerId, $serviceId, $date, $public) as $slot) {
                $byStart[$slot['start']] ??= ['start' => $slot['start'], 'end' => $slot['end'], 'time' => $slot['time'], 'provider_ids' => []];
                $byStart[$slot['start']]['provider_ids'][] = $providerId;
            }
        }
        ksort($byStart);

        return array_values($byStart);
    }

    /**
     * Why an exact start time cannot be booked, or null when it can. The
     * business may book any minute inside working time; customers only the
     * times slots() offers.
     */
    public function problem(int $providerId, int $serviceId, string $startUtc, bool $public = true, ?int $excludeAppointmentId = null): ?string
    {
        $service = $this->bookable($providerId, $serviceId, $public, $excludeAppointmentId !== null);
        if ($service === null) {
            return self::NOT_OFFERED;
        }
        $start = (new DateTimeImmutable($startUtc, new DateTimeZone('UTC')))->getTimestamp();
        $local = (new DateTimeImmutable('@' . $start))->setTimezone($this->tz);
        $date  = $local->format('Y-m-d');

        if ($public) {
            if ($start < $this->now->getTimestamp()) {
                return self::PAST;
            }
            if ($start < $this->earliestStart()) {
                return self::TOO_SOON;
            }
            if (($dateProblem = $this->dateProblem($date)) !== null) {
                return $dateProblem;
            }
            $times = array_column($this->slots($providerId, $serviceId, $date, true, $excludeAppointmentId), 'start');

            return in_array(gmdate('Y-m-d H:i:s', $start), $times, true)
                ? null
                : ($this->whyNotFree($providerId, $service, $start, $date, $excludeAppointmentId) ?? self::NOT_ON_THE_GRID);
        }

        return $this->whyNotFree($providerId, $service, $start, $date, $excludeAppointmentId);
    }

    /**
     * The first free time from a local date on, within $days days.
     *
     * @return array{date:string,start:string,end:string,time:string,provider_id:int}|null
     */
    public function nextAvailable(int $serviceId, ?int $providerId, ?string $fromDate = null, int $days = 60, ?int $moving = null): ?array
    {
        $date = $fromDate ?? $this->now->format('Y-m-d');
        for ($i = 0; $i < $days; $i++) {
            $day   = (new DateTimeImmutable($date, $this->tz))->modify('+' . $i . ' day')->format('Y-m-d');
            if ($this->dateProblem($day) === self::TOO_FAR) {
                return null;
            }
            $slots = $providerId !== null
                ? $this->slots($providerId, $serviceId, $day, true, $moving)
                : array_map(static fn (array $s): array => $s + ['provider_id' => $s['provider_ids'][0]], $this->slotsForAnyone($serviceId, $day));
            if ($slots !== []) {
                return ['date' => $day, 'start' => $slots[0]['start'], 'end' => $slots[0]['end'], 'time' => $slots[0]['time'], 'provider_id' => (int) $slots[0]['provider_id']];
            }
        }

        return null;
    }

    /**
     * Local dates between two dates (inclusive) with at least one free time,
     * for the booking calendar.
     *
     * @return list<string>
     */
    public function availableDates(int $serviceId, ?int $providerId, string $from, string $to, ?int $moving = null): array
    {
        $out  = [];
        $day  = new DateTimeImmutable($from, $this->tz);
        $last = new DateTimeImmutable($to, $this->tz);
        for ($i = 0; $day <= $last && $i < 62; $i++, $day = $day->modify('+1 day')) {
            $d     = $day->format('Y-m-d');
            $slots = $providerId !== null ? $this->slots($providerId, $serviceId, $d, true, $moving) : $this->slotsForAnyone($serviceId, $d);
            if ($slots !== []) {
                $out[] = $d;
            }
        }

        return $out;
    }

    /**
     * The business's own window for a local date: null when closed, ['', '']
     * when its profile gives no hours for the day (people's hours decide), or
     * open and close as HH:MM. With a branch id, that branch's own hours and
     * holiday answer (T29: a person may work at a branch on a day the main
     * address is closed, or for shorter hours).
     *
     * @return array{0:string,1:string}|null
     */
    public function businessWindow(string $date, ?int $locationId = null): ?array
    {
        if ($this->remember) {
            $key = 'win' . $date . '|' . $locationId;
            if (! array_key_exists($key, $this->cache)) {
                $this->remember = false;
                $this->cache[$key] = $this->businessWindow($date, $locationId);
                $this->remember = true;
            }

            return $this->cache[$key];
        }
        $raw     = $this->placeHours($locationId);
        $hours   = is_array($raw) ? $raw : hours_decode($raw);
        $weekday = hours_weekday_row($hours, $date);
        $holiday = hours_within($this->listing, static fn () => hours_calendar()->on($date));

        if ($holiday !== null) {
            $answer = hours_holiday_answer(hours_holiday_rule(is_array($raw) ? json_encode($raw) : $raw), (string) $holiday['key'], $weekday);
            if ($answer === null || $answer['state'] === 'closed') {
                return null;
            }

            return [(string) $answer['open'], (string) $answer['close']];
        }

        if ($weekday === null) {
            return ['', ''];
        }
        if ($weekday['closed']) {
            return null;
        }

        return [(string) $weekday['open'], (string) $weekday['close']];
    }

    /**
     * A provider's usual week as the profile shows it: weekday 1 to 7 =>
     * working periods as HH:MM, after the same clamp to the profile's hours
     * and the same breaks as windows(), so the profile never shows a time
     * the engine would not book. Holidays and time off are left out: this is
     * the usual week, not a calendar.
     *
     * @return array<int,list<array{start:string,end:string,location_id:?int}>>
     */
    public function weekPeriods(int $providerId): array
    {
        $rows = $this->providerRows($providerId);
        $week = [];
        foreach ($rows as $row) {
            $day      = (int) $row['weekday'];
            $location = $row['location_id'] !== null ? (int) $row['location_id'] : null;
            $raw      = $this->placeHours($location);
            $hours    = is_array($raw) ? $raw : hours_decode($raw);
            $place    = $hours[TeamService::PROFILE_DAY_KEYS[$day] ?? ''] ?? null;
            if (is_array($place) && $place['closed']) {
                continue;
            }
            $business = is_array($place) ? [(string) $place['open'], (string) $place['close']] : ['', ''];
            $ids      = $row['service_ids'] !== null ? array_map('intval', json_decode((string) $row['service_ids'], true) ?: []) : null;
            foreach (self::periods($row, $business) as $p) {
                $week[$day][] = ['start' => $p[0], 'end' => $p[1], 'location_id' => $location, 'service_ids' => $ids];
            }
        }

        return $week;
    }

    /**
     * Where an appointment starting then takes place: the place of the
     * person's period that holds it (T29), or null for the profile's own
     * address.
     */
    public function locationAt(int $providerId, ?int $serviceId, string $startUtc): ?int
    {
        $start = $this->utc($startUtc);
        $date  = (new DateTimeImmutable('@' . $start))->setTimezone($this->tz)->format('Y-m-d');
        foreach ($this->windows($providerId, $date, $serviceId) as $w) {
            if ($start >= $w['start'] && $start < $w['end']) {
                return $w['location_id'];
            }
        }

        return null;
    }

    /**
     * One day of a provider's hours row as HH:MM periods: start to end,
     * clamped to the business window (['', ''] = no window), minus breaks.
     * HH:MM strings sort as times, so this needs no clock.
     *
     * @param array<string,mixed>      $row      a BookingProviderHoursModel row
     * @param array{0:string,1:string} $business
     *
     * @return list<array{0:string,1:string}>
     */
    public static function periods(array $row, array $business): array
    {
        $start = substr((string) $row['start_time'], 0, 5);
        $end   = substr((string) $row['end_time'], 0, 5);
        if ($business[0] !== '' && $business[1] !== '') {
            $start = max($start, $business[0]);
            $end   = min($end, $business[1]);
        }
        if ($start >= $end) {
            return [];
        }

        $segments = [[$start, $end]];
        foreach (json_decode((string) $row['breaks'], true) ?: [] as $break) {
            $bs   = (string) $break['start'];
            $be   = (string) $break['end'];
            $next = [];
            foreach ($segments as [$s, $e]) {
                if ($be <= $s || $bs >= $e) {
                    $next[] = [$s, $e];

                    continue;
                }
                if ($bs > $s) {
                    $next[] = [$s, $bs];
                }
                if ($be < $e) {
                    $next[] = [$be, $e];
                }
            }
            $segments = $next;
        }

        return $segments;
    }

    // Internals

    /**
     * Working periods of a provider on a local date, as timestamps: each of
     * their periods that day (T29) offering the service, minus breaks,
     * clamped to the window of the place it is at (periods()).
     *
     * @return list<array{start:int,end:int,location_id:?int}>
     */
    private function windows(int $providerId, string $date, ?int $serviceId = null): array
    {
        $weekday = (int) (new DateTimeImmutable($date, $this->tz))->format('N');
        $rows    = array_values(array_filter($this->providerRows($providerId), static fn (array $r): bool => (int) $r['weekday'] === $weekday));
        $out = [];
        foreach ($rows as $row) {
            if ($serviceId !== null && $row['service_ids'] !== null
                && ! in_array($serviceId, array_map('intval', json_decode((string) $row['service_ids'], true) ?: []), true)) {
                continue;
            }
            $location = $row['location_id'] !== null ? (int) $row['location_id'] : null;
            $business = $this->businessWindow($date, $location);
            if ($business === null) {
                continue;
            }
            foreach (self::periods($row, $business) as $seg) {
                $out[] = ['start' => $this->at($date, $seg[0]), 'end' => $this->at($date, $seg[1]), 'location_id' => $location];
            }
        }

        return $out;
    }

    /**
     * A person's weekly rows (ProviderWeek: their own, or the profile's
     * opening hours), in weekday and start order. Cached while remembering.
     *
     * @return list<array<string,mixed>>
     */
    private function providerRows(int $providerId): array
    {
        if ($this->remember) {
            return $this->cache['hours' . $providerId] ??= ProviderWeek::forProvider($this->listingId, $providerId, $this->placeHours(null));
        }

        return ProviderWeek::forProvider($this->listingId, $providerId, $this->placeHours(null));
    }

    /**
     * The opening hours of a place: the profile's, or a branch's (null when
     * the branch gave none, so people's hours decide there). Cached.
     *
     * @return array<string,mixed>|string|null decoded on the profile page, JSON otherwise
     */
    private function placeHours(?int $locationId): array|string|null
    {
        if ($locationId === null) {
            return $this->listing['trading_hours'] ?? null;
        }

        if (! array_key_exists('place' . $locationId, $this->cache)) {
            $row = (new \App\Models\DirectoryPracticeLocationModel())->where('listing_id', $this->listingId)->find($locationId);
            $this->cache['place' . $locationId] = is_array($row) ? ($row['trading_hours'] ?? null) : null;
        }

        return $this->cache['place' . $locationId];
    }

    /**
     * Busy periods touching a local date, as timestamps: appointments with
     * the gaps they were booked with, and time off for the provider or the
     * whole business.
     *
     * @return list<array{0:int,1:int}>
     */
    private function busy(int $providerId, string $date, ?int $excludeAppointmentId): array
    {
        if ($this->remember && $excludeAppointmentId === null) {
            $key = 'busy' . $providerId . '|' . $date;
            if (! array_key_exists($key, $this->cache)) {
                $this->remember = false;
                $this->cache[$key] = $this->busy($providerId, $date, null);
                $this->remember = true;
            }

            return $this->cache[$key];
        }
        // A day either side catches long appointments and long gaps.
        $from = gmdate('Y-m-d H:i:s', $this->at($date, '00:00') - DAY);
        $to   = gmdate('Y-m-d H:i:s', $this->at($date, '00:00') + 2 * DAY);
        $out  = [];

        $appointments = BookingAppointmentModel::forBusiness($this->listingId)
            ->where('provider_id', $providerId)
            ->whereIn('status', BookingAppointmentModel::HOLDS_TIME)
            ->where('start_at <', $to)
            ->where('end_at >', $from);
        if ($excludeAppointmentId !== null) {
            $appointments->where('id !=', $excludeAppointmentId);
        }
        foreach ($appointments->findAll() as $a) {
            $out[] = [
                $this->utc((string) $a['start_at']) - (int) $a['hold_before_min'] * 60,
                $this->utc((string) $a['end_at']) + (int) $a['hold_after_min'] * 60,
            ];
        }

        $blocked = BookingBlockedTimeModel::forBusiness($this->listingId)
            ->groupStart()->where('provider_id', $providerId)->orWhere('provider_id', null)->groupEnd()
            ->where('start_at <', $to)
            ->where('end_at >', $from)
            ->findAll();
        foreach ($blocked as $b) {
            $out[] = [$this->utc((string) $b['start_at']), $this->utc((string) $b['end_at'])];
        }

        return $out;
    }

    /** @param list<array{0:int,1:int}> $busy */
    private function overlapsAny(int $start, int $end, array $busy): bool
    {
        foreach ($busy as [$bs, $be]) {
            if ($start < $be && $end > $bs) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string,mixed> $service */
    private function whyNotFree(int $providerId, array $service, int $start, string $date, ?int $excludeAppointmentId): ?string
    {
        $windows = $this->windows($providerId, $date, (int) $service['id']);
        if ($windows === [] && $this->businessWindow($date) === null) {
            return self::CLOSED;
        }
        $duration = (int) $service['duration_min'] * 60;
        [$before, $after] = $this->holds($service);
        $inWindow = false;
        foreach ($windows as $w) {
            if ($start - $before >= $w['start'] && $start + $duration + $after <= $w['end']) {
                $inWindow = true;

                break;
            }
        }
        if (! $inWindow) {
            return self::OUTSIDE_HOURS;
        }
        if ($this->overlapsAny($start - $before, $start + $duration + $after, $this->busy($providerId, $date, $excludeAppointmentId))) {
            return self::BUSY;
        }

        return null;
    }

    /**
     * The service when this provider offers it and both can be booked.
     * Customers cannot book a person marked not bookable; the business can.
     *
     * @return array<string,mixed>|null
     */
    private function bookable(int $providerId, int $serviceId, bool $public, bool $moving = false): ?array
    {
        $provider = $this->cache['p' . $providerId] ??= $this->team->provider($providerId) ?? false;
        $service  = $this->cache['s' . $serviceId] ??= $this->catalog->service($serviceId) ?? false;
        if ($provider === false || $service === false || $provider['archived_at'] !== null || $service['archived_at'] !== null) {
            return null;
        }
        // Customers cannot book a person marked not bookable, nor a new
        // booking of a service whose duration nobody has confirmed yet (T29);
        // moving a booking they already have is fine. The business can.
        if ($public && (! (int) $provider['bookable'] || (! $moving && ! (int) $service['length_checked']))) {
            return null;
        }

        return in_array($providerId, $service['provider_ids'], true) ? $service : null;
    }

    /** @return list<int> */
    private function providersFor(int $serviceId, bool $public): array
    {
        $service = $this->catalog->service($serviceId);
        if ($service === null || $service['archived_at'] !== null) {
            return [];
        }
        $ids = [];
        foreach ($this->team->providers() as $p) {
            if (in_array((int) $p['id'], $service['provider_ids'], true) && (! $public || (int) $p['bookable'])) {
                $ids[] = (int) $p['id'];
            }
        }

        return $ids;
    }

    /** @param array<string,mixed> $service @return array{0:int,1:int} seconds before and after */
    private function holds(array $service): array
    {
        return [
            (int) $service['buffer_before'] * 60,
            ((int) $service['buffer_after'] + $this->settings->int('business.buffer_time')) * 60,
        ];
    }

    /** The gaps a new appointment for this service keeps, in minutes. @return array{0:int,1:int} */
    public function holdMinutes(int $serviceId): array
    {
        $service = $this->catalog->service($serviceId);
        if ($service === null) {
            return [0, 0];
        }
        [$before, $after] = $this->holds($service);

        return [intdiv($before, 60), intdiv($after, 60)];
    }

    private function earliestStart(): int
    {
        return $this->now->getTimestamp() + $this->settings->int('booking.lead_minutes') * 60;
    }

    /** Past, or beyond the horizon customers may book. */
    private function dateProblem(string $date): ?string
    {
        $today = $this->now->format('Y-m-d');
        if ($date < $today) {
            return self::PAST;
        }
        $limit = $this->now->modify('+' . $this->settings->int('business.future_limit') . ' days')->format('Y-m-d');

        return $date > $limit ? self::TOO_FAR : null;
    }

    private function validDate(string $date): bool
    {
        $d = DateTimeImmutable::createFromFormat('!Y-m-d', $date, $this->tz);

        return $d !== false && $d->format('Y-m-d') === $date;
    }

    /** A local date and HH:MM as a timestamp. */
    private function at(string $date, string $time): int
    {
        return (new DateTimeImmutable($date . ' ' . $time, $this->tz))->getTimestamp();
    }

    private function utc(string $datetime): int
    {
        return (new DateTimeImmutable($datetime, new DateTimeZone('UTC')))->getTimestamp();
    }

    /** @return array{start:string,end:string,time:string,location_id:?int,provider_id:int} */
    private function slot(int $start, int $end, ?int $locationId, int $providerId): array
    {
        return [
            'start'       => gmdate('Y-m-d H:i:s', $start),
            'end'         => gmdate('Y-m-d H:i:s', $end),
            'time'        => (new DateTimeImmutable('@' . $start))->setTimezone($this->tz)->format('H:i'),
            'location_id' => $locationId,
            'provider_id' => $providerId,
        ];
    }
}

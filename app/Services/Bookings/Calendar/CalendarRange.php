<?php

namespace App\Services\Bookings\Calendar;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Date structure for the calendar views: the 42 cell month grid, the seven
 * day week and a day's time slots. No queries. Ported from Bookings'
 * CalendarRangeService; the time zone and first day of the week come from the
 * business (profile timezone, `localization.first_day`) instead of global
 * settings, and "today" comes from an injectable clock.
 */
class CalendarRange
{
    public const DAY_NAMES = [
        0 => 'sunday', 1 => 'monday', 2 => 'tuesday', 3 => 'wednesday',
        4 => 'thursday', 5 => 'friday', 6 => 'saturday',
    ];

    /** Height of one minute on the time grid. */
    public const PIXELS_PER_MINUTE = 2;

    private DateTimeImmutable $today;

    /**
     * @param int  $firstDayOfWeek 0 Sunday, 1 Monday
     * @param bool $twelveHour     `localization.time_format` = 12h
     */
    public function __construct(
        private DateTimeZone $tz,
        private int $firstDayOfWeek = 1,
        ?DateTimeImmutable $now = null,
        private bool $twelveHour = false,
    ) {
        $this->today = ($now ?? new DateTimeImmutable('now'))->setTimezone($tz)->setTime(0, 0);
    }

    public function timezone(): DateTimeZone
    {
        return $this->tz;
    }

    public function today(): string
    {
        return $this->today->format('Y-m-d');
    }

    /**
     * Always six weeks of seven days, starting on the first day of the week
     * on or before the 1st.
     *
     * @return array{year:int,month:int,monthName:string,startDate:string,endDate:string,weeks:list<list<array<string,mixed>>>}
     */
    public function monthGrid(int $year, int $month): array
    {
        $first   = new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month), $this->tz);
        $current = $this->startOfWeek($first);
        $start   = $current;
        $weeks   = [];

        for ($w = 0; $w < 6; $w++) {
            $row = [];
            for ($d = 0; $d < 7; $d++) {
                $weekday = (int) $current->format('w');
                $date    = $current->format('Y-m-d');
                $row[]   = [
                    'date'             => $date,
                    'dayNumber'        => (int) $current->format('j'),
                    'weekday'          => $weekday,
                    'weekdayName'      => self::DAY_NAMES[$weekday],
                    'week'             => (int) $current->format('W'),
                    'isCurrentMonth'   => (int) $current->format('n') === $month,
                    'isToday'          => $date === $this->today(),
                    'isPast'           => $date < $this->today(),
                    'isFuture'         => $date > $this->today(),
                    'appointments'     => [],
                    'appointmentCount' => 0,
                    'hasAvailability'  => false,
                ];
                $current = $current->modify('+1 day');
            }
            $weeks[] = $row;
        }

        return [
            'year'      => $year,
            'month'     => $month,
            'monthName' => $first->format('F'),
            'startDate' => $start->format('Y-m-d'),
            'endDate'   => $current->modify('-1 day')->format('Y-m-d'),
            'weeks'     => $weeks,
        ];
    }

    /**
     * The seven days of the week holding $date.
     *
     * @return array{startDate:string,endDate:string,days:list<array<string,mixed>>}
     */
    public function weekRange(string $date): array
    {
        $start = $this->startOfWeek(new DateTimeImmutable($date, $this->tz));
        $days  = [];
        for ($i = 0; $i < 7; $i++) {
            $d       = $start->modify('+' . $i . ' days');
            $weekday = (int) $d->format('w');
            $ymd     = $d->format('Y-m-d');
            $days[]  = [
                'date'         => $ymd,
                'dayNumber'    => (int) $d->format('j'),
                'monthName'    => $d->format('M'),
                'fullDate'     => $d->format('D j M'),
                'weekday'      => $weekday,
                'weekdayName'  => self::DAY_NAMES[$weekday],
                'isToday'      => $ymd === $this->today(),
                'isPast'       => $ymd < $this->today(),
                'appointments' => [],
            ];
        }

        return [
            'startDate' => $start->format('Y-m-d'),
            'endDate'   => $start->modify('+6 days')->format('Y-m-d'),
            'days'      => $days,
        ];
    }

    /**
     * Time slots for a day grid. $end may be 24:00.
     *
     * @return array{date:string,dayStart:string,dayEnd:string,totalMinutes:int,pixelsPerMinute:int,containerHeight:int,slots:list<array<string,mixed>>}
     */
    public function daySlots(string $date, string $start, string $end, int $resolution): array
    {
        $startMin = self::minutes($start);
        $endMin   = self::minutes($end);
        $slots    = [];
        for ($min = $startMin; $min < $endMin; $min += $resolution) {
            $h       = intdiv($min, 60);
            $m       = $min % 60;
            $slots[] = [
                'time'         => sprintf('%02d:%02d', $h, $m),
                'minutes'      => $min - $startMin,
                'label'        => $this->label($h, $m),
                'isHour'       => $m === 0,
                'isHalf'       => $m === 30,
                'topPx'        => ($min - $startMin) * self::PIXELS_PER_MINUTE,
                'appointments' => [],
            ];
        }

        return [
            'date'            => $date,
            'dayStart'        => $start,
            'dayEnd'          => $end,
            'totalMinutes'    => $endMin - $startMin,
            'pixelsPerMinute' => self::PIXELS_PER_MINUTE,
            'containerHeight' => ($endMin - $startMin) * self::PIXELS_PER_MINUTE,
            'slots'           => $slots,
        ];
    }

    /** @return array{int:int,string:string} weekday as 0 to 6 and its name */
    public function dayOfWeek(string $date): array
    {
        $int = (int) (new DateTimeImmutable($date, $this->tz))->format('w');

        return ['int' => $int, 'string' => self::DAY_NAMES[$int]];
    }

    /** A time of day as the business shows it (24h or 12h). */
    public function label(int $hour, int $minute): string
    {
        if (! $this->twelveHour) {
            return sprintf('%02d:%02d', $hour % 24, $minute);
        }
        $suffix = $hour % 24 < 12 ? 'am' : 'pm';
        $h12    = $hour % 12 === 0 ? 12 : $hour % 12;

        return $minute === 0 ? $h12 . $suffix : sprintf('%d:%02d%s', $h12, $minute, $suffix);
    }

    /** "HH:MM" (or HH:MM:SS) as minutes after midnight; 24:00 is 1440. */
    public static function minutes(string $time): int
    {
        [$h, $m] = array_map('intval', explode(':', $time . ':00'));

        return $h * 60 + $m;
    }

    private function startOfWeek(DateTimeImmutable $day): DateTimeImmutable
    {
        $diff = ((int) $day->format('w') - $this->firstDayOfWeek + 7) % 7;

        return $day->modify('-' . $diff . ' days');
    }
}

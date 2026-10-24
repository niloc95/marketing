<?php

namespace App\Libraries;

use App\Services\DirectorySettings;

/**
 * South Africa's public holidays, worked out in code rather than fetched.
 *
 * The Public Holidays Act 36 of 1994 fixes ten dates; Good Friday and Family
 * Day follow Easter. Section 2(1): when a public holiday falls on a Sunday,
 * the Monday after it is a public holiday too. When that Monday is already
 * one (Christmas on a Sunday puts the Day of Goodwill there), the day moves
 * on to the Tuesday, which is how 27 December 2022 came to be a holiday.
 *
 * The President can declare one off days (election days, a Springbok World
 * Cup win). Those are the admin's list on /admin/settings, read through
 * DirectorySettings::extraHolidays(), and have no key of their own: an owner's
 * exceptions are per national holiday, so a declared day takes the general
 * answer.
 *
 * Every date here is a South African calendar date (Y-m-d). Callers pass dates,
 * never timestamps; hours_now() is what turns "now" into one.
 */
class PublicHolidays
{
    /** Key for a declared one off day; it takes the owner's general answer. */
    public const EXTRA = 'extra';

    /**
     * The national holidays in calendar order: key => name. The keys are what
     * an owner's exceptions are stored under, so they must never change.
     */
    public const NATIONAL = [
        'new-year'       => "New Year's Day",
        'human-rights'   => 'Human Rights Day',
        'good-friday'    => 'Good Friday',
        'family-day'     => 'Family Day',
        'freedom'        => 'Freedom Day',
        'workers'        => "Workers' Day",
        'youth'          => 'Youth Day',
        'womens'         => "National Women's Day",
        'heritage'       => 'Heritage Day',
        'reconciliation' => 'Day of Reconciliation',
        'christmas'      => 'Christmas Day',
        'goodwill'       => 'Day of Goodwill',
    ];

    /** The fixed ones, as month-day. */
    private const FIXED = [
        'new-year'       => '01-01',
        'human-rights'   => '03-21',
        'freedom'        => '04-27',
        'workers'        => '05-01',
        'youth'          => '06-16',
        'womens'         => '08-09',
        'heritage'       => '09-24',
        'reconciliation' => '12-16',
        'christmas'      => '12-25',
        'goodwill'       => '12-26',
    ];

    /** @var list<array{date:string,name:string}> */
    private array $extra;

    /** @var array<int,list<array{date:string,key:string,name:string,observed:bool}>> */
    private array $years = [];

    /**
     * @param list<array{date:string,name:string}>|null $extra declared days;
     *        null reads the admin's list
     */
    public function __construct(?array $extra = null)
    {
        $this->extra = $extra ?? (new DirectorySettings())->extraHolidays();
    }

    /**
     * Every public holiday in $year, in date order, observed Mondays included.
     *
     * @return list<array{date:string,key:string,name:string,observed:bool}>
     */
    public function forYear(int $year): array
    {
        if (isset($this->years[$year])) {
            return $this->years[$year];
        }

        $easter = self::easterSunday($year);
        $days   = [];
        foreach (self::FIXED as $key => $md) {
            $days[$year . '-' . $md] = ['key' => $key, 'name' => self::NATIONAL[$key], 'observed' => false];
        }
        $days[$easter->modify('-2 days')->format('Y-m-d')] = ['key' => 'good-friday', 'name' => self::NATIONAL['good-friday'], 'observed' => false];
        $days[$easter->modify('+1 day')->format('Y-m-d')]  = ['key' => 'family-day', 'name' => self::NATIONAL['family-day'], 'observed' => false];

        foreach ($this->extra as $row) {
            if (str_starts_with($row['date'], $year . '-') && ! isset($days[$row['date']])) {
                $days[$row['date']] = ['key' => self::EXTRA, 'name' => $row['name'], 'observed' => false];
            }
        }

        // Section 2(1): a Sunday holiday also makes the next free weekday one.
        // In date order, so a moved day can never land on one placed later.
        ksort($days);
        foreach ($days as $date => $day) {
            if ($day['observed'] || (new \DateTimeImmutable($date))->format('N') !== '7') {
                continue;
            }
            $next = new \DateTimeImmutable($date . ' +1 day');
            while (isset($days[$next->format('Y-m-d')])) {
                $next = $next->modify('+1 day');
            }
            $days[$next->format('Y-m-d')] = ['key' => $day['key'], 'name' => $day['name'], 'observed' => true];
            ksort($days);
        }

        $out = [];
        foreach ($days as $date => $day) {
            $out[] = ['date' => $date] + $day;
        }

        return $this->years[$year] = $out;
    }

    /** @return array{date:string,key:string,name:string,observed:bool}|null */
    public function on(string $date): ?array
    {
        foreach ($this->forYear((int) substr($date, 0, 4)) as $day) {
            if ($day['date'] === $date) {
                return $day;
            }
        }

        return null;
    }

    /**
     * Holidays from $from to $to, both inclusive.
     *
     * @return list<array{date:string,key:string,name:string,observed:bool}>
     */
    public function between(string $from, string $to): array
    {
        $out = [];
        for ($y = (int) substr($from, 0, 4); $y <= (int) substr($to, 0, 4); $y++) {
            foreach ($this->forYear($y) as $day) {
                if ($day['date'] >= $from && $day['date'] <= $to) {
                    $out[] = $day;
                }
            }
        }

        return $out;
    }

    /** The first holiday on or after $from, within a year. */
    public function next(string $from): ?array
    {
        return $this->between($from, date('Y-m-d', strtotime($from . ' +1 year')))[0] ?? null;
    }

    /**
     * Every holiday an owner can give its own answer for, key => name, in
     * calendar order. South Africa's are NATIONAL; other calendars
     * (YasumiHolidays) answer with their own, which is why callers ask the
     * calendar instead of reading the constant.
     *
     * @return array<string,string>
     */
    public function named(): array
    {
        return self::NATIONAL;
    }

    /**
     * "Heritage Day", or "Public holiday for Christmas Day" for a moved one, as
     * the Government Gazette words it.
     */
    public static function label(array $day): string
    {
        return ! empty($day['observed']) ? 'Public holiday for ' . $day['name'] : $day['name'];
    }

    /**
     * Read the admin's one off days: one per line, "2026-11-01 Local government
     * elections". Blank lines are skipped; anything else unreadable is an error
     * naming the line, so nothing is half saved.
     *
     * @return array{days:list<array{date:string,name:string}>,errors:list<string>}
     */
    public static function parseExtra(string $text): array
    {
        $days   = [];
        $errors = [];
        foreach (preg_split('/\R/', $text) ?: [] as $i => $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            if (preg_match('/^(\d{4}-\d{2}-\d{2})\s+(.{3,80})$/u', $line, $m) !== 1
                || \DateTimeImmutable::createFromFormat('!Y-m-d', $m[1])?->format('Y-m-d') !== $m[1]) {
                $errors[] = 'Line ' . ($i + 1) . ': write it as 2026-11-01 Local government elections.';

                continue;
            }
            $days[$m[1]] = ['date' => $m[1], 'name' => trim(strip_tags($m[2]))];
        }
        ksort($days);

        return ['days' => array_values($days), 'errors' => $errors];
    }

    /**
     * Easter Sunday by the anonymous Gregorian algorithm, so the calendar
     * extension (easter_date()) is not a server requirement.
     */
    public static function easterSunday(int $year): \DateTimeImmutable
    {
        $a = $year % 19;
        $b = intdiv($year, 100);
        $c = $year % 100;
        $d = intdiv($b, 4);
        $e = $b % 4;
        $f = intdiv($b + 8, 25);
        $g = intdiv($b - $f + 1, 3);
        $h = (19 * $a + $b - $d - $g + 15) % 30;
        $i = intdiv($c, 4);
        $k = $c % 4;
        $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
        $m = intdiv($a + 11 * $h + 22 * $l, 451);

        $month = intdiv($h + $l - 7 * $m + 114, 31);
        $day   = (($h + $l - 7 * $m + 114) % 31) + 1;

        return new \DateTimeImmutable(sprintf('%04d-%02d-%02d', $year, $month, $day));
    }
}

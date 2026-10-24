<?php

/** Per-day free-text note ceiling. See hours_encode(). */
defined('HOURS_NOTE_MAX') || define('HOURS_NOTE_MAX', 120);

if (! function_exists('hours_days')) {
    /**
     * Canonical day order/labels, keyed mon..sun. Single source of truth for
     * the form grid, the show-page card, and encode/decode below.
     *
     * @return array<string,string>
     */
    function hours_days(): array
    {
        return [
            'mon' => 'Monday', 'tue' => 'Tuesday', 'wed' => 'Wednesday',
            'thu' => 'Thursday', 'fri' => 'Friday', 'sat' => 'Saturday', 'sun' => 'Sunday',
        ];
    }
}

if (! function_exists('hours_encode')) {
    /**
     * Normalise raw form input (hours[mon][open] etc.) into the stored JSON
     * shape. Unrecognised days are dropped; malformed times are blanked
     * rather than rejected, since trading hours are display-only and never
     * validated as a hard requirement.
     *
     * @param array<string,array{open?:string,close?:string,closed?:string,note?:string}> $raw
     */
    function hours_encode(array $raw): ?string
    {
        $out = [];
        foreach (array_keys(hours_days()) as $day) {
            $row    = $raw[$day] ?? [];
            $closed = ! empty($row['closed']);
            $open   = preg_match('/^\d{2}:\d{2}$/', (string) ($row['open'] ?? '')) ? $row['open'] : '';
            $close  = preg_match('/^\d{2}:\d{2}$/', (string) ($row['close'] ?? '')) ? $row['close'] : '';

            $out[$day] = [
                'closed' => $closed,
                'open'   => $closed ? '' : $open,
                'close'  => $closed ? '' : $close,
                // Truncated rather than rejected, to match how malformed times
                // are blanked above: hours are display-only and never worth
                // failing a whole submission over. The cap exists because the
                // encoded JSON goes into one TEXT column, and seven uncapped
                // notes are seven unbounded strings per submission.
                'note'   => mb_substr(trim((string) ($row['note'] ?? '')), 0, HOURS_NOTE_MAX),
            ];
        }

        // Public holidays (hours_holiday_encode()), stored beside the days
        // under their own key. hours_decode() returns the seven days only, so
        // every caller that walks the days never meets it.
        $holidays = hours_holiday_encode(is_array($raw['holidays'] ?? null) ? $raw['holidays'] : []);
        if ($holidays !== null) {
            $out['holidays'] = $holidays;
        }

        return json_encode($out) ?: null;
    }
}

if (! function_exists('hours_decode')) {
    /**
     * @return array<string,array{closed:bool,open:string,close:string,note:string}>|null
     */
    function hours_decode(?string $json): ?array
    {
        if ($json === null || trim($json) === '') {
            return null;
        }
        $data = json_decode($json, true);
        if (! is_array($data)) {
            return null;
        }

        $out = [];
        foreach (array_keys(hours_days()) as $day) {
            $row       = $data[$day] ?? [];
            $out[$day] = [
                'closed' => ! empty($row['closed']),
                'open'   => (string) ($row['open'] ?? ''),
                'close'  => (string) ($row['close'] ?? ''),
                'note'   => (string) ($row['note'] ?? ''),
            ];
        }
        return $out;
    }
}

if (! function_exists('hours_context')) {
    /**
     * Whose hours are being shown: set from the profile (or branch) a page is
     * rendering, so "Open now", "Today" and the holiday line use that
     * business's own clock and calendar. hours_now() and hours_calendar()
     * read it.
     *
     * Called with a row, it switches to the row's timezone (its own column,
     * else its country and region via Config\Countries) and country. Called
     * with nothing, it goes back to South Africa, which is also what it is
     * before anyone calls it, so every South African page reads exactly what
     * it always read. Returns the context now in force.
     *
     * @param array<string,mixed>|null $row
     *
     * @return array{timezone:string,country:string}
     */
    function hours_context(?array $row = null, bool $set = true): array
    {
        static $context = ['timezone' => 'Africa/Johannesburg', 'country' => 'ZA'];

        if (! $set) {
            return $context;
        }

        if ($row === null) {
            return $context = ['timezone' => 'Africa/Johannesburg', 'country' => 'ZA'];
        }

        $country = \App\Libraries\Geocoding\NominatimGeocoder::countryOf($row);
        $tz      = trim((string) ($row['timezone'] ?? ''));
        if ($tz === '' || ! in_array($tz, DateTimeZone::listIdentifiers(), true)) {
            $tz = config('Countries')->timezone($country, (string) ($row['region'] ?? $row['province'] ?? ''));
        }

        return $context = ['timezone' => $tz, 'country' => $country];
    }
}

if (! function_exists('hours_now')) {
    /**
     * The current time on the clocks of the business being shown (see
     * hours_context()): South Africa's unless a page said otherwise.
     *
     * The app runs in UTC (Config\App::$appTimezone, see local_datetime()), but
     * trading hours are typed as wall clock times where the business is. A
     * bare date() put "Today" on the wrong row from 22:00 to midnight and said
     * "Open now" two hours late.
     */
    function hours_now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone(hours_context(null, false)['timezone']));
    }
}

if (! function_exists('hours_today_key')) {
    /**
     * Today's day key (mon..sun), computed server-side so it's correct with
     * JS disabled and doesn't shift under a page cache spanning midnight.
     */
    function hours_today_key(): string
    {
        $keys = array_keys(hours_days());
        return $keys[((int) hours_now()->format('N')) - 1];
    }
}

if (! function_exists('hours_is_open_now')) {
    /**
     * Is this business open right now? null when we genuinely cannot say.
     *
     * Three-state on purpose. "Closed" is a claim, and making it the fallback
     * for missing data would put a red "Closed" badge on every listing that
     * simply never filled its hours in — which is worse than saying nothing,
     * because a visitor believes it and doesn't call.
     *
     * So null means "no usable hours for today", and only actual times produce
     * a true or false. A day marked closed is a real answer and returns false;
     * a day left blank while not marked closed is not.
     *
     * Overnight spans are handled: a bar open 18:00–02:00 stores close < open,
     * which without this reads as a zero-length window and reports closed all
     * evening. Yesterday's overnight span is checked too, so 01:00 still counts
     * as open.
     *
     * Times are naive South African wall clock values (see hours_now()): a
     * single-country directory, so there is no per-listing timezone to reconcile.
     *
     * @param array<string,array{closed:bool,open:string,close:string,note:string}>|null $hours
     *   as returned by hours_decode()
     * @param string|null $now 'HH:MM' override, for testing
     * @param string|null $today day key override, for testing
     */
    function hours_is_open_now(?array $hours, ?string $now = null, ?string $today = null): ?bool
    {
        if ($hours === null) {
            return null;
        }

        $keys  = array_keys(hours_days());
        $today = $today ?? hours_today_key();
        $index = array_search($today, $keys, true);
        if ($index === false) {
            return null;
        }

        $now     = $now ?? hours_now()->format('H:i');
        $minutes = static function (string $time): ?int {
            if (preg_match('/^(\d{1,2}):(\d{2})/', trim($time), $m) !== 1) {
                return null;
            }

            return ((int) $m[1]) * 60 + (int) $m[2];
        };

        $at = $minutes($now);
        if ($at === null) {
            return null;
        }

        // Yesterday first: an overnight span that started before midnight is
        // still running now, and today's own row says nothing about it.
        $yesterday = $hours[$keys[($index + 6) % 7]] ?? null;
        if (is_array($yesterday) && empty($yesterday['closed'])) {
            $from = $minutes((string) ($yesterday['open'] ?? ''));
            $to   = $minutes((string) ($yesterday['close'] ?? ''));
            if ($from !== null && $to !== null && $to < $from && $at < $to) {
                return true;
            }
        }

        $row = $hours[$today] ?? null;
        if (! is_array($row)) {
            return null;
        }
        if (! empty($row['closed'])) {
            return false;
        }

        $from = $minutes((string) ($row['open'] ?? ''));
        $to   = $minutes((string) ($row['close'] ?? ''));

        // Not marked closed, but no times either — the owner left the day
        // blank. Saying "closed" would be inventing an answer.
        if ($from === null || $to === null) {
            return null;
        }

        // Closing before opening means the span runs past midnight.
        return $to < $from ? $at >= $from : ($at >= $from && $at < $to);
    }
}

if (! function_exists('hours_today_summary')) {
    /**
     * Today's hours as one line for the profile header, under the badges.
     *
     * Returns ['state' => 'open'|'closed'|null, 'label' => ..., 'detail' => ...]
     * or null when there is nothing honest to say. The open/closed state comes
     * from hours_is_open_now(), so the header and the map popup cannot disagree,
     * and it inherits that function's rule: a day left blank is "we don't
     * know", never "Closed".
     *
     * @param array<string,array{closed:bool,open:string,close:string,note:string}>|null $hours
     *   as returned by hours_decode()
     * @param string|null $now 'HH:MM' override, for testing
     * @param string|null $today day key override, for testing
     *
     * @return array{state:?string,label:string,detail:string}|null
     */
    function hours_today_summary(?array $hours, bool $byAppointment = false, ?string $now = null, ?string $today = null): ?array
    {
        $today = $today ?? hours_today_key();
        $now   = $now ?? hours_now()->format('H:i');
        $open  = hours_is_open_now($hours, $now, $today);

        if ($open === null) {
            return $byAppointment ? ['state' => null, 'label' => 'By appointment only', 'detail' => ''] : null;
        }

        $row = $hours[$today] ?? [];
        if ($open === false && ! empty($row['closed'])) {
            return ['state' => 'closed', 'label' => 'Closed today', 'detail' => ''];
        }

        // Open on the tail of last night's overnight span: today's own row says
        // nothing about that, so give the closing time instead.
        $keys      = array_keys(hours_days());
        $yesterday = $hours[$keys[(array_search($today, $keys, true) + 6) % 7]] ?? [];
        if ($open && empty($yesterday['closed']) && ($yesterday['close'] ?? '') !== ''
            && $yesterday['close'] < ($yesterday['open'] ?? '') && $now < $yesterday['close']) {
            return ['state' => 'open', 'label' => 'Open now', 'detail' => 'until ' . $yesterday['close']];
        }

        return [
            'state'  => $open ? 'open' : 'closed',
            'label'  => $open ? 'Open now' : 'Closed now',
            'detail' => empty($row['closed']) && ($row['open'] ?? '') !== '' && ($row['close'] ?? '') !== ''
                ? $row['open'] . '–' . $row['close'] . ' today'
                : '',
        ];
    }
}

// ─── Public holidays ──────────────────────────────────────────────────────
//
// The owner gives one answer for every public holiday, plus exceptions for
// single holidays ("open most holidays, but closed on Christmas Day"):
//
//   holidays: {mode, open, close, note, except: {christmas: {mode, open, close}}}
//
// mode is 'closed', 'open' (with times, or none given) or 'usual' (the day's
// normal hours). No mode means the owner has not said, and then nothing ever
// claims open or closed on a holiday: see hours_today_line().
// App\Libraries\PublicHolidays says which days are holidays.

defined('HOURS_HOLIDAY_MODES') || define('HOURS_HOLIDAY_MODES', ['closed', 'open', 'usual']);

if (! function_exists('hours_holiday_encode')) {
    /**
     * The posted holidays row as stored, or null when the owner left it unanswered.
     *
     * @param array<string,mixed> $raw hours[holidays][...]
     *
     * @return array{mode:string,open:string,close:string,note:string,except:array<string,array{mode:string,open:string,close:string}>}|null
     */
    function hours_holiday_encode(array $raw): ?array
    {
        $time = static fn ($v): string => preg_match('/^\d{2}:\d{2}$/', (string) $v) ? (string) $v : '';
        $mode = in_array($raw['mode'] ?? '', HOURS_HOLIDAY_MODES, true) ? (string) $raw['mode'] : '';
        if ($mode === '') {
            return null;
        }

        // Exceptions arrive two ways: keyed by holiday, as stored, or as the
        // form's repeat rows, a list each naming its holiday in 'key' (with
        // '_remove' when ticked). A holiday given twice keeps the last row.
        $byKey = [];
        foreach (is_array($raw['except'] ?? null) ? $raw['except'] : [] as $index => $row) {
            if (! is_array($row) || ! empty($row['_remove'])) {
                continue;
            }
            $key = is_string($index) && ! ctype_digit($index) ? $index : (string) ($row['key'] ?? '');
            if (isset(\App\Libraries\PublicHolidays::NATIONAL[$key])) {
                $byKey[$key] = $row;
            }
        }

        $except = [];
        foreach (\App\Libraries\PublicHolidays::NATIONAL as $key => $name) {
            $row  = $byKey[$key] ?? [];
            $eMode = in_array($row['mode'] ?? '', HOURS_HOLIDAY_MODES, true) ? (string) $row['mode'] : '';
            // "Same as other holidays" is the absence of an exception.
            if ($eMode === '' || ($eMode === $mode && $eMode !== 'open')) {
                continue;
            }
            $except[$key] = [
                'mode'  => $eMode,
                'open'  => $eMode === 'open' ? $time($row['open'] ?? '') : '',
                'close' => $eMode === 'open' ? $time($row['close'] ?? '') : '',
            ];
            if ($eMode === 'open' && $mode === 'open' && $except[$key]['open'] === $time($raw['open'] ?? '')
                && $except[$key]['close'] === $time($raw['close'] ?? '')) {
                unset($except[$key]);
            }
        }

        return [
            'mode'   => $mode,
            'open'   => $mode === 'open' ? $time($raw['open'] ?? '') : '',
            'close'  => $mode === 'open' ? $time($raw['close'] ?? '') : '',
            'note'   => mb_substr(trim((string) ($raw['note'] ?? '')), 0, HOURS_NOTE_MAX),
            'except' => $except,
        ];
    }
}

if (! function_exists('hours_holiday_rule')) {
    /**
     * The owner's public holiday answer from the stored JSON, or null when
     * there is none.
     *
     * @return array{mode:string,open:string,close:string,note:string,except:array<string,array{mode:string,open:string,close:string}>}|null
     */
    function hours_holiday_rule(?string $json): ?array
    {
        if ($json === null || trim($json) === '') {
            return null;
        }
        $data = json_decode($json, true);

        return is_array($data) && is_array($data['holidays'] ?? null)
            ? hours_holiday_encode($data['holidays'])
            : null;
    }
}

if (! function_exists('hours_holiday_answer')) {
    /**
     * What the business does on one holiday, from its rule and its normal
     * hours for that weekday. Null when we cannot honestly say: no answer at
     * all, or "usual hours" on a weekday left blank.
     *
     * @param array<string,mixed>|null $rule    hours_holiday_rule()
     * @param string                   $key     PublicHolidays key ('extra' for a declared day)
     * @param array<string,mixed>|null $weekday the hours_decode() row for that weekday
     *
     * @return array{state:string,open:string,close:string}|null state 'open' or 'closed'
     */
    function hours_holiday_answer(?array $rule, string $key, ?array $weekday): ?array
    {
        if ($rule === null) {
            return null;
        }
        $use = $rule['except'][$key] ?? $rule;

        switch ($use['mode']) {
            case 'closed':
                return ['state' => 'closed', 'open' => '', 'close' => ''];

            case 'open':
                return ['state' => 'open', 'open' => (string) $use['open'], 'close' => (string) $use['close']];

            case 'usual':
                if (! is_array($weekday)) {
                    return null;
                }
                if (! empty($weekday['closed'])) {
                    return ['state' => 'closed', 'open' => '', 'close' => ''];
                }
                if (($weekday['open'] ?? '') === '' || ($weekday['close'] ?? '') === '') {
                    return null;
                }

                return ['state' => 'open', 'open' => (string) $weekday['open'], 'close' => (string) $weekday['close']];
        }

        return null;
    }
}

if (! function_exists('hours_holiday_text')) {
    /** "Closed", "09:00–13:00", "Open" or "Usual hours": one answer in words. */
    function hours_holiday_text(array $answer): string
    {
        return match ($answer['mode'] ?? $answer['state'] ?? '') {
            'closed' => 'Closed',
            'usual'  => 'Usual hours',
            default  => ($answer['open'] ?? '') !== '' && ($answer['close'] ?? '') !== ''
                ? $answer['open'] . '–' . $answer['close']
                : 'Open',
        };
    }
}

if (! function_exists('hours_weekday_row')) {
    /** The decoded hours row for the weekday a Y-m-d date falls on. */
    function hours_weekday_row(?array $hours, string $date): ?array
    {
        $keys = array_keys(hours_days());
        $row  = $hours[$keys[((int) (new DateTimeImmutable($date))->format('N')) - 1]] ?? null;

        return is_array($row) ? $row : null;
    }
}

if (! function_exists('hours_today_line')) {
    /**
     * hours_today_summary(), knowing about public holidays. The one place the
     * profile header and the map popup ask "open now?", so they cannot
     * disagree on a holiday either.
     *
     * On a holiday the weekday's normal hours are not trusted: Heritage Day
     * on a Wednesday is not a Wednesday. With the owner's answer the line
     * says what it says; without one it says hours may differ and claims
     * neither open nor closed.
     *
     * @param string|null $now  'HH:MM' override, for testing
     * @param string|null $date 'Y-m-d' override, for testing
     *
     * @return array{state:?string,label:string,detail:string,holiday:?array}|null
     */
    function hours_today_line(?array $hours, ?array $rule, bool $byAppointment = false, ?string $now = null, ?string $date = null, ?\App\Libraries\PublicHolidays $calendar = null): ?array
    {
        $date    ??= hours_now()->format('Y-m-d');
        $now     ??= hours_now()->format('H:i');
        $holiday = ($calendar ?? hours_calendar())->on($date);

        if ($holiday === null) {
            $keys    = array_keys(hours_days());
            $summary = hours_today_summary($hours, $byAppointment, $now, $keys[((int) (new DateTimeImmutable($date))->format('N')) - 1]);

            return $summary === null ? null : $summary + ['holiday' => null];
        }

        $name   = \App\Libraries\PublicHolidays::label($holiday);
        $answer = hours_holiday_answer($rule, $holiday['key'], hours_weekday_row($hours, $date));
        if ($answer === null) {
            return ['state' => null, 'label' => 'Public holiday: hours may differ', 'detail' => $name . '. Call ahead', 'holiday' => $holiday];
        }
        if ($answer['state'] === 'closed') {
            return ['state' => 'closed', 'label' => 'Closed today', 'detail' => $name, 'holiday' => $holiday];
        }
        if ($answer['open'] === '' || $answer['close'] === '') {
            return ['state' => null, 'label' => 'Open today', 'detail' => $name, 'holiday' => $holiday];
        }

        $open = $answer['close'] < $answer['open']
            ? $now >= $answer['open']
            : ($now >= $answer['open'] && $now < $answer['close']);

        return [
            'state'   => $open ? 'open' : 'closed',
            'label'   => $open ? 'Open now' : 'Closed now',
            'detail'  => $name . ' hours ' . $answer['open'] . '–' . $answer['close'],
            'holiday' => $holiday,
        ];
    }
}

if (! function_exists('hours_open_now_line')) {
    /**
     * hours_is_open_now(), knowing about public holidays: true, false, or null
     * when we cannot say (always null on a holiday the owner has not answered).
     */
    function hours_open_now_line(?array $hours, ?array $rule, ?string $now = null, ?string $date = null, ?\App\Libraries\PublicHolidays $calendar = null): ?bool
    {
        $date ??= hours_now()->format('Y-m-d');
        if (($calendar ?? hours_calendar())->on($date) === null) {
            $keys = array_keys(hours_days());

            return hours_is_open_now($hours, $now, $keys[((int) (new DateTimeImmutable($date))->format('N')) - 1]);
        }
        $line = hours_today_line($hours, $rule, false, $now, $date, $calendar);

        return match ($line['state'] ?? null) {
            'open'   => true,
            'closed' => false,
            default  => null,
        };
    }
}

if (! function_exists('hours_calendar')) {
    /**
     * One PublicHolidays per request: it reads the settings once. South
     * Africa's is the only calendar so far, so a business elsewhere (see
     * hours_context()) gets one with no holidays rather than ours.
     */
    function hours_calendar(): \App\Libraries\PublicHolidays
    {
        static $calendar = null;
        static $none     = null;

        if (hours_context(null, false)['country'] !== 'ZA') {
            return $none ??= new \App\Libraries\NoPublicHolidays();
        }

        return $calendar ??= new \App\Libraries\PublicHolidays();
    }
}

if (! function_exists('hours_holiday_open_dates')) {
    /**
     * The holiday dates from $from to a year on when the business is open,
     * by its answers: what the search filter matches on a holiday
     * (xs_directory_listings.holiday_open_dates). Comma separated, for FIND_IN_SET().
     */
    function hours_holiday_open_dates(?string $json, string $from, ?\App\Libraries\PublicHolidays $calendar = null): ?string
    {
        $rule = hours_holiday_rule($json);
        if ($rule === null) {
            return null;
        }
        $hours = hours_decode($json);
        $dates = [];
        foreach (($calendar ?? hours_calendar())->between($from, date('Y-m-d', strtotime($from . ' +1 year'))) as $day) {
            $answer = hours_holiday_answer($rule, $day['key'], hours_weekday_row($hours, $day['date']));
            if (($answer['state'] ?? null) === 'open') {
                $dates[] = $day['date'];
            }
        }

        return implode(',', $dates);
    }
}

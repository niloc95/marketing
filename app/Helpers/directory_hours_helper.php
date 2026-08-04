<?php

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
                'note'   => trim((string) ($row['note'] ?? '')),
            ];
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

if (! function_exists('hours_today_key')) {
    /**
     * Today's day key (mon..sun), computed server-side so it's correct with
     * JS disabled and doesn't shift under a page cache spanning midnight.
     */
    function hours_today_key(): string
    {
        $keys = array_keys(hours_days());
        return $keys[((int) date('N')) - 1];
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
     * Times are naive local values in the app's timezone — a single-country
     * directory, so there is no per-listing timezone to reconcile.
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

        $now     = $now ?? date('H:i');
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

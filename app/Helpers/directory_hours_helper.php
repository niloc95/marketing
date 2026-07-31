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

<?php

namespace App\Services\Bookings\Calendar;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Side by side columns for overlapping appointments in one person's column
 * of the calendar. Ported from Bookings' EventLayoutService, unchanged in
 * behaviour:
 *
 * 1. sort by start, then end;
 * 2. sweep the start and end markers (ends before starts at the same moment,
 *    so back to back appointments share column 0);
 * 3. give each appointment the lowest free column;
 * 4. an appointment's column count is the highest column among everything
 *    it overlaps, plus one.
 *
 * Times are compared as instants, so an appointment across a daylight saving
 * change (.app countries) is laid out by its real length.
 *
 * Adds `_column`, `_columns_total`, `_column_width_pct`, `_column_left_pct`.
 */
class EventLayout
{
    /** Past this many appointments in one column the quadratic pass is logged. */
    private const LARGE_DATASET_WARNING_THRESHOLD = 200;

    /**
     * @param list<array<string,mixed>> $events each with `start` and `end` (ISO strings, or start_at/end_at)
     *
     * @return list<array<string,mixed>>
     */
    public function resolve(array $events): array
    {
        if ($events === []) {
            return [];
        }
        if (count($events) >= self::LARGE_DATASET_WARNING_THRESHOLD) {
            log_message('warning', '[Bookings] Calendar layout of {n} appointments in one column.', ['n' => count($events)]);
        }

        $events = array_values($events);
        usort($events, fn (array $a, array $b): int => [$this->startOf($a), $this->endOf($a)] <=> [$this->startOf($b), $this->endOf($b)]);

        $timeline = [];
        foreach ($events as $i => $event) {
            $timeline[] = ['time' => $this->startOf($event), 'end' => false, 'i' => $i];
            $timeline[] = ['time' => $this->endOf($event), 'end' => true, 'i' => $i];
        }
        // Ends before starts at the same moment.
        usort($timeline, static fn (array $a, array $b): int => [$a['time'], $a['end'] ? 0 : 1] <=> [$b['time'], $b['end'] ? 0 : 1]);

        $active = [];
        $column = [];
        foreach ($timeline as $marker) {
            if ($marker['end']) {
                unset($active[$marker['i']]);

                continue;
            }
            $used = array_map(static fn (int $i): int => $column[$i], array_keys($active));
            $col  = 0;
            while (in_array($col, $used, true)) {
                $col++;
            }
            $column[$marker['i']] = $col;
            $active[$marker['i']] = true;
        }

        foreach ($events as $i => &$event) {
            $start = $this->startOf($event);
            $end   = $this->endOf($event);
            $max   = $column[$i];
            foreach ($events as $j => $other) {
                if ($j !== $i && $start < $this->endOf($other) && $this->startOf($other) < $end) {
                    $max = max($max, $column[$j]);
                }
            }
            $total = $max + 1;

            $event['_column']           = $column[$i];
            $event['_columns_total']    = $total;
            $event['_column_width_pct'] = 100 / $total;
            $event['_column_left_pct']  = $column[$i] * (100 / $total);
        }
        unset($event);

        return $events;
    }

    /** @param array<string,mixed> $event */
    private function startOf(array $event): int
    {
        return $this->toMillis($event['start'] ?? $event['start_at'] ?? null);
    }

    /** @param array<string,mixed> $event */
    private function endOf(array $event): int
    {
        return $this->toMillis($event['end'] ?? $event['end_at'] ?? null);
    }

    /** Milliseconds since the epoch; a string without a zone is UTC (the storage rule). */
    private function toMillis(mixed $dt): int
    {
        if (is_numeric($dt)) {
            return (int) $dt;
        }
        if (is_string($dt) && $dt !== '') {
            try {
                return (new DateTimeImmutable($dt, new DateTimeZone('UTC')))->getTimestamp() * 1000;
            } catch (\Exception) {
                return 0;
            }
        }
        if ($dt instanceof \DateTimeInterface) {
            return $dt->getTimestamp() * 1000;
        }

        return 0;
    }
}

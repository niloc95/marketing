<?php

namespace App\Services\Bookings\Calendar;

/**
 * The hours a day or week view draws, and its line spacing. Ported from
 * Bookings' TimeGridService. Bookings drew one fixed window for every day
 * (`business.work_start` / `work_end`); here CalendarHours::grid() works the
 * window out from the profile's hours, the people's hours and the
 * appointments shown, so nothing booked outside hours falls off the grid.
 */
class TimeGrid
{
    public function __construct(
        private CalendarRange $range,
        private string $dayStart,
        private string $dayEnd,
        private int $resolution = 30,
    ) {
    }

    /** @return array<string,mixed> see CalendarRange::daySlots() */
    public function dayGrid(string $date): array
    {
        return $this->range->daySlots($date, $this->dayStart, $this->dayEnd, $this->resolution);
    }

    public function dayStart(): string
    {
        return $this->dayStart;
    }

    public function dayEnd(): string
    {
        return $this->dayEnd;
    }

    public function resolution(): int
    {
        return $this->resolution;
    }
}

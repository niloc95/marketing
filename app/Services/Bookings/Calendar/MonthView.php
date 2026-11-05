<?php

namespace App\Services\Bookings\Calendar;

use DateTimeImmutable;
use DateTimeZone;

/**
 * The month view's render model: 42 cells (six weeks), each with up to three
 * appointments and a "more" count. Ported from Bookings' MonthViewService.
 *
 * Model (JSON safe):
 *   year, month, monthName, monthLabel, timezone, startDate, endDate,
 *   weeks [[{date, dayNumber, weekday, weekdayName, week, isCurrentMonth,
 *            isToday, isPast, isFuture, appointments, appointmentCount,
 *            hasMore, moreCount, hasAvailability}]],
 *   appointments [...], totalAppointments
 *
 * `hasAvailability` is per date, not per weekday as in Bookings: the
 * business must be open that date (its hours and public holidays) and
 * someone in view must work that weekday.
 */
class MonthView
{
    public const MAX_PER_CELL = 3;

    private DateTimeZone $tz;

    private CalendarRange $range;

    private CalendarHours $hours;

    private CalendarQuery $query;

    private DayView $dayView;

    /**
     * @param array<string,mixed> $listing
     * @param list<int>|null      $scope
     */
    public function __construct(array $listing, private ?array $scope, ?DateTimeImmutable $now = null)
    {
        $listingId     = (int) $listing['id'];
        $this->tz      = \App\Services\Bookings\BusinessLocale::zone($listing);
        $this->range   = DayView::rangeFor($listingId, $this->tz, $now);
        $this->hours   = new CalendarHours($listing, $this->range);
        $this->query   = new CalendarQuery($listingId, $this->tz);
        $this->dayView = new DayView($listing, $scope, $now, $this->range, $this->hours, $this->query);
    }

    /**
     * @param array<string,mixed> $filters
     *
     * @return array<string,mixed>
     */
    public function build(int $year, int $month, array $filters = [], int $maxPerCell = self::MAX_PER_CELL): array
    {
        $grid      = $this->range->monthGrid($year, $month);
        $events    = $this->query->forRange($grid['startDate'], $grid['endDate'], $filters, $this->scope);
        $byDate    = CalendarQuery::byDate($events);
        $people    = array_values(array_filter(array_column($this->dayView->providers($filters), 'id')));

        $weeks = [];
        $shown = [];
        foreach ($grid['weeks'] as $week) {
            $row = [];
            foreach ($week as $cell) {
                $cellEvents = $byDate[$cell['date']] ?? [];
                $count      = count($cellEvents);
                array_push($shown, ...$cellEvents);

                $cell['appointments']     = array_slice($cellEvents, 0, $maxPerCell);
                $cell['appointmentCount'] = $count;
                $cell['hasMore']          = $count > $maxPerCell;
                $cell['moreCount']        = max(0, $count - $maxPerCell);
                $cell['hasAvailability']  = $this->hours->anyoneWorks($cell['date'], $people);
                $row[]                    = $cell;
            }
            $weeks[] = $row;
        }

        return [
            'year'              => $year,
            'month'             => $month,
            'monthName'         => $grid['monthName'],
            'monthLabel'        => $grid['monthName'] . ' ' . $year,
            'timezone'          => $this->tz->getName(),
            'startDate'         => $grid['startDate'],
            'endDate'           => $grid['endDate'],
            'weeks'             => $weeks,
            'appointments'      => $shown,
            'totalAppointments' => count($shown),
        ];
    }
}

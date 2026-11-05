<?php

namespace App\Services\Bookings\Calendar;

use DateTimeImmutable;
use DateTimeZone;

/**
 * The week view's render model: seven days, each built by DayView on one
 * grid shared by the whole week. Ported from Bookings' WeekViewService.
 *
 * Model (JSON safe):
 *   startDate, endDate, weekLabel, timezone, businessHours {startTime, endTime},
 *   slotDuration, grid,
 *   days [{date, dayNumber, monthName, fullDate, weekday, weekdayName, isToday,
 *          isPast, isOpen, appointments, appointmentCount, dayGrid, providerColumns}],
 *   appointments [...], totalAppointments
 *
 * One query for the week; days are grouped by the local date an appointment
 * starts on (Bookings grouped on the UTC date). The label reads
 * "5 to 11 January 2030", with no dash (copy rules).
 */
class WeekView
{
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
     * @param string              $date    any date in the week (Y-m-d)
     * @param array<string,mixed> $filters
     *
     * @return array<string,mixed>
     */
    public function build(string $date, array $filters = []): array
    {
        $week   = $this->range->weekRange($date);
        $events = $this->query->forRange($week['startDate'], $week['endDate'], $filters, $this->scope);
        $byDate = CalendarQuery::byDate($events);
        $dates  = array_column($week['days'], 'date');
        $grid   = $this->hours->grid($dates, array_column($this->dayView->providers($filters, $events), 'id'), $events);

        $all  = [];
        $days = [];
        foreach ($week['days'] as $day) {
            $model = $this->dayView->build($day['date'], $filters, $byDate[$day['date']] ?? [], $grid);

            $day['isOpen']           = $model['businessHours']['isOpen'];
            $day['appointments']     = $model['appointments'];
            $day['appointmentCount'] = $model['totalAppointments'];
            $day['dayGrid']          = $model['grid'];
            $day['providerColumns']  = $model['providerColumns'];
            array_push($all, ...$model['appointments']);
            $days[] = $day;
        }

        return [
            'startDate'         => $week['startDate'],
            'endDate'           => $week['endDate'],
            'weekLabel'         => self::label($week['startDate'], $week['endDate']),
            'timezone'          => $this->tz->getName(),
            'businessHours'     => ['startTime' => $grid->dayStart(), 'endTime' => $grid->dayEnd()],
            'slotDuration'      => $grid->resolution(),
            'grid'              => $grid->dayGrid($week['startDate']),
            'days'              => $days,
            'appointments'      => $all,
            'totalAppointments' => count($all),
        ];
    }

    /** "5 to 11 January 2030", "29 December 2030 to 4 January 2031". */
    public static function label(string $startDate, string $endDate): string
    {
        $s = new DateTimeImmutable($startDate);
        $e = new DateTimeImmutable($endDate);
        if ($s->format('Y-m') === $e->format('Y-m')) {
            return $s->format('j') . ' to ' . $e->format('j F Y');
        }
        if ($s->format('Y') === $e->format('Y')) {
            return $s->format('j F') . ' to ' . $e->format('j F Y');
        }

        return $s->format('j F Y') . ' to ' . $e->format('j F Y');
    }
}

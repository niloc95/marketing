<?php

use App\Services\Bookings\Calendar\CalendarRange;
use App\Services\Bookings\Calendar\EventLayout;
use App\Services\Bookings\Calendar\WeekView;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * The calendar's date structure and overlap layout, no database.
 *
 * The first two cases are Bookings' (EventLayoutServiceDstTest and the
 * example in EventLayoutService's header). The rest pin CalendarRange: six
 * week month grids, the business's first day of the week, local "today" and
 * 12 hour labels.
 *
 * @internal
 */
final class BookingCalendarLayoutTest extends CIUnitTestCase
{
    // Bookings' cases

    public function testColumnAssignmentAcrossDstSpringForwardTransition(): void
    {
        // US DST starts on 2026-03-08 at 02:00 local time (America/New_York).
        // A spans the jump; B overlaps A after the jump; C starts as B ends.
        $byId = $this->byId((new EventLayout())->resolve([
            ['id' => 1, 'start' => '2026-03-08T01:30:00-05:00', 'end' => '2026-03-08T03:30:00-04:00'],
            ['id' => 2, 'start' => '2026-03-08T03:00:00-04:00', 'end' => '2026-03-08T04:00:00-04:00'],
            ['id' => 3, 'start' => '2026-03-08T04:00:00-04:00', 'end' => '2026-03-08T05:00:00-04:00'],
        ]));

        $this->assertSame(2, $byId[1]['_columns_total']);
        $this->assertSame(2, $byId[2]['_columns_total']);
        $this->assertNotSame($byId[1]['_column'], $byId[2]['_column']);
        $this->assertSame(1, $byId[3]['_columns_total']);
        $this->assertSame(0, $byId[3]['_column']);
    }

    public function testTwoOverlapSideBySideAndTheNextIsFullWidth(): void
    {
        $byId = $this->byId((new EventLayout())->resolve([
            ['id' => 'c', 'start' => '2026-03-09T10:00:00Z', 'end' => '2026-03-09T11:00:00Z'],
            ['id' => 'b', 'start' => '2026-03-09T09:00:00Z', 'end' => '2026-03-09T10:00:00Z'],
            ['id' => 'a', 'start' => '2026-03-09T09:00:00Z', 'end' => '2026-03-09T10:00:00Z'],
        ]));

        $this->assertEqualsWithDelta(50.0, $byId['a']['_column_width_pct'], 0.001);
        $this->assertSame([0, 1], [min($byId['a']['_column'], $byId['b']['_column']), max($byId['a']['_column'], $byId['b']['_column'])]);
        $this->assertEqualsWithDelta(50.0, max($byId['a']['_column_left_pct'], $byId['b']['_column_left_pct']), 0.001);
        $this->assertSame(['_column' => 0, '_columns_total' => 1], array_intersect_key($byId['c'], ['_column' => 1, '_columns_total' => 1]));
        $this->assertSame([], (new EventLayout())->resolve([]));
    }

    public function testThreeDeepStaircaseUsesTheLowestFreeColumn(): void
    {
        $byId = $this->byId((new EventLayout())->resolve([
            ['id' => 1, 'start' => '2030-01-07T09:00:00+02:00', 'end' => '2030-01-07T11:00:00+02:00'],
            ['id' => 2, 'start' => '2030-01-07T09:30:00+02:00', 'end' => '2030-01-07T10:00:00+02:00'],
            ['id' => 3, 'start' => '2030-01-07T09:45:00+02:00', 'end' => '2030-01-07T10:30:00+02:00'],
            ['id' => 4, 'start' => '2030-01-07T10:15:00+02:00', 'end' => '2030-01-07T10:45:00+02:00'],
        ]));

        $this->assertSame([0, 1, 2, 1], array_column([$byId[1], $byId[2], $byId[3], $byId[4]], '_column'));
        $this->assertSame(3, $byId[1]['_columns_total']);
    }

    // CalendarRange

    public function testAMonthIsAlwaysSixWeeksFromTheFirstDayOfTheWeek(): void
    {
        $tz     = new DateTimeZone('Africa/Johannesburg');
        $monday = (new CalendarRange($tz, 1, new DateTimeImmutable('2030-01-15 12:00', $tz)))->monthGrid(2030, 1);
        $sunday = (new CalendarRange($tz, 0, new DateTimeImmutable('2030-01-15 12:00', $tz)))->monthGrid(2030, 1);

        // 1 January 2030 is a Tuesday.
        $this->assertSame('2029-12-31', $monday['startDate']);
        $this->assertSame('2030-02-10', $monday['endDate']);
        $this->assertSame('2029-12-30', $sunday['startDate']);
        $this->assertCount(6, $monday['weeks']);
        $this->assertCount(42, array_merge(...$monday['weeks']));
        $this->assertFalse($monday['weeks'][0][0]['isCurrentMonth']);
        $this->assertTrue($monday['weeks'][2][1]['isToday']);
        $this->assertTrue($monday['weeks'][0][1]['isPast']);
    }

    public function testTodayIsTheBusinessesDateNotTheServers(): void
    {
        // 23:30 UTC on 6 January is already 7 January in Johannesburg.
        $range = new CalendarRange(new DateTimeZone('Africa/Johannesburg'), 1, new DateTimeImmutable('2030-01-06 23:30', new DateTimeZone('UTC')));

        $this->assertSame('2030-01-07', $range->today());
        $this->assertTrue($range->weekRange('2030-01-09')['days'][0]['isToday']);
        $this->assertSame('2030-01-07', $range->weekRange('2030-01-13')['startDate'], 'Sunday belongs to the week starting Monday');
    }

    public function testDaySlotsAndLabels(): void
    {
        $range = new CalendarRange(new DateTimeZone('UTC'), 1, null, true);
        $grid  = $range->daySlots('2030-01-07', '08:00', '24:00', 30);

        $this->assertCount(32, $grid['slots']);
        $this->assertSame(16 * 60 * CalendarRange::PIXELS_PER_MINUTE, $grid['containerHeight']);
        $this->assertSame(['8am', '8:30am'], [$grid['slots'][0]['label'], $grid['slots'][1]['label']]);
        $this->assertSame('12pm', $range->label(12, 0));
        $this->assertSame('23:30', (new CalendarRange(new DateTimeZone('UTC')))->label(23, 30));
    }

    public function testWeekLabelsHaveNoDash(): void
    {
        $this->assertSame('7 to 13 January 2030', WeekView::label('2030-01-07', '2030-01-13'));
        $this->assertSame('28 January to 3 February 2030', WeekView::label('2030-01-28', '2030-02-03'));
        $this->assertSame('30 December 2030 to 5 January 2031', WeekView::label('2030-12-30', '2031-01-05'));
    }

    /**
     * @param list<array<string,mixed>> $rows
     *
     * @return array<int|string,array<string,mixed>>
     */
    private function byId(array $rows): array
    {
        return array_column($rows, null, 'id');
    }
}

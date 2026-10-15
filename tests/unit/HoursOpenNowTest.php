<?php

use CodeIgniter\Test\CIUnitTestCase;

/**
 * "Open now" on a map popup is a claim a visitor acts on — they drive there, or
 * they don't call. So the interesting cases here are the ones where the honest
 * answer is "we don't know", and the function has to say so rather than guess.
 *
 * @internal
 */
final class HoursOpenNowTest extends CIUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        helper('directory_hours');
    }

    /** @param array<string,array{closed?:bool,open?:string,close?:string}> $days */
    private function hours(array $days): array
    {
        $out = [];
        foreach (array_keys(hours_days()) as $key) {
            $row       = $days[$key] ?? [];
            $out[$key] = [
                'closed' => ! empty($row['closed']),
                'open'   => (string) ($row['open'] ?? ''),
                'close'  => (string) ($row['close'] ?? ''),
                'note'   => '',
            ];
        }

        return $out;
    }

    public function testOpenInsideTradingHours(): void
    {
        $hours = $this->hours(['wed' => ['open' => '08:00', 'close' => '17:00']]);

        $this->assertTrue(hours_is_open_now($hours, '12:30', 'wed'));
    }

    public function testClosedBeforeOpeningAndAfterClosing(): void
    {
        $hours = $this->hours(['wed' => ['open' => '08:00', 'close' => '17:00']]);

        $this->assertFalse(hours_is_open_now($hours, '07:59', 'wed'));
        $this->assertFalse(hours_is_open_now($hours, '17:00', 'wed'), 'closing time is not still open');
        $this->assertFalse(hours_is_open_now($hours, '23:00', 'wed'));
    }

    public function testOpeningMinuteCounts(): void
    {
        $hours = $this->hours(['wed' => ['open' => '08:00', 'close' => '17:00']]);

        $this->assertTrue(hours_is_open_now($hours, '08:00', 'wed'));
    }

    public function testADayMarkedClosedIsARealAnswer(): void
    {
        $hours = $this->hours(['sun' => ['closed' => true]]);

        $this->assertFalse(hours_is_open_now($hours, '12:00', 'sun'));
    }

    /**
     * The important one. A day left blank is not the same as a day marked
     * closed — putting "Closed" on every listing that never filled its hours in
     * would be inventing an answer, and the visitor would believe it.
     */
    public function testABlankDayIsUnknownRatherThanClosed(): void
    {
        $hours = $this->hours(['wed' => ['open' => '', 'close' => '']]);

        $this->assertNull(hours_is_open_now($hours, '12:00', 'wed'));
    }

    public function testNoHoursAtAllIsUnknown(): void
    {
        $this->assertNull(hours_is_open_now(null, '12:00', 'wed'));
    }

    public function testAHalfFilledDayIsUnknown(): void
    {
        $hours = $this->hours(['wed' => ['open' => '08:00', 'close' => '']]);

        $this->assertNull(hours_is_open_now($hours, '12:00', 'wed'));
    }

    /**
     * A bar open 18:00–02:00 stores close < open. Read naively that is a
     * zero-length window, and the place reads as closed all evening while it is
     * busiest.
     */
    public function testOvernightHoursStayOpenPastMidnight(): void
    {
        $hours = $this->hours(['fri' => ['open' => '18:00', 'close' => '02:00']]);

        $this->assertTrue(hours_is_open_now($hours, '19:00', 'fri'));
        $this->assertTrue(hours_is_open_now($hours, '23:59', 'fri'));
        $this->assertFalse(hours_is_open_now($hours, '17:00', 'fri'));
    }

    /** 01:00 on Saturday is still Friday night's session. */
    public function testYesterdaysOvernightSessionCountsToday(): void
    {
        $hours = $this->hours([
            'fri' => ['open' => '18:00', 'close' => '02:00'],
            'sat' => ['open' => '18:00', 'close' => '02:00'],
        ]);

        $this->assertTrue(hours_is_open_now($hours, '01:00', 'sat'));
        $this->assertFalse(hours_is_open_now($hours, '03:00', 'sat'), 'after last night closed, before tonight opens');
    }

    public function testYesterdayBeingClosedDoesNotLeakIntoToday(): void
    {
        $hours = $this->hours([
            'sun' => ['closed' => true],
            'mon' => ['open' => '09:00', 'close' => '17:00'],
        ]);

        $this->assertFalse(hours_is_open_now($hours, '01:00', 'mon'));
        $this->assertTrue(hours_is_open_now($hours, '09:30', 'mon'));
    }

    public function testWeekWrapsFromMondayBackToSunday(): void
    {
        $hours = $this->hours(['sun' => ['open' => '20:00', 'close' => '03:00']]);

        $this->assertTrue(hours_is_open_now($hours, '02:00', 'mon'), 'Sunday night runs into Monday');
    }

    public function testGarbageTimesAreUnknownRatherThanOpen(): void
    {
        $hours = $this->hours(['wed' => ['open' => 'morning', 'close' => 'evening']]);

        $this->assertNull(hours_is_open_now($hours, '12:00', 'wed'));
    }

    // ---------------------------------------------- hours_today_summary()

    public function testSummaryOpenShowsTodaysSpan(): void
    {
        $hours = $this->hours(['wed' => ['open' => '08:00', 'close' => '17:00']]);

        $this->assertSame(
            ['state' => 'open', 'label' => 'Open now', 'detail' => '08:00–17:00 today'],
            hours_today_summary($hours, false, '12:00', 'wed'),
        );
    }

    public function testSummaryClosedNowStillShowsTodaysSpan(): void
    {
        $hours = $this->hours(['wed' => ['open' => '08:00', 'close' => '17:00']]);

        $this->assertSame(
            ['state' => 'closed', 'label' => 'Closed now', 'detail' => '08:00–17:00 today'],
            hours_today_summary($hours, false, '07:00', 'wed'),
        );
    }

    public function testSummaryDayMarkedClosed(): void
    {
        $hours = $this->hours(['sun' => ['closed' => true]]);

        $this->assertSame(
            ['state' => 'closed', 'label' => 'Closed today', 'detail' => ''],
            hours_today_summary($hours, false, '12:00', 'sun'),
        );
    }

    public function testSummaryOnLastNightsOvernightSpanGivesClosingTime(): void
    {
        $hours = $this->hours([
            'fri' => ['open' => '18:00', 'close' => '02:00'],
            'sat' => ['closed' => true],
        ]);

        $this->assertSame(
            ['state' => 'open', 'label' => 'Open now', 'detail' => 'until 02:00'],
            hours_today_summary($hours, false, '01:00', 'sat'),
        );
    }

    public function testSummaryBlankDaySaysNothing(): void
    {
        $this->assertNull(hours_today_summary($this->hours([]), false, '12:00', 'wed'));
        $this->assertNull(hours_today_summary(null, false, '12:00', 'wed'));
    }

    public function testSummaryByAppointmentWithoutTimes(): void
    {
        $this->assertSame(
            ['state' => null, 'label' => 'By appointment only', 'detail' => ''],
            hours_today_summary(null, true, '12:00', 'wed'),
        );
    }
}

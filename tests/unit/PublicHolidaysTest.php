<?php

use App\Libraries\PublicHolidays;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Public holidays and what a business does on them. The calendar is checked
 * against dates the Government Gazette actually declared, and the hours logic
 * against the rule the whole feature rests on: without the owner's answer, a
 * holiday is never reported as open or closed.
 *
 * Every test passes its own calendar and date, so nothing here depends on
 * today or on the admin's declared days.
 *
 * @internal
 */
final class PublicHolidaysTest extends CIUnitTestCase
{
    private PublicHolidays $calendar;

    protected function setUp(): void
    {
        parent::setUp();
        helper(['directory_hours', 'schema']);
        $this->calendar = new PublicHolidays([]);
    }

    // ------------------------------------------------------------ calendar

    public function testEasterAndTheSundayRule(): void
    {
        $dates = static fn (array $days): array => array_column($days, 'name', 'date');

        $y2026 = $dates($this->calendar->forYear(2026));
        $this->assertSame('Good Friday', $y2026['2026-04-03']);
        $this->assertSame('Family Day', $y2026['2026-04-06']);
        $this->assertSame("National Women's Day", $y2026['2026-08-10'], '9 August 2026 is a Sunday');
        $this->assertCount(13, $y2026);

        // 2022: Workers' Day and Christmas on Sundays. Christmas moves past
        // the Day of Goodwill, which already holds the Monday.
        $y2022 = $dates($this->calendar->forYear(2022));
        $this->assertArrayHasKey('2022-05-02', $y2022);
        $this->assertSame('Day of Goodwill', $y2022['2022-12-26']);
        $this->assertSame('Christmas Day', $y2022['2022-12-27']);
        $this->assertSame('Public holiday for Christmas Day', PublicHolidays::label($this->calendar->on('2022-12-27')));

        $this->assertSame('2027-03-28', PublicHolidays::easterSunday(2027)->format('Y-m-d'));
        $this->assertNull($this->calendar->on('2026-10-19'));
    }

    public function testDeclaredDaysJoinTheCalendar(): void
    {
        $calendar = new PublicHolidays([['date' => '2026-11-04', 'name' => 'Local government elections']]);

        $day = $calendar->on('2026-11-04');
        $this->assertSame(PublicHolidays::EXTRA, $day['key']);
        $this->assertSame('2026-11-04', $calendar->next('2026-10-19')['date']);
    }

    public function testTheAdminListIsReadStrictly(): void
    {
        $ok = PublicHolidays::parseExtra("2026-11-04 Local government elections\n\n2026-05-29 General election");
        $this->assertSame([], $ok['errors']);
        $this->assertSame('2026-05-29', $ok['days'][0]['date'], 'sorted by date');

        $bad = PublicHolidays::parseExtra("2026-02-30 Not a day\nElection day");
        $this->assertCount(2, $bad['errors']);
        $this->assertSame([], $bad['days']);
    }

    // ---------------------------------------------------------- the answer

    public function testEncodingKeepsOnlyRealAnswers(): void
    {
        $this->assertNull(hours_holiday_encode([]), 'unanswered stores nothing');
        $this->assertNull(hours_holiday_encode(['mode' => 'maybe']));

        $rule = hours_holiday_encode([
            'mode'   => 'open', 'open' => '09:00', 'close' => '13:00', 'note' => ' Skeleton staff ',
            'except' => [
                'christmas'   => ['mode' => 'closed'],
                'good-friday' => ['mode' => ''],
                'heritage'    => ['mode' => 'open', 'open' => '09:00', 'close' => '13:00'],
                'not-a-day'   => ['mode' => 'closed'],
            ],
        ]);
        $this->assertSame('open', $rule['mode']);
        $this->assertSame('Skeleton staff', $rule['note']);
        $this->assertSame(['christmas'], array_keys($rule['except']), 'same as the general answer, blank and unknown keys are dropped');

        $json = hours_encode(['mon' => ['open' => '08:00', 'close' => '17:00'], 'holidays' => ['mode' => 'closed']]);
        $this->assertSame('closed', hours_holiday_rule($json)['mode']);
        $this->assertCount(7, hours_decode($json), 'the days decode as before, without the holidays key');
    }

    public function testAnswersResolvePerHoliday(): void
    {
        $rule = hours_holiday_encode(['mode' => 'usual', 'except' => ['christmas' => ['mode' => 'closed']]]);

        $this->assertSame('closed', hours_holiday_answer($rule, 'christmas', ['open' => '08:00', 'close' => '17:00'])['state']);
        $this->assertSame('08:00', hours_holiday_answer($rule, 'heritage', ['closed' => false, 'open' => '08:00', 'close' => '17:00'])['open']);
        $this->assertSame('closed', hours_holiday_answer($rule, 'heritage', ['closed' => true])['state'], 'usual hours on a day it is shut');
        $this->assertNull(hours_holiday_answer($rule, 'heritage', ['closed' => false, 'open' => '', 'close' => '']), 'usual hours on a blank day: unknown');
        $this->assertNull(hours_holiday_answer(null, 'heritage', ['open' => '08:00', 'close' => '17:00']), 'no answer: unknown');
        $this->assertSame('open', hours_holiday_answer($rule, PublicHolidays::EXTRA, ['open' => '08:00', 'close' => '17:00'])['state'], 'a declared day takes the general answer');
    }

    // ------------------------------------------------------------ the line

    public function testOnAHolidayWithoutAnAnswerNothingIsClaimed(): void
    {
        // Heritage Day 2026 is a Thursday; the week says open.
        $hours = $this->week('08:00', '17:00');

        $line = hours_today_line($hours, null, false, '10:00', '2026-09-24', $this->calendar);
        $this->assertNull($line['state']);
        $this->assertSame('Public holiday: hours may differ', $line['label']);
        $this->assertStringContainsString('Heritage Day', $line['detail']);
        $this->assertNull(hours_open_now_line($hours, null, '10:00', '2026-09-24', $this->calendar), 'no Open badge on the map either');

        $this->assertSame('open', hours_today_line($hours, null, false, '10:00', '2026-09-23', $this->calendar)['state'], 'the day before is a normal Wednesday');
    }

    public function testOnAHolidayTheAnswerIsUsed(): void
    {
        $hours  = $this->week('08:00', '17:00');
        $closed = hours_holiday_encode(['mode' => 'closed']);
        $short  = hours_holiday_encode(['mode' => 'open', 'open' => '09:00', 'close' => '13:00']);

        $line = hours_today_line($hours, $closed, false, '10:00', '2026-09-24', $this->calendar);
        $this->assertSame(['closed', 'Closed today', 'Heritage Day'], [$line['state'], $line['label'], $line['detail']]);
        $this->assertFalse(hours_open_now_line($hours, $closed, '10:00', '2026-09-24', $this->calendar));

        $this->assertSame('open', hours_today_line($hours, $short, false, '10:00', '2026-09-24', $this->calendar)['state']);
        $after = hours_today_line($hours, $short, false, '14:00', '2026-09-24', $this->calendar);
        $this->assertSame('closed', $after['state']);
        $this->assertSame('Heritage Day hours 09:00–13:00', $after['detail']);
    }

    public function testOpenDatesListOnlyTheDaysItIsOpen(): void
    {
        // Open its usual hours, which leave Sunday shut; closed on Christmas.
        $json = hours_encode(array_merge($this->week('08:00', '17:00'), [
            'sun'      => ['closed' => '1'],
            'holidays' => ['mode' => 'usual', 'except' => ['christmas' => ['mode' => 'closed']]],
        ]));

        $dates = explode(',', (string) hours_holiday_open_dates($json, '2026-10-19', $this->calendar));
        $this->assertContains('2026-12-16', $dates);
        $this->assertNotContains('2026-12-25', $dates, 'the exception');
        $this->assertContains('2026-12-26', $dates, 'a Saturday, open as usual');
        $this->assertNotContains('2027-03-21', $dates, 'Human Rights Day 2027 is a Sunday, shut as usual');
        $this->assertContains('2027-03-22', $dates, 'and its Monday holiday follows the Monday hours');

        $this->assertNull(hours_holiday_open_dates(hours_encode($this->week('08:00', '17:00')), '2026-10-19', $this->calendar));
    }

    public function testGoogleGetsSpecialHoursForAnsweredHolidays(): void
    {
        $hours = $this->week('08:00', '17:00');
        $rule  = hours_holiday_encode(['mode' => 'open', 'open' => '09:00', 'close' => '13:00', 'except' => ['christmas' => ['mode' => 'closed']]]);

        $spec = schema_holiday_hours($hours, $rule, 30, '2026-12-01', $this->calendar);
        $this->assertSame(['2026-12-16', '2026-12-25', '2026-12-26'], array_column($spec, 'validFrom'));
        $this->assertSame(['00:00', '00:00'], [$spec[1]['opens'], $spec[1]['closes']], 'closed all day, as Google reads it');
        $this->assertSame('09:00', $spec[0]['opens']);
        $this->assertSame($spec[0]['validFrom'], $spec[0]['validThrough']);

        $this->assertSame([], schema_holiday_hours($hours, null, 30, '2026-12-01', $this->calendar), 'unanswered: left to the weekday');
    }

    /** Monday to Saturday at the same times; Sunday blank. */
    private function week(string $open, string $close): array
    {
        $out = [];
        foreach (array_keys(hours_days()) as $key) {
            $out[$key] = ['closed' => false, 'open' => $key === 'sun' ? '' : $open, 'close' => $key === 'sun' ? '' : $close, 'note' => ''];
        }

        return $out;
    }
}

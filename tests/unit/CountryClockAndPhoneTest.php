<?php

use App\Libraries\NoPublicHolidays;
use App\Libraries\PublicHolidays;
use App\Libraries\YasumiHolidays;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * A business's own clock, calendar and dialling code.
 *
 * "Open now" for a London shop must read London's clock, Heritage Day must
 * not appear on its hours, and "07700 900123" is a British mobile, not a
 * South African number. With no profile in view everything is South
 * African, exactly as before.
 *
 * @internal
 */
final class CountryClockAndPhoneTest extends CIUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        helper(['directory_hours', 'directory_ui', 'schema']);
    }

    protected function tearDown(): void
    {
        hours_context();
        parent::tearDown();
    }

    public function testTheClockFollowsTheBusinessBeingShown(): void
    {
        $this->assertSame('Africa/Johannesburg', hours_now()->getTimezone()->getName());

        hours_context(['country_code' => 'GB']);
        $this->assertSame('Europe/London', hours_now()->getTimezone()->getName());

        hours_context(['country' => 'United States of America', 'region' => 'California']);
        $this->assertSame('America/Los_Angeles', hours_now()->getTimezone()->getName());

        hours_context(['country_code' => 'AU', 'timezone' => 'Australia/Perth', 'region' => 'New South Wales']);
        $this->assertSame('Australia/Perth', hours_now()->getTimezone()->getName(), 'the row\'s own timezone wins');

        hours_context();
        $this->assertSame('Africa/Johannesburg', hours_now()->getTimezone()->getName());
    }

    public function testEachCountryGetsItsOwnHolidays(): void
    {
        $this->assertNotNull(hours_calendar()->on(date('Y') . '-12-16'), 'Day of Reconciliation');

        hours_context(['country_code' => 'GB', 'region' => 'England']);
        $this->assertInstanceOf(YasumiHolidays::class, hours_calendar());
        $this->assertNull(hours_calendar()->on(date('Y') . '-12-16'), 'not a holiday in England');
        $this->assertNotNull(hours_calendar()->on(date('Y') . '-12-25'));

        // No calendar for Nigeria yet: no holidays rather than ours.
        hours_context(['country_code' => 'NG']);
        $this->assertInstanceOf(NoPublicHolidays::class, hours_calendar());
        $this->assertSame([], hours_calendar()->named());
    }

    public function testYasumiCalendarsSpeakThePublicHolidaysShape(): void
    {
        $england = new YasumiHolidays('GB', 'England');
        // 2027: Christmas on a Saturday, so Monday 27th stands in for it.
        $sub = $england->on('2027-12-27');
        $this->assertSame('christmasDay', $sub['key']);
        $this->assertTrue($sub['observed']);
        $this->assertSame('Public holiday for Christmas Day', PublicHolidays::label($sub));
        $this->assertArrayHasKey('summerBankHoliday', $england->named());
        $this->assertNull($england->on('2027-03-07'), 'Mothering Sunday is an observance, not a day off');

        // Scotland's own 2 January, a Saturday in 2027, moved to Monday the 4th.
        $this->assertNotNull((new YasumiHolidays('GB', 'Scotland'))->on('2027-01-04'));
        $this->assertNull($england->on('2027-01-04'));

        // Victoria's Melbourne Cup, first Tuesday of November.
        $this->assertNotNull((new YasumiHolidays('AU', 'Victoria'))->on('2027-11-02'));
        $this->assertNull((new YasumiHolidays('AU', 'New South Wales'))->on('2027-11-02'));

        $this->assertArrayHasKey('independenceDay', (new YasumiHolidays('US'))->named());
        $this->assertFalse(YasumiHolidays::supports('NG'));
    }

    public function testALeadingZeroIsTheBusinessesOwnCountry(): void
    {
        $this->assertSame('27821234567', whatsapp_digits('082 123 4567'));
        $this->assertSame('447700900123', whatsapp_digits('07700 900123', country_dial_code('United Kingdom')));
        $this->assertSame('254712345678', whatsapp_digits('0712 345 678', country_dial_code('KE')));
        // No dialling code known: the number must carry its own.
        $this->assertNull(country_dial_code('Germany'));
        $this->assertSame('4915112345678', whatsapp_digits('+49 151 12345678', null));

        $this->assertSame('+442079460000', schema_telephone(['phone' => '020 7946 0000', 'country_code' => 'GB']));
        $this->assertSame('+27117285501', schema_telephone(['phone' => '011 728 5501']));
    }
}

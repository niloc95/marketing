<?php

use App\Libraries\NoPublicHolidays;
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

    public function testOnlySouthAfricaGetsSouthAfricanHolidays(): void
    {
        $this->assertNotNull(hours_calendar()->on(date('Y') . '-12-16'), 'Day of Reconciliation');

        hours_context(['country_code' => 'GB']);
        $this->assertInstanceOf(NoPublicHolidays::class, hours_calendar());
        $this->assertNull(hours_calendar()->on(date('Y') . '-12-16'));
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

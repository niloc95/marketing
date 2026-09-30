<?php

use CodeIgniter\Test\CIUnitTestCase;

/**
 * local_datetime(): the admin tables' "Created" / "Posted" times.
 *
 * The database holds UTC and the admins are in South Africa, so the one thing
 * worth pinning down is the +2h shift, including the case where it rolls the
 * date over.
 *
 * @internal
 */
final class LocalDatetimeTest extends CIUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        helper('directory_ui');
    }

    public function testShiftsUtcToSouthAfricanTime(): void
    {
        $this->assertSame('30 Sep 2026, 14:05', local_datetime('2026-09-30 12:05:00'));
    }

    public function testShiftRollsTheDateOver(): void
    {
        $this->assertSame('1 Oct 2026, 01:30', local_datetime('2026-09-30 23:30:00'));
    }

    public function testBlankOrBrokenInputIsADash(): void
    {
        $this->assertSame('—', local_datetime(null));
        $this->assertSame('—', local_datetime(''));
        $this->assertSame('—', local_datetime('garbage'));
    }
}

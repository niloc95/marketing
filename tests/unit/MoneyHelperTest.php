<?php

use CodeIgniter\Test\CIUnitTestCase;

/**
 * money(): every price on the site.
 *
 * The ZAR cases are the exact strings the views printed before money()
 * existed, character for character. If one of them changes, the South
 * African site changed.
 *
 * @internal
 */
final class MoneyHelperTest extends CIUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        helper('money');
    }

    public function testRandIsPrintedExactlyAsBefore(): void
    {
        $this->assertSame('R29.99', money('29.99'));
        $this->assertSame('R29,99', money('29,99'));
        $this->assertSame('R250', money(rtrim(rtrim('250.00', '0'), '.')));
        $this->assertSame('R1 500', money(1500, 'ZAR', 0));
        $this->assertSame('R12 345.60', money(12345.6, 'ZAR', 2));
        $this->assertSame('R', money(''));
    }

    public function testOtherCurrencies(): void
    {
        $this->assertSame('£1,234.50', money(1234.5, 'GBP', 2));
        $this->assertSame('$29.99', money('29.99', 'usd'));
        $this->assertSame('KSh 1,000', money(1000, 'KES', 0));
        $this->assertSame('CHF 12.00', money(12, 'CHF', 2));
    }
}

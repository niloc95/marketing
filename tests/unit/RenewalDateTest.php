<?php

use App\Services\VerificationService;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * The date a badge is paid through, which has to agree with the date PayFast
 * charges — otherwise the badge either outlives the payment or, far worse,
 * comes down while the customer is still being billed.
 *
 * PHP and PayFast disagree about what "one month after 31 January" means. PHP
 * overflows to 3 March; PayFast clamps to 28 February and then returns to the
 * 31st in March. These cases pin PayFast's answer, because PayFast is the one
 * taking the money.
 *
 * Unit, not database: addMonthsAnchored() is pure arithmetic, and testing it
 * through recordPayment() would put the clock in the way — nextPaidUntil()
 * resolves against today, so no fixed past date survives the trip.
 *
 * @internal
 */
final class RenewalDateTest extends CIUnitTestCase
{
    /** @var callable */
    private $addMonths;

    protected function setUp(): void
    {
        parent::setUp();
        $this->addMonths = $this->getPrivateMethodInvoker(new VerificationService(), 'addMonthsAnchored');
    }

    /**
     * @return array<string,array{0:string,1:int,2:int|null,3:string}>
     */
    public static function renewalDates(): array
    {
        return [
            // The ordinary case, and the overwhelming majority of subscribers.
            'mid-month is untouched' => ['2026-09-05', 1, 5, '2026-10-05'],

            // The cases PHP's `+1 month` gets wrong. Each of these used to
            // overflow into the following month.
            'Jan 31 clamps to the end of February' => ['2026-01-31', 1, 31, '2026-02-28'],
            'Jan 31 clamps to the 29th in a leap year' => ['2028-01-31', 1, 31, '2028-02-29'],
            'Aug 31 clamps to the end of September' => ['2026-08-31', 1, 31, '2026-09-30'],
            'Jan 30 clamps too' => ['2026-01-30', 1, 30, '2026-02-28'],

            // The reason the anchor is stored rather than re-read from the last
            // renewal: after February clamps, March must go back to the 31st.
            // Derived from the previous date alone this would give 28 March,
            // three days before PayFast charges.
            'the anchor returns after a clamp' => ['2026-02-28', 1, 31, '2026-03-31'],
            'and again through a 30-day month' => ['2026-03-31', 1, 31, '2026-04-30'],
            'and back out of one' => ['2026-04-30', 1, 31, '2026-05-31'],

            // Multi-month grants, which is how an admin activates an EFT payer
            // for a quarter.
            'three months from a month end' => ['2026-01-31', 3, 31, '2026-04-30'],
            'twelve months keeps the day' => ['2026-09-05', 12, 5, '2027-09-05'],

            // Year boundary.
            'December rolls into January' => ['2026-12-31', 1, 31, '2027-01-31'],

            // No anchor recorded — an EFT badge, or any row predating the
            // billing_anchor_day column. Falls back to the day it renews from,
            // still without overflowing.
            'no anchor falls back to the day of the month' => ['2026-09-05', 1, null, '2026-10-05'],
            'no anchor still clamps' => ['2026-01-31', 1, null, '2026-02-28'],

            // Defensive: a nonsense anchor must not produce a nonsense date.
            'an out-of-range anchor is ignored' => ['2026-09-05', 1, 99, '2026-10-05'],
            'a zero anchor is ignored' => ['2026-09-05', 1, 0, '2026-10-05'],
        ];
    }

    /**
     * @dataProvider renewalDates
     */
    public function testRenewalDateMatchesPayFastsSchedule(
        string $from,
        int $months,
        ?int $anchorDay,
        string $expected
    ): void {
        $this->assertSame($expected, ($this->addMonths)($from, $months, $anchorDay));
    }

    /**
     * The sequence that motivated all of this.
     *
     * A subscriber who signed up on 31 January is charged by PayFast on 28
     * February, 31 March and 30 April. Walking those charge dates through the
     * renewal calculation must leave the badge covered on every one of them —
     * each paid_until has to reach at least as far as the next charge.
     */
    public function testAMonthEndSubscriberIsNeverLeftUncovered(): void
    {
        $anchor  = 31;
        $charges = ['2026-01-31', '2026-02-28', '2026-03-31', '2026-04-30', '2026-05-31'];

        $paidUntil = null;

        foreach ($charges as $i => $charge) {
            $from      = ($paidUntil !== null && $paidUntil > $charge) ? $paidUntil : $charge;
            $paidUntil = ($this->addMonths)($from, 1, $anchor);

            $nextCharge = $charges[$i + 1] ?? null;
            if ($nextCharge !== null) {
                $this->assertGreaterThanOrEqual(
                    $nextCharge,
                    $paidUntil,
                    sprintf('paid through %s but PayFast charges again on %s', $paidUntil, $nextCharge)
                );
            }
        }

        // And it has not drifted off the anchor along the way.
        $this->assertSame('2026-06-30', $paidUntil);
    }
}

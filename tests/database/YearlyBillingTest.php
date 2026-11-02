<?php

use App\Libraries\PayFast;
use App\Models\DirectoryListingModel;
use App\Models\DirectoryVerificationModel;
use App\Services\DirectorySettings;
use App\Services\VerificationService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use Tests\Support\FreshDatabase;
use Tests\Support\WithEnv;

/**
 * Monthly or yearly billing for the badge and Plus, the monthly to yearly
 * switch, and the /pricing.json feed the brand site reads.
 *
 * Requires the `tests` database group — see the Tests section of README.md.
 *
 * @internal
 */
final class YearlyBillingTest extends CIUnitTestCase
{
    use FreshDatabase;
    use FeatureTestTrait;
    use WithEnv;

    private DirectoryVerificationModel $verifications;
    private DirectoryListingModel $listings;

    protected function setUp(): void
    {
        parent::setUp();
        // A developer's .env may still carry an older badge price.
        $this->setEnv('directory.verifiedMonthlyAmount', '30.00');
        $this->setEnv('directory.verifiedYearlyAmount', '300.00');
        (new DirectorySettings())->forget();
        $this->verifications = new DirectoryVerificationModel();
        $this->listings      = new DirectoryListingModel();
    }

    protected function tearDown(): void
    {
        $this->restoreEnv();
        \Config\Services::resetSingle('site');
        (new DirectorySettings())->forget();
        parent::tearDown();
    }

    // ---------------------------------------------------------------- prices

    public function testTheDefaultsAreThirtyAMonthAndThreeHundredAYear(): void
    {
        $config = new \Config\Directory();
        $this->assertSame('30.00', $config->verifiedMonthlyAmount);
        $this->assertSame('300.00', $config->verifiedYearlyAmount);

        $settings = new DirectorySettings();

        $this->assertSame('30.00', $settings->badgePrice());
        $this->assertSame('300.00', $settings->badgeYearlyPrice());
    }

    public function testYearlyPricesSaveAndNormaliseLikeMonthlyOnes(): void
    {
        $result = (new DirectorySettings())->save([
            'badge_price'           => '35',
            'badge_enabled'         => '1',
            'badge_price_yearly'    => 'R350,00',
            'plus_price_usd'        => '8.99',
            'usd_zar_rate'          => '18.00',
            'plus_price_usd_yearly' => '89.90',
        ], 'test');
        $this->assertTrue($result['ok'], $result['message']);

        $settings = new DirectorySettings();
        $this->assertSame('350.00', $settings->badgeYearlyPrice());
        $this->assertSame('89.90', $settings->plusYearlyPriceUsd());
        $this->assertSame('1618.20', $settings->plusYearlyRandAmount(), '89.90 x 18.00');
    }

    public function testAYearlyPriceOfZeroIsRefusedAndNothingSaves(): void
    {
        $result = (new DirectorySettings())->save(['badge_price' => '30', 'badge_price_yearly' => 'abc'], 'test');

        $this->assertFalse($result['ok']);
        $this->assertArrayHasKey('badge_price_yearly', $result['errors']);
        $this->assertSame('300.00', (new DirectorySettings())->badgeYearlyPrice());
    }

    public function testYearlyPlusIsNotOnSaleUntilPriced(): void
    {
        $this->goLiveWithPlus(yearly: null);

        $this->assertFalse((new VerificationService())->yearlyOffered(DirectoryVerificationModel::PLAN_INTERNATIONAL));
    }

    // -------------------------------------------------------------- checkout

    public function testCheckoutPricesTheRowForTheCycleAndMintsAFreshReference(): void
    {
        $svc = new VerificationService();
        $row = $this->approvedBadge('29.99', 'vb-old');

        $yearly = $svc->priceForCheckout($row, DirectoryVerificationModel::PLAN_BADGE, DirectoryVerificationModel::CYCLE_YEARLY);
        $this->assertSame('yearly', $yearly['billing_cycle']);
        $this->assertSame('300.00', $yearly['amount']);
        $this->assertNotSame('vb-old', $yearly['pf_m_payment_id'], 'PayFast refuses a reused reference with new terms');
        $this->assertSame('300.00', $this->verifications->find((int) $row['id'])['amount'], 'snapshotted on the row');

        // Back to monthly: today's monthly price, since the quote was replaced.
        $monthly = $svc->priceForCheckout($yearly, DirectoryVerificationModel::PLAN_BADGE, DirectoryVerificationModel::CYCLE_MONTHLY);
        $this->assertSame('monthly', $monthly['billing_cycle']);
        $this->assertSame('30.00', $monthly['amount']);
    }

    public function testMonthlyCheckoutKeepsTheQuotedPriceAndTheReference(): void
    {
        $row = $this->approvedBadge('29.99', 'vb-quoted');

        $same = (new VerificationService())->priceForCheckout($row, DirectoryVerificationModel::PLAN_BADGE, DirectoryVerificationModel::CYCLE_MONTHLY);

        $this->assertSame('29.99', (string) $same['amount']);
        $this->assertSame('vb-quoted', $same['pf_m_payment_id']);
    }

    public function testPayFastIsAskedForAnAnnualSubscriptionOnAYearlyRow(): void
    {
        $listing = ['display_name' => 'Yearly Co', 'email' => 'y@example.test', 'country' => 'South Africa'];
        $row     = ['id' => 7, 'listing_id' => 3, 'amount' => '300.00', 'pf_m_payment_id' => 'vb-7-x', 'billing_cycle' => 'yearly'];

        $fields = (new PayFast())->subscriptionFields($listing, $row);

        $this->assertSame((string) PayFast::FREQUENCY_ANNUAL, $fields['frequency']);
        $this->assertSame('300.00', $fields['recurring_amount']);
        $this->assertStringStartsWith('Yearly Verified Business badge for', $fields['item_description']);

        unset($row['billing_cycle']);
        $this->assertSame((string) PayFast::FREQUENCY_MONTHLY, (new PayFast())->subscriptionFields($listing, $row)['frequency'], 'an older row is monthly');
    }

    public function testTheCheckoutPageOffersBothCyclesAndSignsTheOnePicked(): void
    {
        $this->setEnv('directory.payfastMerchantId', '10000100');
        $this->setEnv('directory.payfastMerchantKey', '46f0cd694581a');
        $this->setEnv('directory.payfastSandbox', 'true');
        $row     = $this->approvedBadge('30.00', 'vb-page');
        $session = [\App\Controllers\Manage::SESSION_KEY => (int) $row['listing_id']];

        $monthly = (string) $this->withSession($session)->get('manage/verification/checkout')->getBody();
        $this->assertStringContainsString('cycle=yearly', $monthly);
        $this->assertStringContainsString('Save R60.00', $monthly);
        $this->assertStringContainsString('name="frequency" value="3"', $monthly);
        $this->assertStringContainsString('name="amount" value="30.00"', $monthly);

        $yearly = (string) $this->withSession($session)->get('manage/verification/checkout?cycle=yearly')->getBody();
        $this->assertStringContainsString('name="frequency" value="6"', $yearly);
        $this->assertStringContainsString('name="amount" value="300.00"', $yearly);
        $this->assertStringContainsString('and yearly after that', $yearly);
        $this->assertSame('yearly', $this->verifications->find((int) $row['id'])['billing_cycle']);
    }

    // --------------------------------------------------------------- payment

    public function testAYearlyPaymentPaysTwelveMonths(): void
    {
        $svc = new VerificationService();
        $row = $svc->priceForCheckout($this->approvedBadge('29.99', 'vb-y'), DirectoryVerificationModel::PLAN_BADGE, DirectoryVerificationModel::CYCLE_YEARLY);

        $this->assertSame('applied', $svc->recordPayment($row, 'pf-y-1', 'COMPLETE', 300.00, [
            'm_payment_id' => $row['pf_m_payment_id'], 'token' => 'tok-y', 'billing_date' => date('Y-m-d'),
        ]));

        $after = $this->verifications->find((int) $row['id']);
        $this->assertSame(date('Y-m-d', strtotime('+1 year')), $after['paid_until']);
        $this->assertSame($after['paid_until'], $this->listings->find((int) $row['listing_id'])['verified_until']);
    }

    public function testAMonthlyPaymentStillPaysOneMonth(): void
    {
        $svc = new VerificationService();
        $row = $this->approvedBadge('30.00', 'vb-m');

        $svc->recordPayment($row, 'pf-m-1', 'COMPLETE', 30.00, ['m_payment_id' => 'vb-m', 'token' => 'tok-m', 'billing_date' => date('Y-m-d')]);

        $this->assertSame(date('Y-m-d', strtotime('+1 month')), $this->verifications->find((int) $row['id'])['paid_until']);
    }

    // ---------------------------------------------------------------- switch

    public function testSwitchingToYearlyKeepsThePaidDaysAndStopsTheMonthlyMandate(): void
    {
        $payfast = $this->payFast(true);
        $svc     = new VerificationService($payfast);
        $row     = $this->activeMonthlyBadge('+20 days');

        $start = $svc->startYearlySwitch((int) $row['listing_id'], DirectoryVerificationModel::PLAN_BADGE);
        $this->assertTrue($start['ok'], $start['message']);
        $switchId = $start['verification']['switch_m_payment_id'];

        $pending = $this->verifications->find((int) $row['id']);
        $this->assertSame('monthly', $pending['billing_cycle'], 'nothing changes until the year is paid');
        $this->assertSame('vb-monthly', $pending['pf_m_payment_id']);

        $this->assertSame('applied', $svc->recordPayment($pending, 'pf-switch', 'COMPLETE', 300.00, [
            'm_payment_id' => $switchId, 'token' => 'tok-yearly', 'billing_date' => date('Y-m-d'),
        ]));

        $after = $this->verifications->find((int) $row['id']);
        $this->assertSame('yearly', $after['billing_cycle']);
        $this->assertSame('300.00', $after['amount']);
        $this->assertSame($switchId, $after['pf_m_payment_id']);
        $this->assertSame('tok-yearly', $after['pf_subscription_token']);
        $this->assertNull($after['switch_m_payment_id']);
        $this->assertSame(date('Y-m-d', strtotime('+20 days +1 year')), $after['paid_until'], 'the year runs on from the paid month');
        $this->assertSame(['tok-monthly'], $payfast->cancelled, 'the monthly mandate is cancelled');
    }

    public function testASwitchIsRefusedForAYearlyOrCancelledSubscription(): void
    {
        $svc = new VerificationService($this->payFast(true));
        $row = $this->activeMonthlyBadge('+5 days');

        $this->verifications->update((int) $row['id'], ['billing_cycle' => 'yearly']);
        $this->assertFalse($svc->startYearlySwitch((int) $row['listing_id'], DirectoryVerificationModel::PLAN_BADGE)['ok']);

        $this->verifications->update((int) $row['id'], ['billing_cycle' => 'monthly', 'cancelled_at' => date('Y-m-d H:i:s')]);
        $this->assertFalse($svc->startYearlySwitch((int) $row['listing_id'], DirectoryVerificationModel::PLAN_BADGE)['ok']);
    }

    public function testTheNotificationHandlerMatchesASwitchAndChecksItsOwnAmount(): void
    {
        $row = $this->activeMonthlyBadge('+10 days');
        $this->verifications->update((int) $row['id'], ['switch_m_payment_id' => 'vb-switch-y', 'switch_amount' => '300.00']);

        $found = $this->verifications->findBySwitchPaymentId('vb-switch-y');
        $this->assertNotNull($found);
        $this->assertSame((int) $row['id'], (int) $found['id']);
        $this->assertNull($this->verifications->findByPaymentId('vb-switch-y'), 'the monthly reference is untouched');
    }

    public function testYearlyRowsAreRemindedTwoWeeksAheadAndMonthlyThreeDays(): void
    {
        $svc     = new VerificationService();
        $monthly = $this->activeMonthlyBadge('+3 days');
        $yearly  = $this->activeMonthlyBadge('+14 days');
        $this->verifications->update((int) $yearly['id'], ['billing_cycle' => 'yearly']);

        $this->assertSame([(int) $monthly['id']], array_map('intval', array_column($svc->dueForRenewalReminder(3), 'id')));
        $this->assertSame([(int) $yearly['id']], array_map('intval', array_column($svc->dueForRenewalReminder(14, 'yearly'), 'id')));
        $this->assertSame([], $svc->dueForRenewalReminder(14), 'a yearly row is not reminded on the monthly schedule');
    }

    // ------------------------------------------------------------ the feed

    public function testThePricingFeedListsBothCyclesForTheBrandSite(): void
    {
        $this->goLiveWithPlus(yearly: '89.90');

        $response = $this->get('pricing.json');
        $response->assertStatus(200);
        $response->assertHeader('Access-Control-Allow-Origin', 'https://webscheduler.co.za');
        $response->assertHeader('Cache-Control', 'public, max-age=300');

        $body = json_decode($response->getJSON(), true);
        $this->assertSame('30.00', $body['za']['verified']['monthly']);
        $this->assertSame('300.00', $body['za']['verified']['yearly']);
        $this->assertSame('R30', $body['za']['verified']['display']);
        $this->assertSame('R300', $body['za']['verified']['displayYearly']);
        $this->assertSame('US$8.99', $body['intl']['plus']['display']);
        $this->assertSame('US$89.90', $body['intl']['plus']['displayYearly']);
        $this->assertSame('R250', $body['za']['profileSetup']['display']);
        $this->assertSame('R6.00', $body['za']['partner']['perMonth'], '20% of R30');
        $this->assertSame('R72.00', $body['za']['partner']['firstYear']);

        $raw = (string) $response->getJSON();
        $this->assertStringNotContainsString('18.2', $raw, 'never the exchange rate');
        $this->assertStringNotContainsString('rate', $raw);
    }

    public function testThePricingFeedIsNotServedOnApp(): void
    {
        $this->goLiveWithPlus(yearly: null);

        $this->withHeaders(['Host' => 'local.webscheduler.app'])->get('pricing.json')->assertStatus(404);
    }

    // --------------------------------------------------------------- helpers

    /** @return array<string,mixed> an approved, unpaid badge row */
    private function approvedBadge(string $amount, string $mPaymentId): array
    {
        $id = (int) $this->verifications->insert([
            'listing_id'      => $this->makeListing(),
            'plan'            => DirectoryVerificationModel::PLAN_BADGE,
            'state'           => DirectoryVerificationModel::STATE_APPROVED,
            'amount'          => $amount,
            'pf_m_payment_id' => $mPaymentId,
            'submitted_at'    => date('Y-m-d H:i:s'),
        ], true);

        return $this->verifications->find($id);
    }

    /** @return array<string,mixed> an active monthly badge paid until $paidUntil */
    private function activeMonthlyBadge(string $paidUntil): array
    {
        $id = (int) $this->verifications->insert([
            'listing_id'            => $this->makeListing(),
            'plan'                  => DirectoryVerificationModel::PLAN_BADGE,
            'state'                 => DirectoryVerificationModel::STATE_ACTIVE,
            'amount'                => '30.00',
            'paid_until'            => date('Y-m-d', strtotime($paidUntil)),
            'pf_m_payment_id'       => 'vb-monthly',
            'pf_subscription_token' => 'tok-monthly',
            'activated_at'          => date('Y-m-d H:i:s'),
            'submitted_at'          => date('Y-m-d H:i:s'),
        ], true);

        return $this->verifications->find($id);
    }

    private function makeListing(): int
    {
        return (int) $this->listings->insert([
            'display_name' => 'Yearly Test Co',
            'email'        => 'yearly-' . bin2hex(random_bytes(4)) . '@example.test',
            'slug'         => 'yearly-' . bin2hex(random_bytes(6)),
            'status'       => 'published',
        ], true);
    }

    private function goLiveWithPlus(?string $yearly): void
    {
        $this->setEnv('sites.app.enabled', 'true');
        \Config\Services::resetSingle('site');
        (new DirectorySettings())->save(array_filter([
            'international_price'   => '29.99',
            'international_enabled' => '1',
            'plus_price_usd'        => '8.99',
            'usd_zar_rate'          => '18.20',
            'plus_price_usd_yearly' => $yearly,
        ], static fn ($v) => $v !== null), 'test');
    }

    /** A PayFast whose subscription cancel answers $succeeds and records the token. */
    private function payFast(bool $succeeds): PayFast
    {
        return new class ($succeeds) extends PayFast {
            /** @var list<string> */
            public array $cancelled = [];

            public function __construct(private readonly bool $succeeds)
            {
                parent::__construct();
            }

            public function cancelSubscription(string $token): bool
            {
                $this->cancelled[] = $token;

                return $this->succeeds;
            }
        };
    }
}

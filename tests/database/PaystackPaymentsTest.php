<?php

use App\Controllers\Manage;
use App\Libraries\PayFast;
use App\Models\DirectoryListingModel;
use App\Models\DirectoryVerificationEventModel;
use App\Models\DirectoryVerificationItnRejectionModel;
use App\Models\DirectoryVerificationModel;
use App\Models\ProfileSetupOrderModel;
use App\Services\DirectorySettings;
use App\Services\PaystackPayments;
use App\Services\ProfileSetupService;
use App\Services\VerificationService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;
use Tests\Support\FakePaystack;
use Tests\Support\FreshDatabase;
use Tests\Support\WithEnv;

/**
 * Paystack for the badge, Plus and profile setup: the webhook's checks, first
 * payments, renewals, cancelling, the move from PayFast and the yearly switch,
 * the checkout and its plans. No test leaves the machine (FakePaystack).
 *
 * Requires the `tests` database group — see the Tests section of README.md.
 *
 * @internal
 */
final class PaystackPaymentsTest extends CIUnitTestCase
{
    use FreshDatabase;
    use FeatureTestTrait;
    use WithEnv;

    private const IP = '52.31.139.75';

    private FakePaystack $paystack;
    private DirectoryVerificationModel $verifications;
    private DirectoryListingModel $listings;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setEnv('directory.paystackSecretKey', FakePaystack::SECRET);
        $this->setEnv('directory.paymentProvider', 'paystack');
        $this->setEnv('directory.verifiedMonthlyAmount', '30.00');
        $this->setEnv('directory.verifiedYearlyAmount', '300.00');
        (new DirectorySettings())->forget();

        $this->paystack = new FakePaystack();
        Services::injectMock('paystack', $this->paystack);
        $this->verifications = new DirectoryVerificationModel();
        $this->listings      = new DirectoryListingModel();
    }

    protected function tearDown(): void
    {
        $this->restoreEnv();
        Services::resetSingle('paystack');
        Services::resetSingle('site');
        (new DirectorySettings())->forget();
        parent::tearDown();
    }

    // ------------------------------------------------------------- the webhook

    public function testAForgedWebhookIsRefusedAndWrittenDown(): void
    {
        $raw = json_encode(['event' => 'charge.success', 'data' => ['reference' => 'vb-1-x']]);

        $outcome = $this->payments()->handleWebhook($raw, hash_hmac('sha512', $raw, 'not our key'), self::IP);

        $this->assertStringStartsWith('rejected: signature', $outcome);
        $this->assertSame([], $this->paystack->callsOf('verify'), 'nothing is asked of Paystack for a forgery');
        $this->assertSame(1, (new DirectoryVerificationItnRejectionModel())->countAllResults());
    }

    public function testASignedWebhookFromAnotherAddressIsRefused(): void
    {
        $raw = json_encode(['event' => 'charge.success', 'data' => ['reference' => 'vb-1-x']]);

        $this->assertStringStartsWith('rejected: not from a Paystack address', $this->payments()->handleWebhook($raw, $this->sign($raw), '203.0.113.9'));
    }

    public function testTheWebhookRouteAnswers200ToAForgeryAndNeedsNoCsrfToken(): void
    {
        $raw = json_encode(['event' => 'charge.success', 'data' => ['reference' => 'x']]);

        $this->withBody($raw)
            ->withHeaders(['Content-Type' => 'application/json', 'X-Paystack-Signature' => 'forged'])
            ->call('post', 'paystack/webhook')
            ->assertStatus(200);

        $this->assertSame(1, (new DirectoryVerificationItnRejectionModel())->countAllResults());
    }

    // ---------------------------------------------------------- first payment

    public function testTheFirstPaymentActivatesTheBadgeAndKeepsTheMandate(): void
    {
        $row = $this->approvedBadge('30.00', 'vb-7-first');
        $this->charge('vb-7-first', 3000, 'CUS_a', 'PLN_monthly_3000', 9001);

        $outcome = $this->webhook('charge.success', ['reference' => 'vb-7-first']);

        $this->assertStringStartsWith('applied', $outcome);
        $after = $this->verifications->find((int) $row['id']);
        $this->assertSame(DirectoryVerificationModel::STATE_ACTIVE, $after['state']);
        $this->assertSame('paystack', $after['provider']);
        $this->assertSame('CUS_a', $after['paystack_customer_code']);
        $this->assertSame('PLN_monthly_3000', $after['paystack_plan_code']);
        $this->assertSame('SUB_fake', $after['paystack_subscription_code'], 'looked up once the subscription exists');
        $this->assertSame('etok_fake', $after['paystack_email_token']);
        $this->assertSame((int) date('j'), (int) $after['billing_anchor_day']);
        $this->assertNotNull($this->listings->find((int) $row['listing_id'])['verified_until']);
        $this->assertNotNull((new DirectoryVerificationEventModel())->where('pf_payment_id', 'paystack:9001')->first());
    }

    public function testTheCallbackAndTheWebhookBankTheChargeOnce(): void
    {
        $row = $this->approvedBadge('30.00', 'vb-8-once');
        $this->charge('vb-8-once', 3000, 'CUS_b', 'PLN_monthly_3000', 9002);

        $this->assertStringStartsWith('applied', $this->payments()->confirmReference('vb-8-once'));
        $paidUntil = $this->verifications->find((int) $row['id'])['paid_until'];

        $this->assertStringStartsWith('duplicate', $this->webhook('charge.success', ['reference' => 'vb-8-once']));
        $this->assertSame($paidUntil, $this->verifications->find((int) $row['id'])['paid_until'], 'not extended twice');
    }

    public function testAChargeForTheWrongAmountIsRefused(): void
    {
        $row = $this->approvedBadge('30.00', 'vb-9-cheap');
        $this->charge('vb-9-cheap', 100, 'CUS_c', 'PLN_monthly_100', 9003);

        $this->assertStringStartsWith('rejected: amount 1.00', $this->webhook('charge.success', ['reference' => 'vb-9-cheap']));
        $this->assertSame(DirectoryVerificationModel::STATE_APPROVED, $this->verifications->find((int) $row['id'])['state']);
    }

    public function testAnUnpaidChargeChangesNothing(): void
    {
        $row = $this->approvedBadge('30.00', 'vb-10-fail');
        $this->charge('vb-10-fail', 3000, 'CUS_d', 'PLN_monthly_3000', 9004, 'abandoned');

        $this->assertStringStartsWith('noted: abandoned', $this->payments()->confirmReference('vb-10-fail'));
        $this->assertSame(DirectoryVerificationModel::STATE_APPROVED, $this->verifications->find((int) $row['id'])['state']);
    }

    public function testPaystackNotAnsweringAsksForTheWebhookAgain(): void
    {
        $this->approvedBadge('30.00', 'vb-11-later');

        $this->assertStringStartsWith('retry', $this->webhook('charge.success', ['reference' => 'vb-11-later']));
    }

    // -------------------------------------------------------------- renewals

    public function testARenewalIsFoundByCustomerAndPlanAndExtendsTheTerm(): void
    {
        $row = $this->activePaystackBadge('+10 days', 'CUS_r', 'PLN_monthly_3000');
        $this->charge('T_paystack_made', 3000, 'CUS_r', 'PLN_monthly_3000', 9005);

        $this->assertStringContainsString('(renewal)', $this->webhook('charge.success', ['reference' => 'T_paystack_made']));

        $after = $this->verifications->find((int) $row['id']);
        $this->assertGreaterThan($row['paid_until'], $after['paid_until']);
        $this->assertSame('SUB_kept', $after['paystack_subscription_code'], 'a renewal keeps the mandate it has');
    }

    public function testSubscriptionCreateStoresTheCodeAndToken(): void
    {
        $row = $this->activePaystackBadge('+30 days', 'CUS_s', 'PLN_monthly_3000', subscription: null);

        $this->webhook('subscription.create', [
            'subscription_code' => 'SUB_new', 'email_token' => 'etok_new',
            'customer'          => ['customer_code' => 'CUS_s'], 'plan' => ['plan_code' => 'PLN_monthly_3000'],
        ]);

        $after = $this->verifications->find((int) $row['id']);
        $this->assertSame('SUB_new', $after['paystack_subscription_code']);
        $this->assertSame('etok_new', $after['paystack_email_token']);
    }

    public function testStoppingRenewalAtPaystackCancelsButKeepsThePaidTime(): void
    {
        $row = $this->activePaystackBadge('+20 days', 'CUS_t', 'PLN_monthly_3000');

        $this->assertStringStartsWith('cancelled', $this->webhook('subscription.not_renew', ['subscription_code' => 'SUB_kept']));

        $after = $this->verifications->find((int) $row['id']);
        $this->assertNotNull($after['cancelled_at']);
        $this->assertSame(DirectoryVerificationModel::STATE_ACTIVE, $after['state']);
        $this->assertSame($row['paid_until'], $after['paid_until'], 'the paid time stays');
    }

    // ------------------------------------------------------------- cancelling

    public function testCancellingAPaystackBadgeDisablesTheSubscription(): void
    {
        $row = $this->activePaystackBadge('+20 days', 'CUS_u', 'PLN_monthly_3000');

        $result = $this->billing()->cancelSubscription((int) $row['listing_id']);

        $this->assertTrue($result['ok'], $result['message']);
        $this->assertSame([['disable', 'SUB_kept', 'etok_kept']], $this->paystack->callsOf('disable'));
        $this->assertNotNull($this->verifications->find((int) $row['id'])['cancelled_at']);
    }

    public function testACancelPaystackWillNotConfirmRecordsNothing(): void
    {
        $row                             = $this->activePaystackBadge('+20 days', 'CUS_v', 'PLN_monthly_3000');
        $this->paystack->disableSucceeds = false;

        $this->assertFalse($this->billing()->cancelSubscription((int) $row['listing_id'])['ok']);
        $this->assertNull($this->verifications->find((int) $row['id'])['cancelled_at']);
    }

    // ------------------------------------------------- moving from PayFast

    public function testMovingFromPayFastKeepsThePaidDaysAndStopsPayFast(): void
    {
        $paidUntil = date('Y-m-d', strtotime('+12 days'));
        $id        = (int) $this->verifications->insert([
            'listing_id'            => $this->makeListing(),
            'plan'                  => DirectoryVerificationModel::PLAN_BADGE,
            'state'                 => DirectoryVerificationModel::STATE_ACTIVE,
            'amount'                => '29.99',
            'paid_until'            => $paidUntil,
            'pf_m_payment_id'       => 'vb-old',
            'pf_subscription_token' => 'tok-payfast',
            'billing_anchor_day'    => (int) date('j', strtotime($paidUntil)),
            'activated_at'          => date('Y-m-d H:i:s'),
            'submitted_at'          => date('Y-m-d H:i:s'),
        ], true);
        $payfast = $this->payFast();
        $billing = new VerificationService($payfast, $this->paystack);

        $this->assertCount(1, $billing->payfastMandatesToMove());
        $move = $billing->startMove((int) $this->verifications->find($id)['listing_id'], DirectoryVerificationModel::PLAN_BADGE);
        $this->assertTrue($move['ok'], $move['message']);
        $this->assertStringEndsWith('-m', $move['verification']['switch_m_payment_id']);

        // The checkout: same amount and cycle, a fresh '-m' reference.
        $listing = $this->listings->find((int) $move['verification']['listing_id']);
        $this->assertNotNull($billing->paystackCheckout($listing, $this->verifications->find($id), true));
        $sent = $this->paystack->callsOf('initialize')[0][1];
        $this->assertSame(2999, $sent['amountCents']);
        $this->assertSame('switch', $sent['metadata']['purpose']);
        $ref = $this->verifications->find($id)['switch_m_payment_id'];
        $this->assertSame($sent['reference'], $ref);

        $this->charge($ref, 2999, 'CUS_m', 'PLN_monthly_2999', 9006);
        $this->assertStringContainsString('(switch)', (new PaystackPayments($this->paystack, $billing))->confirmReference($ref));

        $after = $this->verifications->find($id);
        $this->assertSame('paystack', $after['provider']);
        $this->assertSame('monthly', $after['billing_cycle'], 'a move keeps the cycle');
        $this->assertSame('29.99', (string) $after['amount'], 'and the price');
        $this->assertSame(date('Y-m-d', strtotime($paidUntil . ' +1 month')), $after['paid_until'], 'the month starts after the paid days');
        $this->assertNull($after['switch_m_payment_id']);
        $this->assertSame(['tok-payfast'], $payfast->cancelled, 'PayFast stops once Paystack has the payment');
        $this->assertSame([], $billing->payfastMandatesToMove());
    }

    public function testAMoveIsNotOfferedOnceThePaymentIsOnPaystack(): void
    {
        $row = $this->activePaystackBadge('+20 days', 'CUS_w', 'PLN_monthly_3000');

        $this->assertFalse($this->billing()->startMove((int) $row['listing_id'], DirectoryVerificationModel::PLAN_BADGE)['ok']);
    }

    // --------------------------------------------------- monthly to yearly

    public function testAYearlySwitchOnPaystackDisablesTheMonthlySubscription(): void
    {
        $row     = $this->activePaystackBadge('+5 days', 'CUS_y', 'PLN_monthly_3000');
        $billing = $this->billing();

        $switch = $billing->startYearlySwitch((int) $row['listing_id'], DirectoryVerificationModel::PLAN_BADGE);
        $this->assertTrue($switch['ok'], $switch['message']);
        $billing->paystackCheckout($this->listings->find((int) $row['listing_id']), $this->verifications->find((int) $row['id']), true);
        $ref = $this->verifications->find((int) $row['id'])['switch_m_payment_id'];

        $this->paystack->subscription = ['code' => 'SUB_yearly', 'email_token' => 'etok_yearly'];
        $this->charge($ref, 30000, 'CUS_y', 'PLN_annually_30000', 9007);
        (new PaystackPayments($this->paystack, $billing))->confirmReference($ref);

        $after = $this->verifications->find((int) $row['id']);
        $this->assertSame('yearly', $after['billing_cycle']);
        $this->assertSame('PLN_annually_30000', $after['paystack_plan_code']);
        $this->assertSame('SUB_yearly', $after['paystack_subscription_code']);
        $this->assertSame([['disable', 'SUB_kept', 'etok_kept']], $this->paystack->callsOf('disable'), 'the old monthly subscription, not the new one');
    }

    // --------------------------------------------------------------- checkout

    public function testEachPlanIsMadeOnceAndNamesNoAmount(): void
    {
        $row     = $this->approvedBadge('30.00', 'vb-plan');
        $listing = $this->listings->find((int) $row['listing_id']);

        $this->assertSame(['url' => 'https://checkout.paystack.com/fake123', 'access_code' => 'fakeaccess123'], $this->billing()->paystackCheckout($listing, $row, false));
        $this->billing()->paystackCheckout($listing, $this->verifications->find((int) $row['id']), false);

        $this->assertCount(1, $this->paystack->plans, 'the second checkout reuses the cached plan');
        $this->assertSame('Verified Business badge, monthly', $this->paystack->plans[0]['name']);
        $this->assertSame(3000, $this->paystack->plans[0]['cents']);

        [$first, $second] = array_map(static fn (array $c) => $c[1], $this->paystack->callsOf('initialize'));
        $this->assertSame('PLN_monthly_3000', $first['planCode']);
        $this->assertNotSame($first['reference'], $second['reference'], 'Paystack refuses a reference it has seen');
        $this->assertStringContainsString('manage/verification/paystack', $first['callbackUrl']);
    }

    public function testThePayButtonSendsTheOwnerToPaystack(): void
    {
        $row = $this->approvedBadge('30.00', 'vb-button');

        $result = $this->withSession([Manage::SESSION_KEY => (int) $row['listing_id']])
            ->post('manage/verification/pay', ['reference' => 'vb-button', csrf_token() => csrf_hash()]);

        $result->assertRedirectTo('https://checkout.paystack.com/fake123');
    }

    public function testOurCheckoutScriptGetsTheAccessCodeAndAFreshToken(): void
    {
        $row = $this->approvedBadge('30.00', 'vb-overlay');

        $result = $this->withSession([Manage::SESSION_KEY => (int) $row['listing_id']])
            ->withHeaders(['Accept' => 'application/json'])
            ->post('manage/verification/pay', ['reference' => 'vb-overlay', csrf_token() => csrf_hash()]);

        $result->assertStatus(200);
        $json = json_decode((string) $result->response()->getBody(), true);
        $this->assertSame('fakeaccess123', $json['accessCode']);
        $this->assertSame('https://checkout.paystack.com/fake123', $json['url'], 'the hosted page, if the window cannot load');
        $this->assertSame(base_url('manage/verification/paystack'), $json['returnUrl']);
        $this->assertSame(csrf_token(), $json['csrf']['name']);
        $this->assertNotSame('', (string) $json['csrf']['hash'], 'a second press after closing the window needs it');
    }

    public function testOurCheckoutScriptIsToldWhyWhenAReferenceIsNotItsOwn(): void
    {
        $this->approvedBadge('30.00', 'vb-not-yours');

        $result = $this->withSession([Manage::SESSION_KEY => $this->makeListing()])
            ->withHeaders(['Accept' => 'application/json'])
            ->post('manage/verification/pay', ['reference' => 'vb-not-yours', csrf_token() => csrf_hash()]);

        $result->assertStatus(422);
        $this->assertStringContainsString('expired', json_decode((string) $result->response()->getBody(), true)['error']);
        $this->assertSame([], $this->paystack->callsOf('initialize'));
    }

    public function testTheCheckoutPagesMayLoadPaystacksCardWindow(): void
    {
        $csp = new \Config\ContentSecurityPolicy();

        $this->assertContains('https://js.paystack.co', (array) $csp->scriptSrcElem);
        $this->assertContains('https://checkout.paystack.com', (array) $csp->frameSrc);
    }

    public function testThePayButtonRefusesAnotherListingsReference(): void
    {
        $row   = $this->approvedBadge('30.00', 'vb-theirs');
        $other = $this->makeListing();

        $this->withSession([Manage::SESSION_KEY => $other])
            ->post('manage/verification/pay', ['reference' => 'vb-theirs', csrf_token() => csrf_hash()])
            ->assertRedirectTo(base_url('manage/edit'));

        $this->assertSame([], $this->paystack->callsOf('initialize'));
        $this->assertSame('vb-theirs', $this->verifications->find((int) $row['id'])['pf_m_payment_id']);
    }

    public function testWithoutAKeyNewCheckoutsStayOnPayFast(): void
    {
        $this->setEnv('directory.paystackSecretKey', '');

        $this->assertSame('payfast', (new \Config\Directory())->paymentProvider());
    }

    // ---------------------------------------------------------- profile setup

    public function testAProfileSetupChargeMarksTheOrderPaid(): void
    {
        $orders = new ProfileSetupOrderModel();
        $id     = (int) $orders->insert([
            'reference' => 'ps-old', 'name' => 'Thandi Mokoena', 'business_name' => 'Thandi Hair',
            'email'     => 'thandi@example.test', 'phone' => '0821234567', 'amount' => '250.00',
            'status'    => ProfileSetupOrderModel::STATUS_AWAITING_PAYMENT, 'terms_at' => date('Y-m-d H:i:s'),
        ], true);

        $this->assertNotNull((new ProfileSetupService())->paystackCheckout($orders->find($id)));
        $order = $orders->find($id);
        $this->assertSame('paystack', $order['provider']);
        $this->assertNull($this->paystack->callsOf('initialize')[0][1]['planCode'], 'paid once, no plan');

        $this->paystack->transactions[$order['reference']] = [
            'id' => 9008, 'status' => 'success', 'reference' => $order['reference'], 'amount' => 25000,
            'currency' => 'ZAR', 'metadata' => ['order_id' => $id], 'customer' => ['customer_code' => 'CUS_p'], 'plan' => null,
        ];
        $this->assertStringStartsWith('paid', $this->webhook('charge.success', ['reference' => $order['reference']]));
        $this->assertSame(ProfileSetupOrderModel::STATUS_PAID, $orders->find($id)['status']);
        $this->assertSame('paystack:9008', $orders->find($id)['pf_payment_id']);
    }

    // ---------------------------------------------------------------- helpers

    private function payments(): PaystackPayments
    {
        return new PaystackPayments($this->paystack, $this->billing());
    }

    private function billing(): VerificationService
    {
        return new VerificationService($this->payFast(), $this->paystack);
    }

    /** @param array<string,mixed> $data */
    private function webhook(string $event, array $data): string
    {
        $raw = json_encode(['event' => $event, 'data' => $data]);

        return $this->payments()->handleWebhook($raw, $this->sign($raw), self::IP);
    }

    private function sign(string $raw): string
    {
        return hash_hmac('sha512', $raw, FakePaystack::SECRET);
    }

    /** What verify() will answer for $reference. */
    private function charge(string $reference, int $cents, string $customer, string $plan, int $id, string $status = 'success'): void
    {
        $this->paystack->transactions[$reference] = [
            'id' => $id, 'status' => $status, 'reference' => $reference, 'amount' => $cents, 'currency' => 'ZAR',
            'paid_at' => date('c'), 'customer' => ['customer_code' => $customer], 'plan' => $plan, 'metadata' => [],
        ];
    }

    /** @return array<string,mixed> */
    private function approvedBadge(string $amount, string $reference): array
    {
        $id = (int) $this->verifications->insert([
            'listing_id'      => $this->makeListing(),
            'plan'            => DirectoryVerificationModel::PLAN_BADGE,
            'state'           => DirectoryVerificationModel::STATE_APPROVED,
            'amount'          => $amount,
            'pf_m_payment_id' => $reference,
            'submitted_at'    => date('Y-m-d H:i:s'),
        ], true);

        return $this->verifications->find($id);
    }

    /** @return array<string,mixed> */
    private function activePaystackBadge(string $paidUntil, string $customer, string $plan, ?string $subscription = 'SUB_kept'): array
    {
        $id = (int) $this->verifications->insert([
            'listing_id'                 => $this->makeListing(),
            'plan'                       => DirectoryVerificationModel::PLAN_BADGE,
            'state'                      => DirectoryVerificationModel::STATE_ACTIVE,
            'amount'                     => '30.00',
            'paid_until'                 => date('Y-m-d', strtotime($paidUntil)),
            'pf_m_payment_id'            => 'vb-active-' . bin2hex(random_bytes(3)),
            'provider'                   => 'paystack',
            'paystack_customer_code'     => $customer,
            'paystack_plan_code'         => $plan,
            'paystack_subscription_code' => $subscription,
            'paystack_email_token'       => $subscription === null ? null : 'etok_kept',
            'activated_at'               => date('Y-m-d H:i:s'),
            'submitted_at'               => date('Y-m-d H:i:s'),
        ], true);

        return $this->verifications->find($id);
    }

    private function makeListing(): int
    {
        return (int) $this->listings->insert([
            'display_name' => 'Paystack Test Co',
            'email'        => 'paystack-' . bin2hex(random_bytes(4)) . '@example.test',
            'slug'         => 'paystack-' . bin2hex(random_bytes(6)),
            'status'       => 'published',
            'country'      => 'South Africa',
        ], true);
    }

    /** A PayFast whose cancel succeeds and writes down the token. */
    private function payFast(): PayFast
    {
        return new class () extends PayFast {
            /** @var list<string> */
            public array $cancelled = [];

            public function cancelSubscription(string $token): bool
            {
                $this->cancelled[] = $token;

                return true;
            }
        };
    }
}

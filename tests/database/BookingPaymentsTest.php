<?php

use App\Libraries\PayFast;
use App\Models\Bookings\BookingAppointmentModel as A;
use App\Models\Bookings\BookingPaymentModel as P;
use App\Models\Bookings\BusinessMemberModel;
use App\Models\DirectoryCategoryModel;
use App\Models\DirectoryListingModel;
use App\Services\Bookings\AccountService;
use App\Services\Bookings\BookingSettings;
use App\Services\Bookings\BookingSetupService;
use App\Services\Bookings\BusinessSession;
use App\Services\Bookings\CatalogueService;
use App\Services\Bookings\DashboardService;
use App\Services\Bookings\Notifications\BookingIntegrations;
use App\Services\Bookings\Payments\DepositService;
use App\Services\Bookings\Payments\GatewayConfig;
use App\Services\Bookings\PublicBookingService;
use App\Services\Bookings\SetupCheck;
use App\Services\Bookings\TeamService;
use CodeIgniter\Events\Events;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;
use Tests\Support\FakePaystack;
use Tests\Support\FreshDatabase;
use Tests\Support\WithEnv;

/**
 * Phase 8 of online bookings: deposits paid into the business's own PayFast
 * or Paystack account (decision D6), nothing through us.
 *
 * The deposit itself (percentage, R5 floor, rand only), booking without one
 * when no account is on, the hold and its release, Paystack checked on the
 * way back and by the sweep, PayFast's notification checked with the
 * business's own passphrase (and not another's), a payment that comes in
 * late, marking paid and refunded from the business side within scope, the
 * Payments tab with write only keys and the admins' email, and the pages.
 *
 * No test leaves the machine: Paystack is FakePaystack, PayFast a subclass
 * whose source address and POST-back checks say yes. Page tests run on the
 * real clock with two days' notice, so every time offered is in the future.
 *
 * @internal
 */
final class BookingPaymentsTest extends CIUnitTestCase
{
    use FeatureTestTrait;
    use FreshDatabase;
    use WithEnv;

    private const PASSPHRASE = 'salon lumi pass';

    private DirectoryListingModel $listings;

    private int $categoryId;

    private int $listingId;

    private int $accountId;

    private int $sarah;

    private int $james;

    private int $serviceId;

    private FakePaystack $paystack;

    /** @var list<array<string,mixed>> */
    private array $sent = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setEnv('bookings.enabled', 'true');
        config('Encryption')->key = hex2bin(str_repeat('ab', 32));
        Services::resetSingle('encrypter');

        $this->listings   = new DirectoryListingModel();
        $this->categoryId = (int) (new DirectoryCategoryModel())->insert([
            'name' => 'Hair Salon', 'slug' => 'hair-salon', 'group_name' => 'Beauty & Wellness', 'is_active' => 1,
        ], true);
        [$this->listingId, $this->accountId] = $this->business('salon-lumi');

        $team        = new TeamService($this->listingId);
        $this->sarah = (int) $team->providers()[0]['id'];
        $this->james = (int) $team->saveProvider(null, ['name' => 'James Okafor', 'bookable' => 1], null)['id'];
        foreach ([$this->sarah, $this->james] as $id) {
            $team->saveHours($id, array_fill_keys(range(1, 7), ['active' => 1, 'start' => '09:00', 'end' => '17:00']), null);
        }
        $this->serviceId = $this->service($this->listingId, [$this->sarah, $this->james], 'Cut', '250', 20);
        BookingSettings::for($this->listingId)->save(['booking.lead_minutes' => '2880', 'booking.default_appointment_status' => 'confirmed'], null);

        $this->paystack = new FakePaystack();
        Services::injectMock('bookingPaystack', fn (GatewayConfig $c): FakePaystack => $this->paystack);
        Services::injectMock('bookingPayFast', static fn (GatewayConfig $c): PayFast => new class ($c) extends PayFast {
            public function isValidSourceIp(string $ip): bool
            {
                return true;
            }

            public function validateWithPayFast(string $rawBody): bool
            {
                return true;
            }
        });
        Events::on('email', function (array $archive): void {
            $this->sent[] = $archive;
        });
    }

    protected function tearDown(): void
    {
        Events::removeAllListeners('email');
        Services::reset(true);
        $this->restoreEnv();
        parent::tearDown();
    }

    // The deposit

    public function testTheDepositIsAPercentageOfThePriceInRandAtLeastFiveRand(): void
    {
        $this->assertSame(5000, DepositService::amountFor(['deposit_pct' => 20, 'price_cents' => 25000, 'currency' => 'ZAR']));
        $this->assertSame(3333, DepositService::amountFor(['deposit_pct' => 33, 'price_cents' => 10100, 'currency' => 'ZAR']));
        $this->assertNull(DepositService::amountFor(['deposit_pct' => 10, 'price_cents' => 4000, 'currency' => 'ZAR']), 'R4 is under PayFast\'s smallest payment');
        $this->assertNull(DepositService::amountFor(['deposit_pct' => 0, 'price_cents' => 25000, 'currency' => 'ZAR']));
        $this->assertNull(DepositService::amountFor(['deposit_pct' => 20, 'price_cents' => null, 'currency' => 'ZAR']));
        $this->assertNull(DepositService::amountFor(['deposit_pct' => 20, 'price_cents' => 25000, 'currency' => 'USD']), 'Rand only');
    }

    public function testWithoutAnAccountTheBookingGoesThroughWithoutADeposit(): void
    {
        $this->assertContains('deposit_no_account', array_column(SetupCheck::problems($this->listingId), 'code'));
        $page = $this->get('directory/salon-lumi/book?service=' . $this->serviceId);
        $page->assertOK();
        $page->assertDontSee('deposit online');

        $this->bookOnline();
        $a = A::forBusiness($this->listingId)->first();
        $this->assertSame(['confirmed', 'none', null], [$a['status'], $a['payment_status'], $a['payment_due_by']]);

        // Switched off counts as no account.
        $this->connectPaystack(false);
        $this->assertNull(DepositService::gatewayFor($this->listingId));
        $this->connectPaystack();
        $this->assertNotContains('deposit_no_account', array_column(SetupCheck::problems($this->listingId), 'code'));
    }

    // Paystack

    public function testPaystackBookPayAndComeBack(): void
    {
        $this->connectPaystack();
        $page = $this->get('directory/salon-lumi/book?service=' . $this->serviceId);
        $page->assertSee('R50 deposit online');

        $token = $this->bookOnline();
        $a     = A::forBusiness($this->listingId)->first();
        $this->assertSame(['pending', 'due', 5000], [$a['status'], $a['payment_status'], (int) $a['payment_amount_cents']]);
        $this->assertEqualsWithDelta(time() + 30 * 60, strtotime($a['payment_due_by'] . ' UTC'), 5, 'Held for 30 minutes');
        $this->assertSame(0, $this->queued(), 'No messages until the deposit is in');
        $this->assertSame([], (new DashboardService($this->listing(), null))->waiting(), 'Not waiting for the business');

        $manage = $this->get('book/' . $token . '?new=1');
        $manage->assertSee('Nearly done: pay the deposit below to keep this time.');
        $manage->assertSee('Pay R50');
        $manage->assertSee('This business is testing its payments');

        $pay = $this->post('book/' . $token . '/pay', [csrf_token() => csrf_hash()]);
        $pay->assertRedirectTo($this->paystack->checkoutUrl);
        $payment = P::forBusiness($this->listingId)->first();
        $this->assertSame([$a['reference'] . '-P1', 'paystack', 'started', 5000, 1], [$payment['reference'], $payment['gateway'], $payment['status'], (int) $payment['amount_cents'], (int) $payment['test']]);
        $init = $this->paystack->callsOf('initialize')[0][1];
        $this->assertSame(['alice@example.test', 5000, $payment['reference']], [$init['email'], $init['amountCents'], $init['reference']]);
        $this->assertStringEndsWith('/book/paid/' . $payment['hash'], $init['callbackUrl']);

        // Back before Paystack has it: still due, nothing changes.
        $this->get('book/paid/' . $payment['hash'])->assertRedirect();
        $this->assertSame('due', A::forBusiness($this->listingId)->find($a['id'])['payment_status']);

        $this->paystack->transactions[$payment['reference']] = ['status' => 'success', 'amount' => 5000, 'currency' => 'ZAR', 'reference' => $payment['reference'], 'id' => 991];
        $back = $this->get('book/paid/' . $payment['hash']);
        $back->assertRedirect();
        $this->assertStringContainsString('/book/customer/', $back->getRedirectUrl());
        $a = A::forBusiness($this->listingId)->find($a['id']);
        $this->assertSame(['confirmed', 'paid', null], [$a['status'], $a['payment_status'], $a['payment_due_by']]);
        $this->assertSame(['paid', '991'], [P::forBusiness($this->listingId)->first()['status'], P::forBusiness($this->listingId)->first()['gateway_id']]);
        $this->assertGreaterThan(0, $this->queued(), 'The booking messages go out now');

        // Coming back twice changes nothing more.
        $before = $this->queued();
        $this->get('book/paid/' . $payment['hash']);
        $this->assertSame($before, $this->queued());
    }

    public function testPaystackWrongAmountOrCurrencyIsNotPaid(): void
    {
        $this->connectPaystack();
        $a       = $this->dueAppointment();
        $payment = $this->startPayment($a);
        $svc     = new DepositService($this->listing());
        $this->paystack->transactions[$payment['reference']] = ['status' => 'success', 'amount' => 500, 'currency' => 'ZAR', 'reference' => $payment['reference']];
        $svc->checkPaystack($payment);
        $this->paystack->transactions[$payment['reference']] = ['status' => 'success', 'amount' => 5000, 'currency' => 'NGN', 'reference' => $payment['reference']];
        $svc->checkPaystack($payment);
        $this->assertSame('due', A::forBusiness($this->listingId)->find($a['id'])['payment_status']);

        $this->paystack->transactions[$payment['reference']] = ['status' => 'failed', 'amount' => 5000, 'currency' => 'ZAR', 'reference' => $payment['reference']];
        $svc->checkPaystack($payment);
        $this->assertSame('failed', P::forBusiness($this->listingId)->find($payment['id'])['status']);
    }

    public function testEmailIsAskedWhenADepositIsDue(): void
    {
        BookingSettings::for($this->listingId)->save(['booking.email_display' => '0', 'booking.email_required' => '0'], null);
        $this->connectPaystack();
        $this->assertContains('email', array_column((new PublicBookingService($this->listing()))->customerFields(true), 'name'));
        $this->assertNotContains('email', array_column((new PublicBookingService($this->listing()))->customerFields(false), 'name'));

        $slot = $this->firstSlot($this->sarah);
        $res  = $this->post('directory/salon-lumi/book', $this->form($slot['start'], $this->sarah, ['email' => '']));
        $res->assertRedirect();
        $this->assertSame(0, A::forBusiness($this->listingId)->countAllResults());
    }

    // The hold and the sweep

    public function testTheSweepFreesAnUnpaidTimeAndPicksUpAPaystackPayment(): void
    {
        $this->connectPaystack();
        $unpaid  = $this->dueAppointment();
        $paid    = $this->dueAppointment($this->james);
        $payment = $this->startPayment($paid);
        $this->paystack->transactions[$payment['reference']] = ['status' => 'success', 'amount' => 5000, 'currency' => 'ZAR', 'reference' => $payment['reference']];
        db_connect()->table('xs_booking_payments')->where('id', $payment['id'])->update(['created_at' => gmdate('Y-m-d H:i:s', time() - 300)]);

        $this->assertSame(['checked' => 1, 'released' => 0], DepositService::sweep(), 'Nothing is late yet');
        $this->assertSame('paid', A::forBusiness($this->listingId)->find($paid['id'])['payment_status']);

        $stats = DepositService::sweep(new DateTimeImmutable('+31 minutes'));
        $this->assertSame(1, $stats['released']);
        $row = A::forBusiness($this->listingId)->find($unpaid['id']);
        $this->assertSame(['cancelled', 'expired'], [$row['status'], $row['payment_status']]);
        $this->assertSame(0, $this->queued() - $this->queuedFor($paid['id']), 'Freeing a time sends nothing');
        $this->assertSame('confirmed', A::forBusiness($this->listingId)->find($paid['id'])['status'], 'A paid booking is never freed');

        // The customer's page says so, and the time can be booked again.
        $view = (new DepositService($this->listing()))->forCustomer($row);
        $this->assertFalse($view['waiting']);
        $this->assertStringContainsString('not paid in time', $view['text']);
        $this->assertNull((new App\Services\Bookings\AvailabilityService($this->listing()))->problem($this->sarah, $this->serviceId, (string) $row['start_at'], false));
    }

    public function testAPaymentAfterTheTimeWasFreedComesBackOrIsMarkedToRefund(): void
    {
        $this->connectPaystack();
        $svc = new DepositService($this->listing());

        // Still free: the booking comes back.
        $a       = $this->dueAppointment();
        $payment = $this->startPayment($a);
        DepositService::sweep(new DateTimeImmutable('+31 minutes'));
        $svc->paid($payment, 'late1', 'paystack');
        $row = A::forBusiness($this->listingId)->find($a['id']);
        $this->assertSame(['confirmed', 'paid'], [$row['status'], $row['payment_status']]);

        // Taken by someone else meanwhile: kept cancelled, deposit to refund.
        $b        = $this->dueAppointment($this->james);
        $payment2 = $this->startPayment($b);
        DepositService::sweep(new DateTimeImmutable('+31 minutes'));
        $this->appointmentAt($this->james, (string) $b['start_at']);
        $svc->paid($payment2, 'late2', 'paystack');
        $row = A::forBusiness($this->listingId)->find($b['id']);
        $this->assertSame(['cancelled', 'refund_due'], [$row['status'], $row['payment_status']]);

        $this->assertSame([(int) $b['id']], array_map('intval', array_column($svc->toRefund(null), 'id')));
        $alerts = (new DashboardService($this->listing(), null))->alerts($this->member());
        $this->assertContains('refund', array_column($alerts, 'code'));
        $this->assertNotContains('refund', array_column((new DashboardService($this->listing(), [$this->sarah]))->alerts($this->member()), 'code'), 'James\'s deposit is out of Sarah\'s scope');
    }

    // PayFast

    public function testPayFastFormAndNotification(): void
    {
        $this->connectPayFast();
        $token = $this->bookOnline();
        $a     = A::forBusiness($this->listingId)->first();

        $page = $this->post('book/' . $token . '/pay', [csrf_token() => csrf_hash()]);
        $page->assertOK();
        $page->assertSee('Continue to PayFast');
        $page->assertSee('https://sandbox.payfast.co.za/eng/process');
        $this->assertStringNotContainsString('name="' . config('Honeypot')->name . '"', (string) $page->response()->getBody(), 'No honeypot field in the signed form');
        $payment = P::forBusiness($this->listingId)->first();
        $fields  = $this->formFields((string) $page->response()->getBody());
        $this->assertSame(['10000100', '50.00', $payment['reference']], [$fields['merchant_id'], $fields['amount'], $fields['m_payment_id']]);
        $this->assertStringEndsWith('/book/payfast/notify', $fields['notify_url']);
        $this->assertSame((new PayFast($this->gateway()))->signature($fields), $fields['signature'], 'Signed with the business passphrase');

        // A forged notice (wrong passphrase) changes nothing; the real one pays.
        $this->post('book/payfast/notify', $this->itnFields($payment, 'another passphrase'))->assertStatus(200);
        $this->assertSame('due', A::forBusiness($this->listingId)->find($a['id'])['payment_status']);
        $this->post('book/payfast/notify', $this->itnFields($payment, self::PASSPHRASE))->assertStatus(200);
        $a = A::forBusiness($this->listingId)->find($a['id']);
        $this->assertSame(['confirmed', 'paid'], [$a['status'], $a['payment_status']]);
        $this->assertSame('pf-1234', P::forBusiness($this->listingId)->first()['gateway_id']);

        $queued = $this->queued();
        $this->post('book/payfast/notify', $this->itnFields($payment, self::PASSPHRASE))->assertStatus(200);
        $this->assertSame($queued, $this->queued(), 'PayFast sending it again changes nothing');
    }

    public function testPayFastNoticeIsRefusedForAWrongAmountOrAnotherMerchant(): void
    {
        $this->connectPayFast();
        $a       = $this->dueAppointment();
        $payment = $this->startPayment($a);

        $this->assertSame('wrong amount', DepositService::payfastNotified($this->itn($payment, self::PASSPHRASE, ['amount_gross' => '5.00']), '1.2.3.4'));
        $this->assertSame('another merchant', DepositService::payfastNotified($this->itn($payment, self::PASSPHRASE, ['merchant_id' => '99999']), '1.2.3.4'));
        $this->assertSame('unknown payment', DepositService::payfastNotified($this->itn(['reference' => 'WSB-BOOK-999999-P1', 'amount_cents' => 5000], self::PASSPHRASE), '1.2.3.4'));
        $this->assertSame('due', A::forBusiness($this->listingId)->find($a['id'])['payment_status']);
    }

    public function testAnotherBusinessesPassphraseCannotPayThisDeposit(): void
    {
        $this->connectPayFast();
        [$otherId]  = $this->business('other-salon');
        (new BookingIntegrations($otherId))->save('payment', 'payfast', ['merchant_id' => '10000100', 'merchant_key' => '46f0cd694581a', 'passphrase' => 'the other one', 'mode' => 'test'], true, null);

        $payment = $this->startPayment($this->dueAppointment());
        $this->assertSame('bad signature', DepositService::payfastNotified($this->itn($payment, 'the other one'), '1.2.3.4'));
    }

    // The business's side

    public function testMarkPaidAndRefundedWithinScope(): void
    {
        $this->connectPaystack();
        $a = $this->dueAppointment();

        $res = $this->signedIn()->post('business/api/appointments/' . $a['id'] . '/payment', [csrf_token() => csrf_hash(), 'action' => 'paid']);
        $res->assertOK();
        $row = A::forBusiness($this->listingId)->find($a['id']);
        $this->assertSame(['confirmed', 'paid'], [$row['status'], $row['payment_status']]);
        $manual = P::forBusiness($this->listingId)->where('gateway', 'manual')->first();
        $this->assertSame(['paid', 5000], [$manual['status'], (int) $manual['amount_cents']]);

        $detail = json_decode((string) $this->signedIn()->get('business/api/appointments/' . $a['id'])->response()->getBody(), true)['data'];
        $this->assertSame(['paid', 'Deposit paid', true, true], [$detail['payment']['status'], $detail['payment']['label'], $detail['payment']['can_refund'], $detail['can_payments']]);

        $this->signedIn()->post('business/api/appointments/' . $a['id'] . '/payment', [csrf_token() => csrf_hash(), 'action' => 'refunded'])->assertOK();
        $this->assertSame('refunded', A::forBusiness($this->listingId)->find($a['id'])['payment_status']);
        $this->assertSame('refunded', P::forBusiness($this->listingId)->find($manual['id'])['status']);

        // Reception has no payments at all.
        $desk = $this->reception();
        $this->signedIn((int) $desk['account_id'])->post('business/api/appointments/' . $a['id'] . '/payment', [csrf_token() => csrf_hash(), 'action' => 'paid'])->assertStatus(403);
        $this->signedIn((int) $desk['account_id'])->get('business/payments')->assertStatus(403);
    }

    public function testATeamMemberCannotMarkSomeoneElsesDeposit(): void
    {
        $this->connectPaystack();
        $a       = $this->dueAppointment($this->james);
        $account = (new AccountService())->findOrCreate('sarah@example.test', 'Sarah');
        $member  = (int) BusinessMemberModel::forBusiness($this->listingId)->insert(['account_id' => $account['id'], 'role' => 'provider', 'status' => 'active'], true);
        (new TeamService($this->listingId))->saveProvider($this->sarah, ['name' => 'Sarah', 'bookable' => 1, 'member_id' => $member], null);

        $this->signedIn((int) $account['id'])->post('business/api/appointments/' . $a['id'] . '/payment', [csrf_token() => csrf_hash(), 'action' => 'paid'])->assertStatus(404);
        $this->assertSame('due', A::forBusiness($this->listingId)->find($a['id'])['payment_status']);
    }

    public function testThePaymentsTabKeepsKeysWriteOnlyAndTellsTheAdmins(): void
    {
        $bad = $this->signedIn()->post('business/settings/payments', [csrf_token() => csrf_hash(), 'provider' => 'paystack', 'cfg' => ['paystack' => ['secret_key' => 'pk_live_nope']], 'active' => '1', 'hold_minutes' => '30']);
        $bad->assertRedirect();
        $this->assertNull((new BookingIntegrations($this->listingId))->account('payment'));

        $key = 'sk_live_' . str_repeat('a1', 15);
        $this->signedIn()->post('business/settings/payments', [csrf_token() => csrf_hash(), 'provider' => 'paystack', 'cfg' => ['paystack' => ['secret_key' => $key]], 'active' => '1', 'hold_minutes' => '45'])->assertRedirect();
        $row = db_connect()->table('xs_booking_integrations')->where('listing_id', $this->listingId)->where('channel', 'payment')->get()->getRowArray();
        $this->assertStringNotContainsString($key, (string) $row['config'], 'Encrypted');
        $this->assertSame($key, (new BookingIntegrations($this->listingId))->account('payment')['config']['secret_key']);
        $this->assertSame(45, BookingSettings::for($this->listingId)->int('payments.hold_minutes'));
        $this->assertCount(1, $this->sent);
        $this->assertStringContainsString('Payment account changed', (string) $this->sent[0]['subject']);
        $this->assertStringNotContainsString($key, (string) $this->sent[0]['body']);

        $tab = $this->signedIn()->get('business/settings/payments');
        $tab->assertOK();
        $tab->assertSee('Saved. Leave empty to keep it.');
        $tab->assertDontSee($key);

        // Saving again with the key left empty keeps it, and tells nobody.
        $this->signedIn()->post('business/settings/payments', [csrf_token() => csrf_hash(), 'provider' => 'paystack', 'cfg' => ['paystack' => ['secret_key' => '']], 'active' => '1', 'hold_minutes' => '45'])->assertRedirect();
        $this->assertSame($key, (new BookingIntegrations($this->listingId))->account('payment')['config']['secret_key']);
        $this->assertCount(1, $this->sent);
    }

    public function testNoAccountIsSavedWithoutAnEncryptionKey(): void
    {
        config('Encryption')->key = '';
        Services::resetSingle('encrypter');
        $errors = (new BookingIntegrations($this->listingId))->save('payment', 'paystack', ['secret_key' => 'sk_test_' . str_repeat('b2', 15)], true, null);
        $this->assertNotSame([], $errors);
        $this->assertNull(DepositService::gatewayFor($this->listingId));
        $this->signedIn()->get('business/settings/payments')->assertSee('Connecting a payment account is not available yet.');
    }

    public function testThePaymentsPageAndAnotherBusinessesPayments(): void
    {
        $this->connectPaystack();
        $this->startPayment($this->dueAppointment());
        [$otherId] = $this->business('other-salon');
        db_connect()->table('xs_booking_payments')->insert([
            'listing_id' => $otherId, 'hash' => str_repeat('c', 32), 'reference' => 'WSB-BOOK-777777-P1', 'gateway' => 'paystack',
            'amount_cents' => 9900, 'currency' => 'ZAR', 'status' => 'paid', 'created_at' => gmdate('Y-m-d H:i:s'),
        ]);

        $page = $this->signedIn()->get('business/payments');
        $page->assertOK();
        $page->assertSee('-P1');
        $page->assertSee('Not finished');
        $page->assertDontSee('WSB-BOOK-777777-P1');
    }

    // Helpers

    private function connectPaystack(bool $active = true): void
    {
        $this->assertSame([], (new BookingIntegrations($this->listingId))->save('payment', 'paystack', ['secret_key' => 'sk_test_' . str_repeat('ab', 15)], $active, null));
    }

    private function connectPayFast(): void
    {
        // PayFast's public sandbox merchant, with our own passphrase.
        $this->assertSame([], (new BookingIntegrations($this->listingId))->save('payment', 'payfast', [
            'merchant_id' => '10000100', 'merchant_key' => '46f0cd694581a', 'passphrase' => self::PASSPHRASE, 'mode' => 'test',
        ], true, null));
    }

    private function gateway(): GatewayConfig
    {
        return DepositService::gatewayFor($this->listingId);
    }

    /**
     * The fields of a signed PayFast notification for a payment.
     *
     * @param array<string,mixed>  $payment
     * @param array<string,string> $over
     *
     * @return array<string,string>
     */
    private function itnFields(array $payment, string $passphrase, array $over = []): array
    {
        $fields = $over + [
            'm_payment_id'   => (string) $payment['reference'],
            'pf_payment_id'  => 'pf-1234',
            'payment_status' => 'COMPLETE',
            'item_name'      => 'Deposit: Cut',
            'amount_gross'   => number_format((int) $payment['amount_cents'] / 100, 2, '.', ''),
            'merchant_id'    => '10000100',
        ];
        $fields['signature'] = (new PayFast())->signature($fields, $passphrase, false);

        return $fields;
    }

    /** The same notification as the raw body PayFast posts. @param array<string,mixed> $payment @param array<string,string> $over */
    private function itn(array $payment, string $passphrase, array $over = []): string
    {
        return http_build_query($this->itnFields($payment, $passphrase, $over));
    }

    /** @return array<string,string> the hidden fields of the PayFast form */
    private function formFields(string $html): array
    {
        preg_match_all('/<input type="hidden" name="([^"]+)" value="([^"]*)">/', $html, $m, PREG_SET_ORDER);
        $out = [];
        foreach ($m as [, $name, $value]) {
            if ($name !== csrf_token()) {
                $out[html_entity_decode($name)] = html_entity_decode($value, ENT_QUOTES);
            }
        }

        return $out;
    }

    /** A booking waiting for its R50 deposit, in two days. @return array<string,mixed> */
    private function dueAppointment(?int $providerId = null): array
    {
        $providerId ??= $this->sarah;
        $slot  = $this->firstSlot($providerId);
        $svc   = new PublicBookingService($this->listing());
        $email = 'c' . bin2hex(random_bytes(3)) . '@example.test';
        $res   = $svc->book(['service_id' => $this->serviceId, 'provider_id' => $providerId, 'start' => $slot['start'], 'first_name' => 'Bob', 'last_name' => 'Ndlovu', 'email' => $email, 'phone' => '082 555 1234']);
        $this->assertSame([], $res['errors']);
        $a = A::forBusiness($this->listingId)->where('reference', $res['reference'])->first();
        $this->assertSame('due', $a['payment_status']);

        return $a;
    }

    /** @return array<string,mixed> the payment row */
    private function startPayment(array $a): array
    {
        $start = (new DepositService($this->listing()))->start($a);
        $this->assertNull($start['error']);

        return P::forBusiness($this->listingId)->where('appointment_id', (int) $a['id'])->orderBy('id', 'DESC')->first();
    }

    /** Someone else's confirmed booking at that time. */
    private function appointmentAt(int $providerId, string $startUtc): void
    {
        $start = new DateTimeImmutable($startUtc, new DateTimeZone('UTC'));
        A::forBusiness($this->listingId)->insert([
            'hash' => bin2hex(random_bytes(16)), 'provider_id' => $providerId, 'service_id' => $this->serviceId,
            'start_at' => $start->format('Y-m-d H:i:s'), 'end_at' => $start->modify('+1 hour')->format('Y-m-d H:i:s'),
            'stored_timezone' => 'Africa/Johannesburg', 'status' => 'confirmed', 'service_name' => 'Cut',
            'service_duration_min' => 60, 'provider_name' => 'James',
        ]);
    }

    private function queued(): int
    {
        return db_connect()->table('xs_booking_notification_queue')->where('listing_id', $this->listingId)->countAllResults();
    }

    private function queuedFor(int $appointmentId): int
    {
        return db_connect()->table('xs_booking_notification_queue')->where('listing_id', $this->listingId)->where('appointment_id', $appointmentId)->countAllResults();
    }

    /** @return array<string,mixed> */
    private function firstSlot(int $providerId): array
    {
        $svc = new PublicBookingService($this->listing());
        foreach ($svc->days($this->serviceId, $providerId, $svc->today(), 14) as $day) {
            foreach ($svc->times($this->serviceId, $providerId, $day) as $slot) {
                if (A::forBusiness($this->listingId)->where('provider_id', $providerId)->where('start_at', $slot['start'])->countAllResults() === 0) {
                    return $slot;
                }
            }
        }
        $this->fail('No free time');
    }

    /**
     * @param array<string,mixed> $extra
     *
     * @return array<string,mixed>
     */
    private function form(string $start, int $providerId, array $extra = []): array
    {
        return $extra + [
            csrf_token()  => csrf_hash(),
            'service_id'  => $this->serviceId,
            'provider_id' => $providerId,
            'start'       => $start,
            'first_name'  => 'Alice',
            'last_name'   => 'Johnson',
            'email'       => 'alice@example.test',
            'phone'       => '082 555 1234',
        ];
    }

    private function bookOnline(): string
    {
        $res = $this->post('directory/salon-lumi/book', $this->form($this->firstSlot($this->sarah)['start'], $this->sarah));
        $this->assertMatchesRegularExpression('#/book/[0-9a-f]{64}\?new=1$#', (string) $res->getRedirectUrl(), 'Booked');
        preg_match('#/book/([0-9a-f]{64})#', $res->getRedirectUrl(), $m);

        return $m[1];
    }

    /** @param list<int> $providerIds */
    private function service(int $listingId, array $providerIds, string $name, string $price, int $depositPct): int
    {
        $result = (new CatalogueService($listingId))->saveService(null, [
            'name' => $name, 'duration_min' => 60, 'price' => $price, 'deposit_pct' => $depositPct, 'delivery_modes' => ['onsite'], 'provider_ids' => $providerIds,
        ], null);
        $this->assertSame([], $result['errors']);

        return (int) $result['id'];
    }

    /** @return array<string,mixed> the owner's membership */
    private function member(): array
    {
        return BusinessMemberModel::forBusiness($this->listingId)->where('account_id', $this->accountId)->first();
    }

    /** A reception member looking after James. @return array<string,mixed> */
    private function reception(): array
    {
        $account = (new AccountService())->findOrCreate('desk@example.test', 'Desk');
        $id      = (int) BusinessMemberModel::forBusiness($this->listingId)->insert(['account_id' => $account['id'], 'role' => 'staff', 'status' => 'active'], true);
        (new TeamService($this->listingId))->setStaff($this->james, [$id], null);

        return BusinessMemberModel::forBusiness($this->listingId)->find($id);
    }

    private function signedIn(?int $accountId = null): self
    {
        return $this->withSession([
            BusinessSession::ACCOUNT => $accountId ?? $this->accountId,
            BusinessSession::LISTING => $this->listingId,
            BusinessSession::SEEN    => time(),
        ]);
    }

    /** @return array<string,mixed> */
    private function listing(?int $id = null): array
    {
        return $this->listings->find($id ?? $this->listingId);
    }

    /** @return array{0:int,1:int} listing id, owner account id */
    private function business(string $slug): array
    {
        $id = (int) $this->listings->insert([
            'type'             => 'practice',
            'display_name'     => ucwords(str_replace('-', ' ', $slug)),
            'contact_person'   => 'Thandi Mokoena',
            'email'            => $slug . '@example.test',
            'slug'             => $slug,
            'status'           => 'published',
            'is_verified'      => 1,
            'country'          => 'South Africa',
            'category_id'      => $this->categoryId,
            'description'      => '<p>A friendly hair salon in the heart of Rosebank, open six days a week.</p>',
            'description_text' => 'A friendly hair salon in the heart of Rosebank, open six days a week.',
        ], true);
        $account = (new BookingSetupService())->turnOn($this->listings->find($id));

        return [$id, (int) $account['id']];
    }
}

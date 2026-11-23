<?php

use App\Filters\AdminFilter;
use App\Models\ProfileSetupOrderModel;
use App\Services\ProfileSetupService;
use CodeIgniter\Events\Events;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;
use Tests\Support\FreshDatabase;

/**
 * /profile-setup: we complete a profile for a single Paystack payment.
 *
 * Pinned:
 *
 * 1. **The page** renders full width with its FAQ markup, and is in the
 *    footer and the sitemap.
 * 2. **An order** is saved awaiting payment only when the form is valid,
 *    and the checkout opens Paystack at the price the order was sold at.
 * 3. **A payment is recorded once**: a repeat (webhook and return both
 *    landing) sends no second set of emails, and the retired PayFast
 *    notification endpoint is gone.
 * 4. **The admin queue** cannot mark an order paid by hand, nor work an
 *    unpaid order.
 *
 * @internal
 */
final class ProfileSetupTest extends CIUnitTestCase
{
    use FreshDatabase;
    use \Tests\Support\WithEnv;
    use FeatureTestTrait;

    private ProfileSetupService $svc;
    private ProfileSetupOrderModel $orders;

    /** @var list<array<string,mixed>> */
    private array $sent = [];

    protected function setUp(): void
    {
        parent::setUp();
        cache()->delete('directory_sitemap_xml');
        $this->svc    = new ProfileSetupService();
        $this->orders = new ProfileSetupOrderModel();

        Services::injectMock('throttler', new class () {
            public function check(string $key, int $capacity, int $seconds, int $cost = 1): bool
            {
                return true;
            }

            public function getTokenTime(): int
            {
                return 0;
            }
        });

        $this->sent = [];
        Events::on('email', function (array $archive): void {
            $this->sent[] = $archive;
        });
    }

    protected function tearDown(): void
    {
        $this->restoreEnv();
        Services::resetSingle('paystack');
        Events::removeAllListeners('email');
        Services::resetSingle('throttler');
        Services::resetSingle('response');
        cache()->delete('directory_sitemap_xml');
        parent::tearDown();
    }

    // ------------------------------------------------------------- helpers

    private function form(array $over = []): array
    {
        return $over + [
            'name'          => 'Thandi Mokoena',
            'business_name' => 'Thandi Hair Studio',
            'email'         => 'thandi@example.test',
            'phone'         => '082 123 4567',
            'profile_url'   => '',
            'notes'         => 'Braids and natural hair, Soweto.',
            'terms'         => '1',
        ];
    }

    private function order(array $over = []): array
    {
        $result = $this->svc->create($this->form($over));
        $this->assertTrue($result['ok'], json_encode($result['errors']));

        return $result['order'];
    }

    private function asAdmin(): self
    {
        return $this->withSession(['dir_admin' => true, AdminFilter::SEEN_KEY => time()]);
    }

    // ------------------------------------------------------------- the page

    public function testThePageRendersAndIsLinkedFromTheFooterAndSitemap(): void
    {
        $res = $this->get('profile-setup');
        $res->assertStatus(200);
        $html = (string) $res->response()->getBody();

        $this->assertStringContainsString('We set up your business profile for you', $html);
        $this->assertStringContainsString('"FAQPage"', $html);
        $this->assertStringContainsString('R250', $html);
        $this->assertStringContainsString('id="terms"', $html);
        $this->assertStringContainsString('href="' . base_url('profile-setup') . '">We set up your profile</a>', $html);
        $this->assertStringNotContainsString('form-card-narrow', explode('id="order"', $html)[1] ?? '', 'the order form spans the container');

        $this->get('sitemap.xml')->assertSee(base_url('profile-setup'));
    }

    public function testTheSignupFormAndTheOwnerEditPageOfferTheService(): void
    {
        $offer = '<strong>Short on time? For a single payment of R250, we complete your profile for you.</strong>';

        $res = $this->get('add-profile?plan=free');
        $res->assertStatus(200);
        $this->assertStringContainsString($offer, (string) $res->response()->getBody());

        $id = (int) (new \App\Models\DirectoryListingModel())->insert([
            'type'             => 'practice',
            'display_name'     => 'Thandi Hair Studio',
            'email'            => 'thandi@example.test',
            'slug'             => 'thandi-hair-studio',
            'status'           => 'published',
            'description'      => '<p>Braids and natural hair.</p>',
            'description_text' => 'Braids and natural hair.',
        ], true);

        $res = $this->withSession(\Tests\Support\OwnerSession::for($id))->get('business/badge');
        $res->assertStatus(200);
        $html = (string) $res->response()->getBody();
        $this->assertStringContainsString($offer, $html);
        $this->assertStringContainsString('href="' . base_url('profile-setup') . '"', $html);
    }

    // ------------------------------------------------------------- orders

    public function testAValidOrderIsSavedAwaitingPayment(): void
    {
        $order = $this->order();

        $this->assertSame(ProfileSetupOrderModel::STATUS_AWAITING_PAYMENT, $order['status']);
        $this->assertSame(sprintf('WSL-SETUP-%06d', $order['id']), $order['reference'], 'the order\'s own number');
        $this->assertSame('250.00', $order['amount']);
        $this->assertNotEmpty($order['terms_at']);
        $this->assertNull($order['profile_url']);
        $this->assertSame([], $this->sent, 'nothing is emailed before payment');
    }

    public function testAnOrderNeedsItsDetailsAndTheTerms(): void
    {
        $result = $this->svc->create([
            'name' => '', 'business_name' => '', 'email' => 'nope', 'phone' => '12', 'profile_url' => 'not a link', 'terms' => '',
        ]);

        $this->assertFalse($result['ok']);
        $this->assertSame(
            ['name', 'business_name', 'email', 'phone', 'profile_url', 'terms'],
            array_keys($result['errors'])
        );
        $this->assertSame(0, $this->orders->countAllResults());
    }

    public function testThePostedFormGoesToCheckoutAndBotsSaveNothing(): void
    {
        $this->setEnv('directory.paystackSecretKey', \Tests\Support\FakePaystack::SECRET);
        \Config\Services::injectMock('paystack', new \Tests\Support\FakePaystack());

        $this->withSession(['profile_setup_form_rendered_at' => time() - 30])
            ->post('profile-setup', $this->form() + [HONEYPOT_FIELD => 'http://spam.test', csrf_token() => csrf_hash()])
            ->assertRedirectTo(base_url('profile-setup') . '#order');
        $this->assertSame(0, $this->orders->countAllResults(), 'the honeypot saves nothing');
        // Never a silent bounce: a real person caught by autofill must be told,
        // and keep what they typed (29 Oct 2026).
        $this->assertStringContainsString('press Order again', (string) session('error'));
        $this->assertSame($this->form()['email'], session('old')['email'] ?? null);
        $this->assertArrayNotHasKey(HONEYPOT_FIELD, (array) session('old'));

        $this->withSession(['profile_setup_form_rendered_at' => time() - 30])
            ->post('profile-setup', $this->form() + [csrf_token() => csrf_hash()])
            ->assertRedirectTo(base_url('profile-setup/checkout'));
        $order = $this->orders->first();

        $res = $this->withSession(['profile_setup_order' => (int) $order['id']])->get('profile-setup/checkout');
        $res->assertStatus(200);
        $html = (string) $res->response()->getBody();
        $this->assertStringContainsString('action="' . base_url('profile-setup/pay') . '"', $html);
        $this->assertStringContainsString('Pay R250', $html);
        $this->assertStringContainsString('data-paystack-checkout', $html);

        $this->withSession(['profile_setup_order' => (int) $order['id']])
            ->post('profile-setup/pay', [csrf_token() => csrf_hash()])
            ->assertRedirectTo('https://checkout.paystack.com/fake123');
        $this->assertSame('paystack', $this->orders->find((int) $order['id'])['provider']);
    }

    public function testCheckoutIsPaystackWhateverNewSubscriptionsUse(): void
    {
        $this->setEnv('directory.paystackSecretKey', \Tests\Support\FakePaystack::SECRET);
        $this->setEnv('directory.paymentProvider', 'payfast');
        \Config\Services::injectMock('paystack', new \Tests\Support\FakePaystack());

        $this->assertTrue((new \App\Services\ProfileSetupService())->canTakePayment());
    }

    public function testCheckoutWithoutAnOrderGoesBackToTheForm(): void
    {
        $this->get('profile-setup/checkout')->assertRedirectTo(base_url('profile-setup') . '#order');
    }

    // ------------------------------------------------------------- payment

    public function testAPaymentIsRecordedOnceAndSendsTwoEmails(): void
    {
        $order = $this->order();

        $this->assertSame('paid: order ' . $order['id'], $this->svc->recordPayment($order, 'paystack:1234567'));

        $paid = $this->orders->find($order['id']);
        $this->assertSame(ProfileSetupOrderModel::STATUS_PAID, $paid['status']);
        $this->assertSame('paystack:1234567', $paid['pf_payment_id']);
        $this->assertNotEmpty($paid['paid_at']);
        $this->assertCount(2, $this->sent, 'the inbox and the buyer');

        $this->assertStringStartsWith('duplicate', $this->svc->recordPayment($paid, 'paystack:1234567'));
        $this->assertCount(2, $this->sent, 'a second confirmation sends nothing more');
    }

    public function testTheRetiredPayFastSetupNotificationIsGone(): void
    {
        $this->post('payfast/setup-notify', ['m_payment_id' => 'ps-000000000000'])->assertStatus(404);
    }

    // ------------------------------------------------------------- admin

    public function testTheAdminCannotMarkPaidOrWorkAnUnpaidOrder(): void
    {
        $order = $this->order();

        $this->assertFalse($this->svc->update((int) $order['id'], ProfileSetupOrderModel::STATUS_PAID, '')['ok']);
        $this->assertFalse($this->svc->update((int) $order['id'], ProfileSetupOrderModel::STATUS_DONE, '')['ok']);
        $this->assertTrue($this->svc->update((int) $order['id'], ProfileSetupOrderModel::STATUS_CANCELLED, 'Changed their mind')['ok']);
        $this->assertSame('Changed their mind', $this->orders->find($order['id'])['admin_notes']);

        $paid = $this->order(['email' => 'second@example.test']);
        $this->svc->recordPayment($paid, '42');
        $this->assertTrue($this->svc->update((int) $paid['id'], ProfileSetupOrderModel::STATUS_DONE, '')['ok']);
        $this->assertSame(ProfileSetupOrderModel::STATUS_DONE, $this->orders->find($paid['id'])['status']);
    }

    public function testTheAdminQueueRendersPaidOrdersByDefault(): void
    {
        $paid = $this->order();
        $this->svc->recordPayment($paid, '42');
        $this->order(['business_name' => 'Unpaid Plumbing', 'email' => 'u@example.test']);

        $res = $this->asAdmin()->get('admin/profile-setup');
        $res->assertStatus(200);
        $html = (string) $res->response()->getBody();

        $this->assertStringContainsString('Thandi Hair Studio', $html);
        $this->assertStringNotContainsString('Unpaid Plumbing', $html);
        $this->assertStringContainsString('Setup orders', $html);
    }
}

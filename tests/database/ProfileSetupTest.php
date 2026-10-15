<?php

use App\Filters\AdminFilter;
use App\Libraries\PayFast;
use App\Models\ProfileSetupOrderModel;
use App\Services\ProfileSetupService;
use CodeIgniter\Events\Events;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Directory as DirectoryConfig;
use Config\Services;

/**
 * /profile-setup: we complete a profile for a single PayFast payment.
 *
 * Pinned:
 *
 * 1. **The page** renders full width with its FAQ markup, and is in the
 *    footer and the sitemap.
 * 2. **An order** is saved awaiting payment only when the form is valid,
 *    and the checkout signs a once-off payment (no subscription fields) at
 *    the price the order was sold at.
 * 3. **Only a checked PayFast notification** marks an order paid: a bad
 *    signature, a wrong amount or an unknown reference changes nothing, and
 *    a repeat notification sends no second set of emails.
 * 4. **The admin queue** cannot mark an order paid by hand, nor work an
 *    unpaid order.
 *
 * @internal
 */
final class ProfileSetupTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $namespace   = 'App';
    protected $migrate     = true;
    protected $migrateOnce = false;
    protected $refresh     = true;

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

    /**
     * Real signing and parsing, with the two network checks dictated: the
     * source address lookup and PayFast's POST-back.
     */
    private function payfast(bool $fromPayFast = true, bool $confirmed = true): PayFast
    {
        $config                     = new DirectoryConfig();
        $config->payfastMerchantId  = '10000100';
        $config->payfastMerchantKey = '46f0cd694581a';

        return new class ($config, $fromPayFast, $confirmed) extends PayFast {
            public function __construct(DirectoryConfig $config, private bool $fromPayFast, private bool $confirmed)
            {
                parent::__construct($config);
            }

            public function isConfigured(): bool
            {
                return true;
            }

            public function isSandbox(): bool
            {
                return false;
            }

            public function isValidSourceIp(string $ip): bool
            {
                return $this->fromPayFast;
            }

            public function validateWithPayFast(string $rawBody): bool
            {
                return $this->confirmed;
            }
        };
    }

    /** A signed ITN body for an order, as PayFast would post it. */
    private function itn(PayFast $pf, array $order, array $over = []): string
    {
        $fields = $over + [
            'm_payment_id'   => $order['reference'],
            'pf_payment_id'  => '1234567',
            'payment_status' => 'COMPLETE',
            'item_name'      => 'Profile setup service',
            'amount_gross'   => (string) $order['amount'],
            'custom_str1'    => (string) $order['id'],
        ];
        $fields['signature'] = $pf->signature($fields, null, false);

        return http_build_query($fields);
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

        $res = $this->withSession([\App\Controllers\Manage::SESSION_KEY => $id])->get('manage/edit');
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
        $this->assertMatchesRegularExpression('/^ps-[0-9a-f]{12}$/', $order['reference']);
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

    public function testTheCheckoutSignsAOnceOffPaymentAtTheOrderPrice(): void
    {
        $pf     = $this->payfast();
        $fields = $pf->onceOffFields($this->order());

        $this->assertSame('250.00', $fields['amount']);
        $this->assertSame(base_url('payfast/setup-notify'), $fields['notify_url']);
        $this->assertSame('Thandi', $fields['name_first']);
        $this->assertArrayNotHasKey('subscription_type', $fields);
        $this->assertArrayNotHasKey('recurring_amount', $fields);
        $this->assertSame($pf->signature($fields), $fields['signature']);
    }

    public function testThePostedFormGoesToCheckoutAndBotsSaveNothing(): void
    {
        if (! (new PayFast())->isConfigured()) {
            $this->markTestSkipped('PayFast credentials are not set in this environment.');
        }

        $this->withSession(['profile_setup_form_rendered_at' => time() - 30])
            ->post('profile-setup', $this->form() + ['company_website_hp' => 'http://spam.test', csrf_token() => csrf_hash()])
            ->assertRedirectTo(base_url('profile-setup') . '#order');
        $this->assertSame(0, $this->orders->countAllResults(), 'the honeypot saves nothing');

        $this->withSession(['profile_setup_form_rendered_at' => time() - 30])
            ->post('profile-setup', $this->form() + [csrf_token() => csrf_hash()])
            ->assertRedirectTo(base_url('profile-setup/checkout'));
        $order = $this->orders->first();

        $res = $this->withSession(['profile_setup_order' => (int) $order['id']])->get('profile-setup/checkout');
        $res->assertStatus(200);
        $html = (string) $res->response()->getBody();
        $this->assertStringContainsString('name="m_payment_id" value="' . $order['reference'] . '"', $html);
        $this->assertStringContainsString('Pay R250 with PayFast', $html);
        $this->assertStringNotContainsString('company_website_hp', $html, 'no honeypot field is posted to PayFast');
    }

    public function testCheckoutWithoutAnOrderGoesBackToTheForm(): void
    {
        $this->get('profile-setup/checkout')->assertRedirectTo(base_url('profile-setup') . '#order');
    }

    // ------------------------------------------------------------- payment

    public function testACheckedNotificationMarksTheOrderPaidOnce(): void
    {
        $pf    = $this->payfast();
        $order = $this->order();
        $raw   = $this->itn($pf, $order);

        $this->assertSame('paid: order ' . $order['id'], $this->svc->handleNotification($raw, '197.97.145.144', $pf));

        $paid = $this->orders->find($order['id']);
        $this->assertSame(ProfileSetupOrderModel::STATUS_PAID, $paid['status']);
        $this->assertSame('1234567', $paid['pf_payment_id']);
        $this->assertNotEmpty($paid['paid_at']);
        $this->assertCount(2, $this->sent, 'the inbox and the buyer');

        $this->assertStringStartsWith('duplicate', $this->svc->handleNotification($raw, '197.97.145.144', $pf));
        $this->assertCount(2, $this->sent, 'a retried notification sends nothing more');
    }

    public function testANotificationThatFailsACheckChangesNothing(): void
    {
        $order = $this->order();
        $good  = $this->payfast();

        $cases = [
            'bad signature'        => [$good, str_replace('signature=', 'signature=0', $this->itn($good, $order))],
            'not from PayFast'     => [$this->payfast(false), $this->itn($good, $order)],
            'not confirmed'        => [$this->payfast(true, false), $this->itn($good, $order)],
            'wrong amount'         => [$good, $this->itn($good, $order, ['amount_gross' => '1.00'])],
            'unknown reference'    => [$good, $this->itn($good, $order, ['m_payment_id' => 'ps-000000000000', 'custom_str1' => '999'])],
        ];

        foreach ($cases as $label => [$pf, $raw]) {
            $this->assertStringStartsWith('rejected', $this->svc->handleNotification($raw, '197.97.145.144', $pf), $label);
        }

        $this->assertSame(ProfileSetupOrderModel::STATUS_AWAITING_PAYMENT, $this->orders->find($order['id'])['status']);
        $this->assertSame([], $this->sent);
    }

    public function testAFailedPaymentLeavesTheOrderAwaitingPayment(): void
    {
        $pf    = $this->payfast();
        $order = $this->order();

        $outcome = $this->svc->handleNotification($this->itn($pf, $order, ['payment_status' => 'CANCELLED']), '197.97.145.144', $pf);

        $this->assertStringStartsWith('noted', $outcome);
        $this->assertSame(ProfileSetupOrderModel::STATUS_AWAITING_PAYMENT, $this->orders->find($order['id'])['status']);
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

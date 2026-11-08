<?php

use App\Models\Bookings\BusinessMemberModel;
use App\Models\DirectoryCategoryModel;
use App\Models\DirectoryListingModel;
use App\Services\Bookings\Api\ApiKeys;
use App\Services\Bookings\AvailabilityService;
use App\Services\Bookings\BookingSettings;
use App\Services\Bookings\BookingSetupService;
use App\Services\Bookings\BusinessLocale;
use App\Services\Bookings\BusinessSession;
use App\Services\Bookings\CatalogueService;
use App\Services\Bookings\Plans\BookingPlanService;
use App\Services\Bookings\TeamService;
use App\Services\DirectorySettings;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;
use Tests\Support\FakePaystack;
use Tests\Support\FreshDatabase;
use Tests\Support\WithEnv;

/**
 * Online bookings on local.webscheduler.app (decision D2, owner 5 Nov 2026).
 *
 * A business abroad turns bookings on like one at home and uses its own
 * site's host for everything (booking page, /bookings, the API); prices are
 * in its own currency on its own clock; no deposits (rand only); its plan is
 * shown in US dollars and charged in rand at the Plus rate, with no rand
 * figure on its pages. co.za businesses stay on co.za.
 *
 * @internal
 */
final class BookingAppSiteTest extends CIUnitTestCase
{
    use FeatureTestTrait;
    use FreshDatabase;
    use WithEnv;

    private const APP = 'local.webscheduler.app';

    private DirectoryListingModel $listings;

    private int $categoryId;

    private int $londonId;

    private int $londonAccount;

    private int $salonId;

    private int $serviceId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setEnv('bookings.enabled', 'true');
        $this->setEnv('sites.app.enabled', 'true');
        Services::resetSingle('site');
        Services::injectMock('paystack', new FakePaystack());

        $this->listings   = new DirectoryListingModel();
        $this->categoryId = (int) (new DirectoryCategoryModel())->insert([
            'name' => 'Hair Salon', 'slug' => 'hair-salon', 'group_name' => 'Beauty & Wellness', 'is_active' => 1,
        ], true);
        [$this->londonId, $this->londonAccount] = $this->business('london-salon', 'United Kingdom', 'London');
        [$this->salonId] = $this->business('salon-lumi', 'South Africa', 'Gauteng');

        $team     = new TeamService($this->londonId);
        $provider = (int) $team->providers()[0]['id'];
        $team->saveHours($provider, array_fill_keys(range(1, 7), ['active' => 1, 'start' => '09:00', 'end' => '17:00']), null);
        $this->serviceId = (int) (new CatalogueService($this->londonId))->saveService(null, [
            'name' => 'Cut', 'duration_min' => 60, 'price' => '45', 'deposit_pct' => 20, 'delivery_modes' => ['onsite'], 'provider_ids' => [$provider],
        ], null)['id'];
        BookingSettings::for($this->londonId)->save(['booking.lead_minutes' => '2880'], null);
    }

    protected function tearDown(): void
    {
        Services::reset(true);
        $this->restoreEnv();
        Services::resetSingle('site');
        parent::tearDown();
    }

    public function testABusinessAbroadTakesBookingsOnItsOwnSiteInItsOwnCurrencyAndClock(): void
    {
        $london = $this->listings->find($this->londonId);
        $this->assertSame(['GBP', 'Europe/London'], [BusinessLocale::currency($london), BusinessLocale::timezoneName($london)]);
        $this->assertSame('Europe/London', (new AvailabilityService($london))->timezone()->getName());
        $this->assertSame('GBP', (new CatalogueService($this->londonId))->service($this->serviceId)['currency']);

        $page = $this->withHeaders(['Host' => self::APP])->get('directory/london-salon/book');
        $page->assertStatus(200);
        $page->assertSee('£45');
        $page->assertDontSee('R45');
        $page->assertDontSee('deposit');
        $this->assertSame(\Config\Sites::APP, site()->forRow($london), 'links leave through listing_site_url(), to .app');
    }

    public function testTheProfileListsBookingServicesInItsOwnCurrency(): void
    {
        $page = $this->withHeaders(['Host' => self::APP])->get('directory/london-salon');
        $page->assertStatus(200);
        $this->assertStringContainsString('service-list-book', $page->getBody(), 'booking services stand in for the list (T28)');
        $page->assertSee('£45');
        $page->assertDontSee('R45');
        $this->assertStringContainsString('"priceCurrency":"GBP"', $page->getBody());
    }

    public function testNoDepositsAbroad(): void
    {
        $this->assertNull((new \App\Services\Bookings\Payments\DepositService($this->listings->find($this->londonId)))->depositFor(
            (new CatalogueService($this->londonId))->service($this->serviceId),
        ), 'a GBP price never asks a deposit');
        $this->app()->get('bookings/settings/payments')->assertStatus(404);
        $form = $this->app()->get('bookings/services/' . $this->serviceId);
        $form->assertSee('Price in GBP');
        $form->assertDontSee('Deposit (%)');
    }

    public function testEachBusinessStaysOnItsOwnHost(): void
    {
        // co.za's business on .app: not there.
        $this->withHeaders(['Host' => self::APP])->get('directory/salon-lumi/book')->assertStatus(301);
        // The London business's area on the co.za host follows it to .app.
        $res = $this->withHeaders([])->withSession($this->session())->get('bookings');
        $this->assertMatchesRegularExpression('#^https?://' . preg_quote(self::APP, '#') . '(:\d+)?/bookings$#', $res->response()->getHeaderLine('Location'));
        $this->withHeaders([])->withSession($this->session())->post('bookings/settings/localization', ['time_format' => '24h', 'first_day' => 'monday', csrf_token() => csrf_hash()])->assertStatus(404);
        $this->app()->get('bookings')->assertStatus(200);
    }

    public function testTheApiAnswersOnTheBusinesssOwnHost(): void
    {
        $member = BusinessMemberModel::forBusiness($this->londonId)->where('account_id', $this->londonAccount)->first();
        $key    = (new ApiKeys($this->londonId))->create((int) $member['id'], 'Till', ['catalogue.read'], null, null)['key'];

        $res = $this->withHeaders(['Host' => self::APP, 'Authorization' => 'Bearer ' . $key])->get('api/v1/business');
        $res->assertStatus(200);
        $this->assertSame(['GBP', 'Europe/London'], [json_decode($res->getJSON(), true)['data']['currency'], json_decode($res->getJSON(), true)['data']['timezone']]);
        $this->assertStringContainsString('"amount_cents": 4500', $this->withHeaders(['Host' => self::APP, 'Authorization' => 'Bearer ' . $key])->get('api/v1/services')->getJSON());

        $wrong = $this->withHeaders(['Authorization' => 'Bearer ' . $key])->get('api/v1/business'); // co.za host
        $wrong->assertStatus(404);
        $wrong->assertJSONFragment(['error' => ['code' => 'wrong_site']]);
    }

    public function testThePlanAbroadIsInDollarsChargedInRand(): void
    {
        $this->setEnv('bookings.planRequired', 'true');
        (new BookingPlanService())->startTrial($this->londonId, null);
        $status = (new BookingPlanService())->status($this->londonId);
        $this->assertSame([true, '$8.99', null, false], [$status['abroad'], $status['display'], $status['price'], $status['can_subscribe']], 'no rate set yet: not on sale');

        db_connect()->table('xs_directory_settings')->insert(['name' => 'usd_zar_rate@app', 'value' => '18.5000']);
        (new DirectorySettings())->forget();
        $status = (new BookingPlanService())->status($this->londonId);
        $this->assertSame(['166.32', true], [$status['price'], $status['can_subscribe']], 'US$8.99 at R18.50');

        $tab = $this->app()->get('bookings/settings/plan');
        $tab->assertSee('$8.99');
        $tab->assertSee('Billed in South African rand');
        $this->assertDoesNotMatchRegularExpression('/R\s?\d/', strip_tags((string) $tab->getBody()), 'no rand figure on .app');

        $this->app()->post('bookings/settings/plan/subscribe', [csrf_token() => csrf_hash()])->assertRedirect();
        $init = service('paystack')->callsOf('initialize')[0][1];
        $this->assertSame([16632, 'PLN_monthly_16632'], [$init['amountCents'], $init['planCode']]);
        $this->assertStringEndsWith('/bookings/plan/paystack', $init['callbackUrl']);
        $this->assertSame('Solo', (new BookingPlanService())->forListing($this->londonId)['checkout_tier']);
    }

    public function testCoZaIsUnchanged(): void
    {
        $salon = $this->listings->find($this->salonId);
        $this->assertSame(['ZAR', 'Africa/Johannesburg'], [BusinessLocale::currency($salon), BusinessLocale::timezoneName($salon)]);
        $this->setEnv('bookings.planRequired', 'true');
        (new BookingPlanService())->startTrial($this->salonId, null);
        $status = (new BookingPlanService())->status($this->salonId);
        $this->assertSame(['R160', '160.00', false], [$status['display'], $status['price'], $status['abroad']]);
    }

    // Helpers

    /** Signed in to the London business, on .app. */
    private function app(): self
    {
        return $this->withHeaders(['Host' => self::APP])->withSession($this->session());
    }

    /** @return array<string,mixed> */
    private function session(): array
    {
        return [BusinessSession::ACCOUNT => $this->londonAccount, BusinessSession::LISTING => $this->londonId, BusinessSession::SEEN => time()];
    }

    /** @return array{0:int,1:int} */
    private function business(string $slug, string $country, string $region): array
    {
        $id = (int) $this->listings->insert([
            'type'             => 'practice',
            'display_name'     => ucwords(str_replace('-', ' ', $slug)),
            'contact_person'   => 'Sam Taylor',
            'email'            => $slug . '@example.test',
            'slug'             => $slug,
            'status'           => 'published',
            'is_verified'      => 0,
            'country'          => $country,
            'province'         => $region,
            'category_id'      => $this->categoryId,
            'description'      => '<p>A friendly hair salon open six days a week, with walk ins welcome.</p>',
            'description_text' => 'A friendly hair salon open six days a week, with walk ins welcome.',
        ], true);
        $account = (new BookingSetupService())->turnOn($this->listings->find($id));

        return [$id, (int) $account['id']];
    }
}

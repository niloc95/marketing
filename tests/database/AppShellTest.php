<?php

use App\Filters\AdminFilter;
use App\Models\Bookings\BusinessMemberModel;
use App\Models\DirectoryCategoryModel;
use App\Models\DirectoryListingModel;
use App\Services\Bookings\AccountService;
use App\Services\Bookings\BookingSetupService;
use App\Services\Bookings\BusinessSession;
use CodeIgniter\Config\Factories;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Google as GoogleConfig;
use Config\Services;
use Tests\Support\FreshDatabase;
use Tests\Support\WithEnv;

/**
 * The signed in app shell (layouts/_shell.php): /admin and /business get the
 * sidebar and the swappable #spa-content (resources/shell/spa.js); public
 * pages keep layouts/public.php, and admin no longer carries the public
 * header, its Popular categories strip or the footer.
 *
 * Requires the `tests` database group — see the Tests section of README.md.
 *
 * @internal
 */
final class AppShellTest extends CIUnitTestCase
{
    use FreshDatabase;
    use FeatureTestTrait;
    use WithEnv;

    /** Every page in the admin sidebar. */
    private const ADMIN_PAGES = [
        'admin', 'admin/profiles', 'admin/verifications', 'admin/jobs', 'admin/reviews', 'admin/referrals',
        'admin/partners', 'admin/careers', 'admin/profile-setup', 'admin/categories', 'admin/venues',
        'admin/hero', 'admin/comparison', 'admin/documents', 'admin/documents/system', 'admin/funnel',
        'admin/discovery', 'admin/stats', 'admin/bookings', 'admin/settings', 'admin/status', 'admin/forms',
    ];

    private int $categoryId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setEnv('bookings.enabled', 'true');
        cache()->deleteMatching('form-*');

        $google                  = new GoogleConfig();
        $google->credentialsPath = '';
        Factories::injectMock('config', 'Google', $google);

        $this->categoryId = (int) (new DirectoryCategoryModel())->insert([
            'name' => 'Hair Salon', 'slug' => 'hair-salon', 'group_name' => 'Beauty & Wellness', 'is_active' => 1,
        ], true);
    }

    protected function tearDown(): void
    {
        $this->restoreEnv();
        Factories::reset('config');
        Services::reset(true);
        parent::tearDown();
    }

    public function testEveryAdminPageIsInTheShellWithoutThePublicHeader(): void
    {
        foreach (self::ADMIN_PAGES as $path) {
            $result = $this->withSession(['dir_admin' => true, AdminFilter::SEEN_KEY => time()])->get($path);
            $result->assertOK();
            $html = (string) $result->response()->getBody();

            $this->assertStringContainsString('data-shell="admin"', $html, $path);
            $this->assertStringContainsString('id="spa-content"', $html, $path);
            $this->assertStringContainsString('data-shell-nav', $html, $path);
            $this->assertMatchesRegularExpression('#class="shell-link is-active"[^>]*aria-current="page"#', $html, $path . ' marks its sidebar item');
            $this->assertStringNotContainsString('header-quick', $html, $path . ' has the Popular categories strip');
            $this->assertStringNotContainsString('class="site-header"', $html, $path . ' has the public header');
            $this->assertStringNotContainsString('id="ws_email"', $html, $path . ' has the newsletter footer');
            $this->assertStringContainsString('noindex, nofollow', $html, $path);
        }
    }

    public function testTheTopBarNamesTheSidebarItem(): void
    {
        $html = (string) $this->withSession(['dir_admin' => true, AdminFilter::SEEN_KEY => time()])->get('admin/documents/system')->response()->getBody();

        $this->assertStringContainsString('data-page-title="Documents"', $html, 'the handbook sits under Documents');
        $this->assertStringContainsString('data-shell-title>Documents<', $html);
    }

    public function testTheProfileFormOpensWithAFullPageLoad(): void
    {
        $html = (string) $this->withSession(['dir_admin' => true, AdminFilter::SEEN_KEY => time()])->get('admin/new')->response()->getBody();

        // Leaflet and Quill load with the page, so the shell must not swap it in.
        $this->assertMatchesRegularExpression('#<main id="spa-content"[^>]*data-spa-full#', $html);
    }

    public function testAdminSignInIsTheShellWithoutTheSidebar(): void
    {
        $html = (string) $this->get('admin/login')->response()->getBody();

        $this->assertStringContainsString('data-shell-bare', $html);
        $this->assertStringNotContainsString('data-shell-nav', $html);
        $this->assertStringNotContainsString('header-quick', $html);
    }

    public function testBusinessSidebarFollowsTheRole(): void
    {
        [$listingId, $ownerId] = $this->business();

        $owner = (string) $this->signedIn($ownerId, $listingId)->get('business/calendar')->response()->getBody();
        $this->assertStringContainsString('data-shell="business"', $owner);
        $this->assertStringContainsString('data-page-title="Calendar"', $owner);
        $this->assertStringContainsString('<title>Calendar | WebScheduler Local</title>', $owner);
        $this->assertStringContainsString('title="Settings"', $owner);
        $this->assertStringContainsString('Salon Lumi', $owner, 'the business, to switch from');

        $desk = (new AccountService())->findOrCreate('desk@example.test', 'Desk');
        BusinessMemberModel::forBusiness($listingId)->insert(['account_id' => $desk['id'], 'role' => 'staff', 'status' => 'active'], true);
        $staff = (string) $this->signedIn((int) $desk['id'], $listingId)->get('business/calendar')->response()->getBody();
        $this->assertStringContainsString('title="Calendar"', $staff);
        $this->assertStringNotContainsString('title="Settings"', $staff, 'reception does not manage settings');
        $this->assertStringNotContainsString('title="Analytics"', $staff);
    }

    public function testBusinessSignInHasNoSidebar(): void
    {
        $html = (string) $this->get('business/sign-in')->response()->getBody();

        $this->assertStringContainsString('data-shell-bare', $html);
        $this->assertStringNotContainsString('data-shell-nav', $html);
    }

    public function testPublicPagesKeepTheirOwnLayout(): void
    {
        $this->business();
        $html = (string) $this->get('/')->response()->getBody();

        $this->assertStringContainsString('class="site-header"', $html);
        $this->assertStringNotContainsString('data-shell=', $html);
        $this->assertStringNotContainsString('id="spa-content"', $html);
    }

    /** @return array{int, int} listing id, owner account id */
    private function business(): array
    {
        $listings = new DirectoryListingModel();
        $id       = (int) $listings->insert([
            'type'             => 'practice',
            'display_name'     => 'Salon Lumi',
            'contact_person'   => 'Thandi Mokoena',
            'email'            => 'salon-lumi@example.test',
            'slug'             => 'salon-lumi',
            'status'           => 'published',
            'country'          => 'South Africa',
            'category_id'      => $this->categoryId,
            'description'      => '<p>A friendly hair salon in the heart of Rosebank, open six days a week.</p>',
            'description_text' => 'A friendly hair salon in the heart of Rosebank, open six days a week.',
        ], true);
        $account = (new BookingSetupService())->turnOn($listings->find($id));

        return [$id, (int) $account['id']];
    }

    private function signedIn(int $accountId, int $listingId): self
    {
        return $this->withSession([
            BusinessSession::ACCOUNT => $accountId,
            BusinessSession::LISTING => $listingId,
            BusinessSession::SEEN    => time(),
        ]);
    }
}

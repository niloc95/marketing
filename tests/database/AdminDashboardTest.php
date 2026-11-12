<?php

use App\Filters\AdminFilter;
use App\Models\DirectoryCategoryModel;
use App\Models\DirectoryListingModel;
use App\Models\ProfileSetupOrderModel;
use CodeIgniter\Config\Factories;
use CodeIgniter\Config\Services;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Google as GoogleConfig;
use Tests\Support\FreshDatabase;
use Tests\Support\WithEnv;

/**
 * The admin landing page (AdminDashboard, AdminDashboardService): health,
 * queues, signups, traffic, forms and setup orders, one site at a time, and
 * the old /admin?… list links still landing on /admin/profiles.
 *
 * Requires the `tests` database group — see the Tests section of README.md.
 *
 * @internal
 */
final class AdminDashboardTest extends CIUnitTestCase
{
    use FreshDatabase;
    use FeatureTestTrait;
    use WithEnv;

    private int $category;

    protected function setUp(): void
    {
        parent::setUp();
        cache()->deleteMatching('form-*');

        $google                  = new GoogleConfig();
        $google->credentialsPath = '';
        Factories::injectMock('config', 'Google', $google);

        $this->category = (int) (new DirectoryCategoryModel())->insert([
            'name' => 'Bakeries', 'slug' => 'bakeries', 'group_name' => 'Food & Drink', 'is_active' => 1,
        ], true);
    }

    protected function tearDown(): void
    {
        $this->restoreEnv();
        Factories::reset('config');
        Services::resetSingle('site');
        parent::tearDown();
    }

    public function testSigningInLandsOnTheDashboard(): void
    {
        $html = $this->admin('admin');

        $this->assertMatchesRegularExpression('#<h1 class="shell-title" data-shell-title>\s*Dashboard\s*</h1>#', $html);
        $this->assertMatchesRegularExpression('#class="shell-link is-active" href="[^"]*/admin" title="Dashboard" aria-current="page">#', $html);
        $this->assertStringContainsString('Nothing waiting in any queue.', $html);
    }

    public function testAVisitorIsNotLetIn(): void
    {
        $this->get('admin')->assertRedirectTo(base_url('admin/login'));
    }

    public function testOldListLinksMoveToProfiles(): void
    {
        $result = $this->withSession($this->session())->get('admin?status=pending&q=bread');

        $result->assertStatus(301);
        $result->assertRedirectTo(base_url('admin/profiles?status=pending&q=bread'));
        $this->withSession($this->session())->get('admin/profiles?status=pending')->assertSee('Profiles');
    }

    public function testSignupsAreCountedPerSiteAndSplitByPlan(): void
    {
        $this->setEnv('sites.app.enabled', 'true');
        Services::resetSingle('site');
        $free   = $this->listing('Cape Crumb Bakery', 'South Africa');
        $badge  = $this->listing('Karoo Rusk Co', 'South Africa');
        $abroad = $this->listing('Thames Loaf Bakery', 'United Kingdom');
        $this->verification($badge, 'badge');
        $this->verification($abroad, 'international');

        $za = $this->admin('admin');
        $this->assertMatchesRegularExpression('#New profiles</dt>\s*<dd class="stats-tile-value">2<#', $za);
        $this->assertMatchesRegularExpression('#Applied for Verified</dt>\s*<dd class="stats-tile-value">1<#', $za);
        $this->assertStringContainsString('admin/edit/' . $free, $za);
        $this->assertStringNotContainsString('Thames Loaf Bakery', $za);
        $this->assertStringContainsString('class="chart-s2"', $za, 'the badge signup is stacked on the free one');

        $app = $this->admin('admin?site=app');
        $this->assertMatchesRegularExpression('#New profiles</dt>\s*<dd class="stats-tile-value">1<#', $app);
        $this->assertMatchesRegularExpression('#Applied for Plus</dt>\s*<dd class="stats-tile-value">1<#', $app);
        $this->assertStringContainsString('Thames Loaf Bakery', $app);
        $this->assertStringNotContainsString('Cape Crumb Bakery', $app);
    }

    public function testWaitingWorkAndSetupOrdersShow(): void
    {
        $id = $this->listing('Karoo Rusk Co', 'South Africa');
        $this->verification($id, 'badge');
        $orders = new ProfileSetupOrderModel();
        foreach (['ps-000000000001' => 'paid', 'ps-000000000002' => 'done', 'ps-000000000003' => 'done'] as $ref => $status) {
            $orders->insert(['reference' => $ref, 'name' => 'Thandi', 'business_name' => 'Order ' . $ref, 'email' => 'o@example.test', 'phone' => '0215550100', 'amount' => '250.00', 'status' => $status]);
        }

        $html = $this->admin('admin');

        $this->assertMatchesRegularExpression('#href="[^"]*admin/verifications">\s*<span class="stats-tile-value">1</span>\s*<span class="stats-tile-label">Verification to review#', $html);
        $this->assertMatchesRegularExpression('#admin/profile-setup">\s*<span class="stats-tile-value">1</span>#', $html);
        $this->assertMatchesRegularExpression('#status=done">Done</a>.*?<span class="meter-value">2</span>#s', $html);
        $this->assertStringContainsString('Order ps-000000000003', $html);
        $this->assertSame(['paid' => 1, 'in_progress' => 0, 'done' => 2, 'awaiting_payment' => 0, 'cancelled' => 0, 'refunded' => 0], $orders->statusCounts());
    }

    public function testALostFormTurnsTheHealthPillRed(): void
    {
        db_connect()->table('directory_form_events')->insert([
            'form' => 'signup', 'outcome' => 'lost', 'source' => 'server', 'site' => 'za',
            'detail' => 'bad_redirect', 'path' => 'add-profile', 'created_at' => date('Y-m-d H:i:s'),
        ]);

        $html = $this->admin('admin');

        $this->assertMatchesRegularExpression('#class="dash-pill is-bad"[^>]*title="1 lost in 24 hours"#', $html);
        $this->assertStringContainsString('form submission was lost in the last 24 hours', $html);
    }

    // ---------------------------------------------------------------- helpers

    /** @return array<string,mixed> */
    private function session(): array
    {
        return ['dir_admin' => true, AdminFilter::SEEN_KEY => time()];
    }

    private function admin(string $path): string
    {
        $result = $this->withSession($this->session())->get($path);
        $result->assertOK();

        return (string) $result->response()->getBody();
    }

    private function listing(string $name, string $country): int
    {
        $slug = strtolower(str_replace(' ', '-', $name));

        return (int) (new DirectoryListingModel())->insert([
            'type' => 'practice', 'status' => 'published', 'is_verified' => 1, 'category_id' => $this->category,
            'display_name' => $name, 'slug' => $slug, 'email' => $slug . '@example.test',
            'city' => $country === 'South Africa' ? 'Cape Town' : 'London',
            'province' => $country === 'South Africa' ? 'Western Cape' : '',
            'country' => $country, 'phone' => '021 555 0100', 'published_at' => date('Y-m-d H:i:s'),
        ], true);
    }

    private function verification(int $listingId, string $plan): void
    {
        db_connect()->table('directory_verifications')->insert([
            'listing_id' => $listingId, 'plan' => $plan, 'state' => 'submitted', 'amount' => '29.99',
            'submitted_at' => date('Y-m-d H:i:s'), 'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }
}

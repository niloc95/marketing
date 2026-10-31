<?php

use App\Controllers\Manage;
use App\Models\DirectoryCategoryModel;
use App\Models\DirectoryListingModel;
use App\Services\ListingStatsService;
use CodeIgniter\Config\Factories;
use CodeIgniter\Config\Services;
use Config\Google as GoogleConfig;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use Tests\Support\FreshDatabase;
use Tests\Support\WithEnv;

/**
 * The owner's stats panel on /manage/edit (_stats_panel.php, data from
 * ListingStatsService::forOwner()): basic for every owner, full with the
 * badge or Plus, tips that only name empty parts of the profile.
 *
 * Requires the `tests` database group — see the Tests section of README.md.
 *
 * @internal
 */
final class OwnerStatsTest extends CIUnitTestCase
{
    use FreshDatabase;
    use FeatureTestTrait;
    use WithEnv;

    private int $bakery;
    private int $pending;
    private int $abroad;

    protected function setUp(): void
    {
        parent::setUp();
        cache()->clean();

        // A developer's .env may hold real Google settings: never here.
        $google                  = new GoogleConfig();
        $google->credentialsPath = '';
        Factories::injectMock('config', 'Google', $google);
        Services::resetSingle('google');

        $category = (int) (new DirectoryCategoryModel())->insert([
            'name' => 'Bakeries', 'slug' => 'bakeries', 'group_name' => 'Food & Drink', 'is_active' => 1,
        ], true);
        $listings = new DirectoryListingModel();
        $base     = [
            'type' => 'practice', 'status' => 'published', 'is_verified' => 1, 'category_id' => $category,
            'city' => 'Cape Town', 'province' => 'Western Cape', 'country' => 'South Africa',
            'phone' => '021 555 0100', 'published_at' => date('Y-m-d H:i:s'),
        ];
        $this->bakery  = (int) $listings->insert(['display_name' => 'Cape Crumb Bakery', 'slug' => 'cape-crumb-bakery', 'email' => 'crumb@example.test'] + $base, true);
        $this->pending = (int) $listings->insert(['display_name' => 'Unconfirmed Bakery', 'slug' => 'unconfirmed-bakery', 'email' => 'wait@example.test', 'status' => 'pending'] + $base, true);
        $this->abroad  = (int) $listings->insert([
            'display_name' => 'Thames Loaf Bakery', 'slug' => 'thames-loaf-bakery', 'email' => 'thames@example.test',
            'city' => 'London', 'province' => '', 'region' => 'England', 'country' => 'United Kingdom',
        ] + $base, true);
    }

    protected function tearDown(): void
    {
        $this->restoreEnv();
        Factories::reset('config');
        Services::resetSingle('site');
        Services::resetSingle('google');
        cache()->clean();
        parent::tearDown();
    }

    public function testAFreeOwnerSeesTheLastThirtyDaysAndNoMore(): void
    {
        // Counting started inside the 30 days before, so there is something to compare with.
        $this->day($this->bakery, '-59 days', ['views' => 10, 'appearances' => 100]);
        $this->day($this->bakery, '-2 days', ['views' => 15, 'appearances' => 200, 'taps_call' => 2, 'taps_whatsapp' => 1]);
        $this->opened($this->bakery, 'fresh bread');

        $html = $this->manage($this->bakery);

        $this->assertStringContainsString('id="profile-stats"', $html);
        $this->assertStringContainsString('Up 50% on the 30 days before', $html);
        $this->assertMatchesRegularExpression('#Taps to get in touch</dt>\s*<dd class="stats-tile-value">3<#', $html);
        $this->assertStringNotContainsString('fresh bread', $html, 'the words are for the full view');
        $this->assertStringNotContainsString('Profile views by month', $html);
        $this->assertStringNotContainsString('On Google search', $html);
    }

    public function testAVerifiedOwnerSeesTheFullView(): void
    {
        (new DirectoryListingModel())->update($this->bakery, ['verified_until' => date('Y-m-d', strtotime('+1 year'))]);
        $this->day($this->bakery, 'first day of last month', ['views' => 8]);
        $this->day($this->bakery, 'today', ['views' => 12, 'taps_website' => 4]);
        $this->opened($this->bakery, 'fresh bread');
        $this->opened($this->bakery, 'fresh bread');

        $last = date('Y-m-d', strtotime('-3 days'));
        $db   = db_connect();
        $db->table('directory_google_pulls')->insert(['source' => 'gsc', 'site' => 'za', 'day' => $last, 'rows' => 1, 'pulled_at' => date('Y-m-d H:i:s')]);
        $db->table('directory_listing_search')->insert(['listing_id' => $this->bakery, 'day' => $last, 'impressions' => 90, 'clicks' => 6, 'position' => 4.5]);
        $db->table('directory_listing_search_queries')->insert(['listing_id' => $this->bakery, 'month' => substr($last, 0, 7) . '-01', 'query' => 'bakery cape town', 'impressions' => 40, 'clicks' => 3]);

        $html = $this->manage($this->bakery);

        $this->assertStringContainsString('Full statistics', $html);
        $this->assertStringContainsString('Profile views by month', $html);
        $this->assertStringContainsString('class="stats-chart"', $html);
        $this->assertStringContainsString('fresh bread', $html);
        $this->assertMatchesRegularExpression('#<span>Website</span><span class="stats-list-n">4<#', $html);
        $this->assertStringContainsString('shown <strong>90</strong> times', $html);
        $this->assertStringContainsString('bakery cape town', $html);
        $this->assertStringNotContainsString('About Verified', $html);
    }

    public function testTipsWaitUntilCountingHasRunTwoWeeks(): void
    {
        $this->day($this->bakery, 'today', ['appearances' => 1]);
        $this->assertSame([], $this->forOwner()['tips']);

        $this->day($this->bakery, '-20 days', ['appearances' => 1]);
        $keys = array_column($this->forOwner()['tips'], 'key');
        $this->assertSame(['rarely_shown'], $keys);
    }

    public function testTipsNameOnlyEmptyPartsAndNothingPaid(): void
    {
        $this->day($this->bakery, '-20 days', ['appearances' => 2000, 'views' => 40]);
        $tips = (new ListingStatsService())->tips(
            ['id' => $this->bakery, 'whatsapp' => '27821234567'],
            (new ListingStatsService())->totals($this->bakery, date('Y-m-d', strtotime('-29 days')), date('Y-m-d')),
            [
                ['key' => 'logo', 'label' => 'A logo', 'anchor' => 'logo-input', 'done' => false],
                ['key' => 'photos', 'label' => 'Photos', 'anchor' => 'gallery-input', 'done' => true],
                ['key' => 'phone', 'label' => 'A phone number', 'anchor' => 'field-phone', 'done' => true],
                ['key' => 'hours', 'label' => 'Opening hours', 'anchor' => 'field-hours', 'done' => false],
            ],
        );

        $this->assertSame(['rarely_opened', 'few_contacts'], array_column($tips, 'key'));
        $this->assertSame(['A logo'], array_column($tips[0]['steps'], 'label'), 'photos are done');
        $this->assertSame(['Opening hours'], array_column($tips[1]['steps'], 'label'), 'phone and WhatsApp are there');
    }

    public function testATipWithNothingLeftToFillInIsLeftOut(): void
    {
        $this->day($this->bakery, '-20 days', ['appearances' => 2000, 'views' => 40]);
        $tips = (new ListingStatsService())->tips(
            ['id' => $this->bakery, 'whatsapp' => '27821234567'],
            (new ListingStatsService())->totals($this->bakery, date('Y-m-d', strtotime('-29 days')), date('Y-m-d')),
            [['key' => 'logo', 'label' => 'A logo', 'anchor' => 'logo-input', 'done' => true]],
        );

        $this->assertSame([], $tips);
    }

    public function testNoPanelUntilTheProfileIsPublished(): void
    {
        $this->assertStringNotContainsString('id="profile-stats"', $this->manage($this->pending));
    }

    public function testAnOwnerAbroadIsNeverPointedAtTheVerifiedBadge(): void
    {
        $this->setEnv('sites.app.enabled', 'true');
        Services::resetSingle('site');

        $html = $this->withHeaders(['Host' => 'local.webscheduler.app'])
            ->withSession([Manage::SESSION_KEY => $this->abroad])
            ->get('manage/edit')->getBody();

        $panel = $this->panel($html);
        $this->assertNotSame('', $panel);
        $this->assertStringNotContainsString('Verified', $panel);
        $this->assertDoesNotMatchRegularExpression('/\bR\s?\d/', $panel);
    }

    // ------------------------------------------------------------------ admin

    public function testAdminStatsAddsUpOneSiteAndListsWhoToHelp(): void
    {
        // With .app off every profile is co.za's, so it is switched on, as on production.
        $this->setEnv('sites.app.enabled', 'true');
        Services::resetSingle('site');
        $this->day($this->bakery, 'today', ['views' => 7, 'appearances' => 40, 'taps_call' => 2]);
        $this->day($this->abroad, 'today', ['views' => 500]);

        $html = $this->admin('admin/stats');

        $this->assertMatchesRegularExpression('#Profile views</dt>\s*<dd class="stats-tile-value">7<#', $html, 'only co.za profiles');
        $this->assertStringContainsString('admin/edit/' . $this->bakery . '#profile-stats', $html);
        $this->assertStringNotContainsString('Thames Loaf Bakery', $html);
        $this->assertStringContainsString('class="stats-chart"', $html);
        // The pending profile is not published; the bakery was opened.
        $this->assertMatchesRegularExpression('#\b0 published profiles had no views#', $html);
        $this->assertStringContainsString('Google is not set up', $html);
    }

    public function testAdminStatsShowsTheOtherSiteWhenAsked(): void
    {
        $this->setEnv('sites.app.enabled', 'true');
        Services::resetSingle('site');
        $this->day($this->abroad, 'today', ['views' => 500]);

        $html = $this->admin('admin/stats?site=app');

        $this->assertMatchesRegularExpression('#Profile views</dt>\s*<dd class="stats-tile-value">500<#', $html);
        $this->assertStringContainsString('Thames Loaf Bakery', $html);
        $this->assertStringNotContainsString('Cape Crumb Bakery', $html);
    }

    public function testAdminEditShowsTheFullPanel(): void
    {
        $this->day($this->bakery, 'today', ['views' => 3]);

        $html = $this->admin('admin/edit/' . $this->bakery);

        $this->assertStringContainsString('id="profile-stats"', $html);
        $this->assertStringContainsString('Full statistics', $html);
        $this->assertStringContainsString('This profile in the last 30 days', $html);
    }

    // ---------------------------------------------------------------- helpers

    private function admin(string $path): string
    {
        return (string) $this->withSession(['dir_admin' => true, \App\Filters\AdminFilter::SEEN_KEY => time()])->get($path)->getBody();
    }

    /** @param array<string,int> $counts */
    private function day(int $listingId, string $when, array $counts): void
    {
        db_connect()->table('directory_listing_stats')->insert(['listing_id' => $listingId, 'day' => date('Y-m-d', strtotime($when))] + $counts);
    }

    private function opened(int $listingId, string $query): void
    {
        db_connect()->table('directory_discovery_events')->insert([
            'query' => $query, 'listing_id' => $listingId, 'position' => 1, 'site' => 'za', 'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    private function manage(int $listingId): string
    {
        return (string) $this->withSession([Manage::SESSION_KEY => $listingId])->get('manage/edit')->getBody();
    }

    /** @return array<string,mixed> */
    private function forOwner(): array
    {
        $listing = (new DirectoryListingModel())->find($this->bakery);

        return (new ListingStatsService())->forOwner($listing, [['key' => 'tags', 'label' => 'Areas of focus', 'anchor' => 'field-tags', 'done' => false]], false);
    }

    private function panel(string $html): string
    {
        return preg_match('#<div class="panel stats-panel".*?<div class="panel strength-panel"#s', $html, $m) === 1 ? $m[0] : '';
    }
}

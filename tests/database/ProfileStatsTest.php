<?php

use App\Controllers\Manage;
use App\Models\DirectoryCategoryModel;
use App\Models\DirectoryListingModel;
use App\Services\ListingStatsService;
use CodeIgniter\Config\Services;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use Tests\Support\FreshDatabase;
use Tests\Support\WithEnv;

/**
 * Profile statistics (ListingStatsService): views, appearances and contact
 * taps, as daily totals with nothing about the visitor.
 *
 * Requires the `tests` database group — see the Tests section of README.md.
 *
 * @internal
 */
final class ProfileStatsTest extends CIUnitTestCase
{
    use FreshDatabase;
    use FeatureTestTrait;
    use WithEnv;

    private const BROWSER  = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 15_0) AppleWebKit/605.1.15 Safari/605.1.15';
    private const APP_HOST = 'local.webscheduler.app';

    private int $bakery;
    private int $pending;
    private int $abroad;

    protected function setUp(): void
    {
        parent::setUp();
        cache()->clean();

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
        config('Directory')->profileStats = true;
        $this->restoreEnv();
        Services::resetSingle('site');
        cache()->clean();
        parent::tearDown();
    }

    // ------------------------------------------------------------------ views

    public function testAViewCountsOncePerVisitorInTheWindow(): void
    {
        $this->browse('directory/cape-crumb-bakery')->assertOK();
        $this->browse('directory/cape-crumb-bakery')->assertOK();

        $this->assertSame(1, $this->total($this->bakery, 'views'));
    }

    public function testCrawlersAreNotCounted(): void
    {
        $this->withHeaders(['User-Agent' => 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)'])
            ->get('directory/cape-crumb-bakery')->assertOK();

        $this->assertSame(0, $this->total($this->bakery, 'views'));
    }

    public function testTheOwnerAndTheAdminAreNotCounted(): void
    {
        $this->withSession([Manage::SESSION_KEY => $this->bakery])->withHeaders(['User-Agent' => self::BROWSER])
            ->get('directory/cape-crumb-bakery')->assertOK();
        $this->withSession(['dir_admin' => true])->withHeaders(['User-Agent' => self::BROWSER])
            ->get('directory/cape-crumb-bakery')->assertOK();

        $this->assertSame(0, $this->total($this->bakery, 'views'));
    }

    public function testAnOwnerOfAnotherProfileStillCounts(): void
    {
        $this->withSession([Manage::SESSION_KEY => $this->abroad])->withHeaders(['User-Agent' => self::BROWSER])
            ->get('directory/cape-crumb-bakery')->assertOK();

        $this->assertSame(1, $this->total($this->bakery, 'views'));
    }

    public function testTheSwitchStopsCounting(): void
    {
        config('Directory')->profileStats = false;

        $this->browse('directory/cape-crumb-bakery')->assertOK();
        $this->browse('directory/bakeries')->assertOK();

        $this->assertSame(0, db_connect()->table('xs_directory_listing_stats')->countAllResults());
    }

    // ------------------------------------------------------------ appearances

    public function testAppearancesAreCountedOnResultsAndLandingPages(): void
    {
        $this->browse('directory/bakeries')->assertOK();
        $this->browse('directory?q=bakery')->assertOK();

        $this->assertSame(2, $this->total($this->bakery, 'appearances'));
        $this->assertSame(0, $this->total($this->bakery, 'views'));
        $this->assertSame(0, $this->total($this->pending, 'appearances'));
    }

    public function testAppearancesFollowTheSiteThePageBelongsTo(): void
    {
        $this->setEnv('sites.app.enabled', 'true');
        $this->withHeaders(['Host' => self::APP_HOST, 'User-Agent' => self::BROWSER])->get('directory?q=bakery')->assertOK();

        $this->assertSame(1, $this->total($this->abroad, 'appearances'));
        $this->assertSame(0, $this->total($this->bakery, 'appearances'));
    }

    // ------------------------------------------------------------------- taps

    public function testTheProfileCarriesTheTapMarkup(): void
    {
        $body = $this->browse('directory/cape-crumb-bakery')->getBody();

        $this->assertStringContainsString('data-stat-slug="cape-crumb-bakery"', $body);
        $this->assertStringContainsString('data-stat="call"', $body);
    }

    public function testATapIsCountedOnlyForAPublishedProfileOnThisSite(): void
    {
        $this->setEnv('sites.app.enabled', 'true');
        $this->tap('cape-crumb-bakery', 'call')->assertStatus(204);
        $this->tap('cape-crumb-bakery', 'whatsapp')->assertStatus(204);
        $this->tap('unconfirmed-bakery', 'call')->assertStatus(204);
        $this->tap('thames-loaf-bakery', 'call')->assertStatus(204);
        $this->tap('cape-crumb-bakery', 'fax')->assertStatus(204);
        // Email is counted where the address is fetched, never from the beacon.
        $this->tap('cape-crumb-bakery', 'email')->assertStatus(204);

        $totals = (new ListingStatsService())->totals($this->bakery, date('Y-m-d'), date('Y-m-d'));
        $this->assertSame(1, $totals['taps_call']);
        $this->assertSame(1, $totals['taps_whatsapp']);
        $this->assertSame(0, $totals['taps_email']);
        $this->assertSame(2, $totals['taps']);
        $this->assertSame(0, $this->total($this->pending, 'taps_call'));
        $this->assertSame(0, $this->total($this->abroad, 'taps_call'));
    }

    public function testShowingTheEmailCountsAsATap(): void
    {
        $this->browse('directory/cape-crumb-bakery/email')->assertOK();

        $this->assertSame(1, $this->total($this->bakery, 'taps_email'));
    }

    // -------------------------------------------------------------- retention

    public function testPruneKeepsTwentyFiveMonths(): void
    {
        $db = db_connect();
        $db->table('xs_directory_listing_stats')->insert(['listing_id' => $this->bakery, 'day' => date('Y-m-d', strtotime('-26 months')), 'views' => 3]);
        $db->table('xs_directory_listing_stats')->insert(['listing_id' => $this->bakery, 'day' => date('Y-m-d', strtotime('-24 months')), 'views' => 4]);

        $this->assertSame(1, (new ListingStatsService())->prune());
        $this->assertSame(4, $this->total($this->bakery, 'views', '-30 months'));
    }

    // ---------------------------------------------------------------- helpers

    private function browse(string $path)
    {
        return $this->withHeaders(['User-Agent' => self::BROWSER])->get($path);
    }

    private function tap(string $slug, string $kind)
    {
        return $this->withHeaders(['User-Agent' => self::BROWSER])->post('stats/tap', ['slug' => $slug, 'kind' => $kind]);
    }

    private function total(int $listingId, string $column, string $since = 'today'): int
    {
        return (new ListingStatsService())->totals($listingId, date('Y-m-d', strtotime($since)), date('Y-m-d'))[$column];
    }
}

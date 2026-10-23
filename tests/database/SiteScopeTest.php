<?php

use App\Models\DirectoryCategoryModel;
use App\Models\DirectoryListingModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;
use Tests\Support\WithEnv;

/**
 * Each site shows its own countries, and nothing changes until .app is live.
 *
 * This is the safety net for the one bug that would hurt: a public query that
 * skips the scope and puts South African profiles on local.webscheduler.app
 * (or the reverse). Every public page that lists profiles is fetched on both
 * hosts with one South African and one British profile published.
 *
 * Requires the `tests` database group — see the Tests section of README.md.
 *
 * @internal
 */
final class SiteScopeTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;
    use WithEnv;

    protected $namespace   = 'App';
    protected $migrate     = true;
    protected $migrateOnce = false;
    protected $refresh     = true;

    /** Not the app host, so za: the test baseURL's host. */
    private const ZA_HOST  = 'example.com';
    private const APP_HOST = 'local.webscheduler.app';

    /** Pages that list profiles. */
    private const PAGES = ['/', '/directory', '/directory?q=bakery', '/directory/bakeries', '/sitemap.xml'];

    protected function setUp(): void
    {
        parent::setUp();
        cache()->clean();

        $category = (int) (new DirectoryCategoryModel())->insert([
            'name' => 'Bakeries', 'slug' => 'bakeries', 'group_name' => 'Food & Drink', 'is_active' => 1,
        ], true);

        $listings = new DirectoryListingModel();
        $listings->insert([
            'type' => 'practice', 'display_name' => 'Cape Crumb Bakery', 'slug' => 'cape-crumb-bakery',
            'email' => 'cape@example.test', 'status' => 'published', 'category_id' => $category,
            'city' => 'Cape Town', 'province' => 'Western Cape', 'country' => 'South Africa',
            'published_at' => date('Y-m-d H:i:s'),
        ]);
        $listings->insert([
            'type' => 'practice', 'display_name' => 'Thames Loaf Bakery', 'slug' => 'thames-loaf-bakery',
            'email' => 'thames@example.test', 'status' => 'published', 'category_id' => $category,
            'city' => 'London', 'region' => 'England', 'country' => 'United Kingdom',
            'published_at' => date('Y-m-d H:i:s'),
        ]);
    }

    protected function tearDown(): void
    {
        $this->restoreEnv();
        Services::resetSingle('site');
        cache()->clean();
        parent::tearDown();
    }

    public function testWhileOffCoZaShowsEveryCountryAsToday(): void
    {
        $this->setEnv('sites.app.enabled', 'false');

        $body = $this->page(self::ZA_HOST, '/directory');

        $this->assertStringContainsString('Cape Crumb Bakery', $body);
        $this->assertStringContainsString('Thames Loaf Bakery', $body);
    }

    public function testWhileOffTheAppHostRedirectsToCoZa(): void
    {
        $this->setEnv('sites.app.enabled', 'false');

        $result = $this->withHeaders(['Host' => self::APP_HOST])->get('/directory?q=bread');

        $result->assertStatus(302);
        $this->assertSame(base_url('directory') . '?q=bread', $result->getRedirectUrl());
        $this->assertNotSame(self::APP_HOST, parse_url((string) $result->getRedirectUrl(), PHP_URL_HOST));
    }

    public function testOnceLiveEachSiteShowsOnlyItsOwnCountries(): void
    {
        $this->setEnv('sites.app.enabled', 'true');

        foreach (self::PAGES as $path) {
            $za = $this->page(self::ZA_HOST, $path);
            $this->assertStringNotContainsString('Thames Loaf', $za, 'co.za ' . $path . ' shows a British profile');
            $this->assertStringNotContainsString('thames-loaf-bakery', $za, 'co.za ' . $path . ' links a British profile');

            $app = $this->page(self::APP_HOST, $path);
            $this->assertStringNotContainsString('Cape Crumb', $app, '.app ' . $path . ' shows a South African profile');
            $this->assertStringNotContainsString('cape-crumb-bakery', $app, '.app ' . $path . ' links a South African profile');
        }

        $this->assertStringContainsString('Cape Crumb Bakery', $this->page(self::ZA_HOST, '/directory'));
        $this->assertStringContainsString('Thames Loaf Bakery', $this->page(self::APP_HOST, '/directory'));
    }

    public function testTheAdminIsNotServedOnTheAppHost(): void
    {
        $this->setEnv('sites.app.enabled', 'true');

        $this->withHeaders(['Host' => self::APP_HOST])->get('/admin/login')->assertStatus(404);
    }

    private function page(string $host, string $path): string
    {
        Services::resetSingle('site');
        $result = $this->withHeaders(['Host' => $host])->get($path);
        $result->assertOK();

        return (string) $result->getBody();
    }
}

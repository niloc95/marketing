<?php

use App\Models\DirectoryCategoryModel;
use App\Models\DirectoryListingModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;
use Config\Sites;
use Tests\Support\WithEnv;
use Tests\Support\FreshDatabase;

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
    use FreshDatabase;
    use FeatureTestTrait;
    use WithEnv;

    /** Not the app host, so za. Redirects go to Sites::host(ZA), the baseURL's host. */
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

    public function testOnceLiveTheDropdownFindsDescriptionsOnlyOnItsOwnSite(): void
    {
        $this->setEnv('sites.app.enabled', 'true');
        db_connect()->table('xs_directory_listings')->set('description_text', 'Sourdough baked daily.')->update();

        $za = $this->page(self::ZA_HOST, '/directory/suggest?q=sourdough');
        $this->assertStringContainsString('Cape Crumb Bakery', $za);
        $this->assertStringNotContainsString('Thames Loaf', $za);

        $app = $this->page(self::APP_HOST, '/directory/suggest?q=sourdough');
        $this->assertStringContainsString('Thames Loaf Bakery', $app);
        $this->assertStringNotContainsString('Cape Crumb', $app);
    }

    public function testOnceLiveAProfileOnTheWrongSiteMovesForGood(): void
    {
        $this->setEnv('sites.app.enabled', 'true');

        Services::resetSingle('site');
        $toApp = $this->withHeaders(['Host' => self::ZA_HOST])->get('/directory/thames-loaf-bakery?ref=x');
        $toApp->assertStatus(301);
        $this->assertSame(self::APP_HOST, parse_url((string) $toApp->getRedirectUrl(), PHP_URL_HOST));
        $this->assertStringEndsWith('/directory/thames-loaf-bakery?ref=x', (string) $toApp->getRedirectUrl());

        Services::resetSingle('site');
        $toZa = $this->withHeaders(['Host' => self::APP_HOST])->get('/directory/cape-crumb-bakery');
        $toZa->assertStatus(301);
        $this->assertSame(config(Sites::class)->host(Sites::ZA), parse_url((string) $toZa->getRedirectUrl(), PHP_URL_HOST));

        // Each on its own site renders.
        $this->assertStringContainsString('Thames Loaf Bakery', $this->page(self::APP_HOST, '/directory/thames-loaf-bakery'));
        $this->assertStringContainsString('Cape Crumb Bakery', $this->page(self::ZA_HOST, '/directory/cape-crumb-bakery'));
    }

    public function testWhileOffEveryProfileStaysOnCoZa(): void
    {
        $this->setEnv('sites.app.enabled', 'false');

        $this->assertStringContainsString('Thames Loaf Bakery', $this->page(self::ZA_HOST, '/directory/thames-loaf-bakery'));
    }

    public function testCoZaOnlyPagesMoveFromTheAppHost(): void
    {
        $this->setEnv('sites.app.enabled', 'true');

        foreach (['/careers', '/partners', '/verified', '/directory/province/western-cape', '/directory/bakeries/western-cape'] as $path) {
            Services::resetSingle('site');
            $result = $this->withHeaders(['Host' => self::APP_HOST])->get($path);
            $result->assertStatus(301);
            $this->assertSame(config(Sites::class)->host(Sites::ZA), parse_url((string) $result->getRedirectUrl(), PHP_URL_HOST), $path);
        }
    }

    public function testTheAppHasCountryAndRegionPages(): void
    {
        $this->setEnv('sites.app.enabled', 'true');

        $country = $this->page(self::APP_HOST, '/directory/united-kingdom');
        $this->assertStringContainsString('Local services in United Kingdom', $country);
        $this->assertStringContainsString('Thames Loaf Bakery', $country);
        $this->assertStringContainsString('/directory/united-kingdom/england', $country);

        $region = $this->page(self::APP_HOST, '/directory/united-kingdom/england');
        $this->assertStringContainsString('Local services in England', $region);
        $this->assertStringContainsString('Thames Loaf Bakery', $region);

        Services::resetSingle('site');
        $this->withHeaders(['Host' => self::APP_HOST])->get('/directory/united-kingdom/bavaria')->assertStatus(404);

        Services::resetSingle('site');
        $moved = $this->withHeaders(['Host' => self::ZA_HOST])->get('/directory/united-kingdom');
        $moved->assertStatus(301);
        $this->assertSame(self::APP_HOST, parse_url((string) $moved->getRedirectUrl(), PHP_URL_HOST));
    }

    public function testCountryPagesDoNotExistWhileTheAppIsOff(): void
    {
        $this->setEnv('sites.app.enabled', 'false');

        $this->withHeaders(['Host' => self::ZA_HOST])->get('/directory/united-kingdom')->assertStatus(404);
    }

    public function testTheAppSitemapListsOnlyItsOwnPages(): void
    {
        $this->setEnv('sites.app.enabled', 'true');

        $xml = $this->page(self::APP_HOST, '/sitemap.xml');
        foreach (['/careers', '/partners', '/compare', '/verified'] as $zaOnly) {
            $this->assertStringNotContainsString($zaOnly . '<', $xml, $zaOnly);
        }
        $this->assertStringContainsString('/jobs<', $xml);
    }

    public function testTheHomePagesPointAtEachOther(): void
    {
        $this->setEnv('sites.app.enabled', 'false');
        $this->assertStringNotContainsString('hreflang', $this->page(self::ZA_HOST, '/'));

        $this->setEnv('sites.app.enabled', 'true');
        foreach ([self::ZA_HOST, self::APP_HOST] as $host) {
            $this->assertStringContainsString('hreflang="en-ZA"', $this->page($host, '/'), $host);
        }

        // Only checkable from co.za here: under test, base_url() keeps the
        // configured host whatever the request's, so .app's own links would
        // read as co.za's. On a real server they follow the host.
        $this->assertStringContainsString('hreflang="x-default" href="http://local.webscheduler.app', $this->page(self::ZA_HOST, '/'));
    }

    public function testTheAppHasCategoryPagesPerCountryAndRegion(): void
    {
        $this->setEnv('sites.app.enabled', 'true');

        $all = $this->page(self::APP_HOST, '/directory/bakeries');
        $this->assertStringContainsString('Bakeries by country', $all);
        $this->assertStringContainsString('/directory/bakeries/united-kingdom', $all);

        $country = $this->page(self::APP_HOST, '/directory/bakeries/united-kingdom');
        $this->assertStringContainsString('Bakeries in United Kingdom', $country);
        $this->assertStringContainsString('Thames Loaf Bakery', $country);
        $this->assertStringContainsString('/directory/bakeries/united-kingdom/england', $country);

        $region = $this->page(self::APP_HOST, '/directory/bakeries/united-kingdom/england');
        $this->assertStringContainsString('Bakeries in England', $region);
        $this->assertStringContainsString('Thames Loaf Bakery', $region);

        Services::resetSingle('site');
        $moved = $this->withHeaders(['Host' => self::ZA_HOST])->get('/directory/bakeries/united-kingdom');
        $moved->assertStatus(301);
        $this->assertSame(self::APP_HOST, parse_url((string) $moved->getRedirectUrl(), PHP_URL_HOST));

        // co.za's own category page is untouched by any of it.
        $this->assertStringNotContainsString('by country', $this->page(self::ZA_HOST, '/directory/bakeries'));
    }

    public function testASearchOnTheAppReadsRegionsAndCountries(): void
    {
        $this->setEnv('sites.app.enabled', 'true');

        $this->assertStringContainsString('Thames Loaf Bakery', $this->page(self::APP_HOST, '/directory?q=bakery+in+england'));
        $this->assertStringContainsString('Thames Loaf Bakery', $this->page(self::APP_HOST, '/directory?q=bakery+united+kingdom'));
        $this->assertStringNotContainsString('Thames Loaf Bakery', $this->page(self::APP_HOST, '/directory?q=bakery+in+scotland'));
    }

    public function testTheAppHomeExamplesAreNeverSouthAfrican(): void
    {
        $this->setEnv('sites.app.enabled', 'true');

        // One British pair is too few for real examples: the app's own
        // fallbacks show, never the South African towns.
        $app = $this->page(self::APP_HOST, '/');
        $this->assertStringContainsString('Accountant', $app);
        $this->assertStringNotContainsString('Sandton', $app);
        $this->assertStringContainsString('Sandton', $this->page(self::ZA_HOST, '/'), 'co.za keeps its own fallbacks');

        // A profile with no town makes an example from its country.
        $categories = new DirectoryCategoryModel();
        $listings   = new DirectoryListingModel();
        foreach (['Attorney' => 'Portugal', 'Dentist' => 'India'] as $name => $country) {
            $category = (int) $categories->insert([
                'name' => $name, 'slug' => strtolower($name), 'group_name' => 'Services', 'is_active' => 1,
            ], true);
            $listings->insert([
                'type' => 'practice', 'display_name' => $name . ' Co', 'slug' => strtolower($name) . '-co',
                'email' => strtolower($name) . '@example.test', 'status' => 'published', 'category_id' => $category,
                'city' => '', 'country' => $country, 'published_at' => date('Y-m-d H:i:s'),
            ]);
        }

        $app = $this->page(self::APP_HOST, '/');
        $this->assertStringContainsString('Attorney in Portugal', $app);
        $this->assertStringContainsString('Bakeries in London', $app);
        $this->assertStringNotContainsString('Sandton', $app);
    }

    public function testEachSiteHasItsOwnTermsAndPrivacy(): void
    {
        $this->setEnv('sites.app.enabled', 'true');

        $appTerms = $this->page(self::APP_HOST, '/terms');
        $this->assertStringContainsString('businesses, services and professionals outside South Africa', $appTerms);
        $this->assertStringNotContainsString('International Profile', $appTerms);
        $this->assertStringContainsString('governed by the law of the Republic of South Africa', $appTerms);

        $appPrivacy = $this->page(self::APP_HOST, '/privacy');
        $this->assertStringContainsString('UK General Data Protection Regulation', $appPrivacy);
        $this->assertStringNotContainsString('representative', $appPrivacy, 'no UK representative named (owner, 24 Oct 2026)');

        $this->assertStringContainsString('International Profile', $this->page(self::ZA_HOST, '/terms'));
        $this->assertStringNotContainsString('UK General Data Protection Regulation', $this->page(self::ZA_HOST, '/privacy'));

        Services::resetSingle('site');
        Services::injectMock('site', new \App\Services\SiteContext(self::APP_HOST));
        $this->assertSame(\App\Controllers\Legal::APP_LAST_UPDATED['terms'], \App\Controllers\Legal::termsVersion());
    }

    public function testTheAppHasItsOwnFaqAndAboutPage(): void
    {
        $this->setEnv('sites.app.enabled', 'true');

        $faq = $this->page(self::APP_HOST, '/faq');
        $this->assertStringContainsString('What is Plus?', $faq);
        $this->assertStringContainsString('Changing or removing your profile', $faq);
        $this->assertStringNotContainsString('International Profile', $faq);
        $this->assertStringNotContainsString('Verified Business badge', $faq);

        $company = $this->page(self::APP_HOST, '/company');
        $this->assertStringContainsString('for businesses everywhere', $company);
        $this->assertStringNotContainsString('built for South Africa', $company);

        // co.za's are untouched.
        $this->assertStringContainsString('International Profile', $this->page(self::ZA_HOST, '/faq'));
        $this->assertStringContainsString('built for South Africa', $this->page(self::ZA_HOST, '/company'));
    }

    public function testPlusHasItsOwnExplainerAsVerifiedDoes(): void
    {
        $this->setEnv('sites.app.enabled', 'true');

        $plus = $this->page(self::APP_HOST, '/plus');
        $this->assertStringContainsString('What Plus means', $plus);
        $this->assertStringContainsString('That we have checked the business', $plus, 'what it is not, as prominent as what it is');
        $this->assertStringContainsString('Plus businesses', $plus, 'linked from the footer');
        $this->assertStringContainsString('/plus<', $this->page(self::APP_HOST, '/sitemap.xml'));

        Services::resetSingle('site');
        $moved = $this->withHeaders(['Host' => self::ZA_HOST])->get('/plus');
        $moved->assertStatus(301);
        $this->assertSame(self::APP_HOST, parse_url((string) $moved->getRedirectUrl(), PHP_URL_HOST));
        $this->assertStringContainsString('Verified businesses', $this->page(self::ZA_HOST, '/'));
    }

    public function testEachSiteCarriesOnlyItsOwnOwnershipTags(): void
    {
        $this->setEnv('sites.app.enabled', 'true');

        $this->assertStringContainsString('<meta name="msvalidate.01" content="DCE52B24BAE5E4896E636D6B6BD0A921">', $this->page(self::APP_HOST, '/'));
        $this->assertStringNotContainsString('msvalidate.01', $this->page(self::ZA_HOST, '/'));
    }

    public function testTheAdminIsNotServedOnTheAppHost(): void
    {
        $this->setEnv('sites.app.enabled', 'true');

        $result = $this->withHeaders(['Host' => self::APP_HOST])->get('/admin/login');

        // Never served here: sent to the one admin, on co.za.
        $result->assertStatus(302);
        $this->assertSame(config(Sites::class)->host(Sites::ZA), parse_url((string) $result->getRedirectUrl(), PHP_URL_HOST));
        $this->assertStringEndsWith('/admin/login', (string) $result->getRedirectUrl());
    }

    private function page(string $host, string $path): string
    {
        Services::resetSingle('site');
        $result = $this->withHeaders(['Host' => $host])->get($path);
        $result->assertOK();

        return (string) $result->getBody();
    }
}

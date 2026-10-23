<?php

use App\Services\SiteContext;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Services;
use Config\Sites;
use Tests\Support\WithEnv;

/**
 * Which site a request is on, which site a country belongs to, and links
 * between the two.
 *
 * The rule that matters most: while sites.app.enabled is false everything is
 * za, so co.za and every email keep doing exactly what they did before.
 *
 * @internal
 */
final class SiteContextTest extends CIUnitTestCase
{
    use WithEnv;

    protected function tearDown(): void
    {
        $this->restoreEnv();
        Services::resetSingle('site');
        parent::tearDown();
    }

    public function testOnlyTheAppHostIsTheAppSite(): void
    {
        $this->assertSame(Sites::APP, (new SiteContext('local.webscheduler.app'))->key());
        $this->assertSame(Sites::APP, (new SiteContext('LOCAL.webscheduler.app:443'))->key());
        $this->assertSame(Sites::ZA, (new SiteContext('local.webscheduler.co.za'))->key());
        $this->assertSame(Sites::ZA, (new SiteContext('listing.webscheduler.co.za'))->key());
        $this->assertSame(Sites::ZA, (new SiteContext(''))->key());
        $this->assertSame(Sites::ZA, (new SiteContext(null))->key());
    }

    public function testEveryCountryIsZaUntilTheAppSiteIsLive(): void
    {
        $this->setEnv('sites.app.enabled', 'false');
        $site = new SiteContext('local.webscheduler.app');

        $this->assertSame(Sites::ZA, $site->forCountry('GB'));
        $this->assertFalse($site->scoping());
        $this->assertSame('', $site->scopeSql('country_code'));
    }

    public function testOnceLiveForeignCountriesBelongToApp(): void
    {
        $this->setEnv('sites.app.enabled', 'true');
        $za  = new SiteContext('local.webscheduler.co.za');
        $app = new SiteContext('local.webscheduler.app');

        $this->assertSame(Sites::APP, $za->forCountry('gb'));
        $this->assertSame(Sites::ZA, $za->forCountry('ZA'));
        $this->assertSame(Sites::ZA, $za->forCountry(null));
        $this->assertTrue($app->ownsCountry('KE'));
        $this->assertFalse($app->ownsCountry('ZA'));
        $this->assertSame(Sites::APP, $za->forRow(['country_code' => 'US']));

        $this->assertSame("(l.country_code IS NULL OR l.country_code = 'ZA')", $za->scopeSql('l.country_code'));
        $this->assertSame("(l.country_code IS NOT NULL AND l.country_code <> 'ZA')", $app->scopeSql('l.country_code'));
    }

    public function testLinksToTheOtherSiteSwapOnlyTheHost(): void
    {
        $this->setEnv('sites.app.enabled', 'true');
        Services::injectMock('site', new SiteContext(null));

        // On the current site it is base_url() itself.
        $this->assertSame(base_url('faq'), site_url_for(Sites::ZA, 'faq'));

        $app = site_url_for(Sites::APP, 'directory/london-bakery');
        $this->assertSame('local.webscheduler.app', parse_url($app, PHP_URL_HOST));
        $this->assertSame(parse_url(base_url(), PHP_URL_SCHEME), parse_url($app, PHP_URL_SCHEME));
        $this->assertStringEndsWith('/directory/london-bakery', $app);

        $this->assertSame('local.webscheduler.app', parse_url(listing_url(['slug' => 'x', 'country_code' => 'GB']), PHP_URL_HOST));
        $this->assertSame(base_url('directory/x'), listing_url(['slug' => 'x', 'country_code' => 'ZA']));
    }

    public function testCacheKeysOnlyChangeOnTheAppSite(): void
    {
        Services::injectMock('site', new SiteContext(null));
        $this->assertSame('directory_sitemap_xml', site_cache_key('directory_sitemap_xml'));

        Services::injectMock('site', new SiteContext('local.webscheduler.app'));
        $this->assertSame('directory_sitemap_xml_app', site_cache_key('directory_sitemap_xml'));
    }
}

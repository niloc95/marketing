<?php

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * /company, the directory's own company page, and our social links.
 *
 * Pinned: the page renders with its canonical, carries every link in
 * Config\Directory::$socialLinks (on the page and in the footer), and no
 * longer sends anyone to the marketing site.
 *
 * @internal
 */
final class CompanyPageTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $namespace   = 'App';
    protected $migrate     = true;
    protected $migrateOnce = false;
    protected $refresh     = true;

    public function testTheAboutPageRenders(): void
    {
        $result = $this->get('company');

        $result->assertStatus(200);
        $html = html_entity_decode((string) $result->response()->getBody());
        $this->assertStringContainsString('A local business discovery and visibility platform, built for South Africa', $html);
        $this->assertStringContainsString('<link rel="canonical" href="' . base_url('company') . '"', $html);
        $this->assertStringContainsString('"AboutPage"', $html);
    }

    public function testOurSocialLinksAppearOnThePageAndInTheFooter(): void
    {
        $html  = (string) $this->get('company')->response()->getBody();
        $links = config('Directory')->socialLinks;

        $this->assertNotEmpty($links);
        foreach ($links as $url) {
            // Once in the closing section, once in the footer.
            $this->assertSame(2, substr_count($html, 'href="' . $url . '"'), $url);
        }
    }

    public function testNeitherThePageNorTheFooterLinksToTheMarketingSite(): void
    {
        $html = (string) $this->get('company')->response()->getBody();

        $this->assertStringContainsString('href="' . base_url('company') . '"', $html);
        $this->assertStringNotContainsString('href="https://webscheduler.co.za', $html);
    }

    public function testTheOrganizationListsOurProfilesAsSameAs(): void
    {
        $org = schema_organization();

        $this->assertSame(array_values(config('Directory')->socialLinks), $org['sameAs']);
    }
}

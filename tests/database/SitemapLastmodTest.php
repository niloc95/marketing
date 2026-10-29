<?php

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use Tests\Support\FreshDatabase;

/**
 * The sitemap's lastmod for its fixed pages.
 *
 * Pinned: a page dates from its view's entry in the release bundle's
 * view-lastmod.json, not from today; /resources also moves when one of its
 * PDFs changes; and a page with no entry still gets a date.
 *
 * Requires the `tests` database group — see the Tests section of README.md.
 *
 * @internal
 */
final class SitemapLastmodTest extends CIUnitTestCase
{
    use FreshDatabase;
    use FeatureTestTrait;

    private string $manifest = ROOTPATH . 'view-lastmod.json';

    protected function setUp(): void
    {
        parent::setUp();
        cache()->delete(site_cache_key('directory_sitemap_xml'));
    }

    protected function tearDown(): void
    {
        if (is_file($this->manifest)) {
            unlink($this->manifest);
        }
        cache()->delete(site_cache_key('directory_sitemap_xml'));
        parent::tearDown();
    }

    public function testFixedPagesDateFromTheirViewNotFromToday(): void
    {
        file_put_contents($this->manifest, json_encode([
            'app/Views/directory/compare.php'                  => '2026-10-01',
            'app/Views/directory/resources.php'                => '2026-10-02',
            'public/assets/documents/a-guide.pdf'              => '2026-10-05',
            'app/Views/directory/verified.php'                 => '2026-09-30',
            'app/Views/directory/compare_unrelated_partial.php' => '2026-10-20',
        ]));

        $xml = (string) $this->get('sitemap.xml')->response()->getBody();

        $this->assertStringContainsString('<loc>' . base_url('compare') . '</loc><lastmod>2026-10-01</lastmod>', $xml);
        $this->assertStringContainsString('<loc>' . base_url('resources') . '</loc><lastmod>2026-10-05</lastmod>', $xml);
        $this->assertStringContainsString('<loc>' . base_url('verified') . '</loc><lastmod>2026-09-30</lastmod>', $xml);
        // Not in the manifest: still a valid date.
        $this->assertMatchesRegularExpression('#<loc>' . preg_quote(base_url('faq'), '#') . '</loc><lastmod>\d{4}-\d{2}-\d{2}</lastmod>#', $xml);
    }
}

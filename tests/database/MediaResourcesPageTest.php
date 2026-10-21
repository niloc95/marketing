<?php

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * /media, the logo downloads and usage rules.
 *
 * Pinned: every logo file it offers exists and is offered as a download; the
 * Verified Business badge is shown but never offered as a file; the legal
 * warning is on the page; and the footer and sitemap link to it.
 *
 * @internal
 */
final class MediaResourcesPageTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $namespace   = 'App';
    protected $migrate     = true;
    protected $migrateOnce = false;
    protected $refresh     = true;

    private function html(string $uri): string
    {
        $result = $this->get($uri);
        $result->assertStatus(200);

        return (string) $result->response()->getBody();
    }

    public function testThePageRendersWithItsCanonical(): void
    {
        $html = $this->html('media');

        $this->assertStringContainsString('<link rel="canonical" href="' . base_url('media') . '"', $html);
        $this->assertStringContainsString('Our logo, for your website', $html);
    }

    public function testEveryLogoIsADownloadOfAFileThatExists(): void
    {
        $html = $this->html('media');

        foreach (['logo-1024.png', 'logo-512.png', 'logo-256.png', 'social-1080.png'] as $file) {
            $this->assertFileExists(FCPATH . 'assets/brand/' . $file);
            $this->assertMatchesRegularExpression(
                '#href="' . preg_quote(base_url('assets/brand/' . $file), '#') . '\?v=\d+" download="webscheduler-local-logo#',
                $html,
                $file
            );
        }
    }

    public function testTheVerifiedBadgeIsNeverOfferedAsAFile(): void
    {
        $html = $this->html('media');

        // Shown, inlined, so visitors know which mark the warning is about...
        $this->assertStringContainsString('The Verified Business badge', $html);
        $this->assertStringContainsString('<title>WebScheduler Local Verified Business</title>', $html);
        // ...but never linked, embedded as an image or offered as a download.
        $this->assertStringNotContainsString('verified-seal.svg', $html);
        $this->assertSame(4, substr_count($html, ' download="'));
    }

    public function testTheLegalWarningIsOnThePage(): void
    {
        $text = preg_replace('/\s+/', ' ', html_entity_decode($this->html('media')));

        $this->assertStringContainsString(
            'Should the Verified Business badge be found used unlawfully, WebScheduler Local will take immediate legal action',
            $text
        );
    }

    public function testTheTermsCarryTheSameRulesAndThePageLinksToThem(): void
    {
        $terms = preg_replace('/\s+/', ' ', html_entity_decode($this->html('terms')));

        $this->assertStringContainsString('<h2 id="brand">20. Our name, logo and the Verified Business badge</h2>', $terms);
        $this->assertStringContainsString('It is not available for download', $terms);
        $this->assertStringContainsString(
            'Should the Verified Business badge be found used unlawfully, WebScheduler Local will take immediate legal action',
            $terms
        );
        $this->assertStringContainsString('href="' . base_url('media') . '"', $terms);
        $this->assertStringContainsString('href="' . base_url('terms#brand') . '"', $this->html('media'));
    }

    public function testTheFooterAndSitemapLinkToIt(): void
    {
        $this->assertStringContainsString('href="' . base_url('media') . '">Media resources</a>', $this->html('company'));
        $this->assertStringContainsString('<loc>' . base_url('media') . '</loc>', $this->html('sitemap.xml'));
    }
}

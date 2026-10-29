<?php

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use Tests\Support\FreshDatabase;

/**
 * /resources, the guides and reports.
 *
 * Pinned: every document rolls down (a <details>), says what is inside, and
 * offers both an Open link (new tab) and a Download link (download attribute)
 * to a file that exists, with the ?v= stamp that spares a Cloudflare purge.
 *
 * @internal
 */
final class ResourcesPageTest extends CIUnitTestCase
{
    use FreshDatabase;
    use FeatureTestTrait;

    private function html(): string
    {
        $result = $this->get('resources');
        $result->assertStatus(200);

        return (string) $result->response()->getBody();
    }

    public function testThePageRendersWithItsCanonical(): void
    {
        $html = $this->html();

        $this->assertStringContainsString('<link rel="canonical" href="' . base_url('resources') . '"', $html);
        $this->assertStringContainsString('Free reading for business owners', $html);
    }

    public function testEveryDocumentRollsDownWithOpenAndDownload(): void
    {
        $html = $this->html();

        preg_match_all('#<details class="resource" name="resources">(.*?)</details>#s', $html, $rows);
        $this->assertGreaterThanOrEqual(5, count($rows[1]), 'every guide is a roll down row');
        $this->assertStringNotContainsString('<details class="resource" name="resources" open', $html, 'rows start closed');

        foreach ($rows[1] as $row) {
            $this->assertStringContainsString('<summary class="resource-summary">', $row);
            $this->assertStringContainsString('<strong>Inside:</strong>', $row);

            $this->assertSame(1, preg_match('#href="([^"]+\.pdf\?v=\d+)" target="_blank" rel="noopener">#', $row, $open), 'an Open link in a new tab');
            $this->assertSame(1, preg_match('#href="([^"]+\.pdf\?v=\d+)" download="([^"]+\.pdf)">#', $row, $download), 'a Download link');
            $this->assertSame($open[1], $download[1], 'Open and Download serve the same file');

            $path = FCPATH . ltrim((string) parse_url($open[1], PHP_URL_PATH), '/');
            $this->assertFileExists($path);
            $this->assertSame(basename($path), $download[2], 'saved under its published name');
        }
    }

    public function testTheProfileGuideIsListedFirst(): void
    {
        preg_match('#<details class="resource" name="resources">.*?<h2>([^<]+)</h2>#s', $this->html(), $first);

        $this->assertSame('Profile guide', $first[1] ?? null);
    }
}

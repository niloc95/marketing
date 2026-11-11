<?php

use App\Models\DirectoryCategoryModel;
use App\Models\DirectoryListingModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use Tests\Support\FreshDatabase;

/**
 * What the SEO review of 11 Nov 2026 changed (docs/audits/2026-11-seo-review.md):
 * paginated lists index each page under its own canonical, the 404 page names
 * no canonical, a capitalised profile slug redirects, the sitemap has one
 * Cache-Control value, and our own text never prints an en dash.
 *
 * @internal
 */
final class SeoReviewTest extends CIUnitTestCase
{
    use FreshDatabase;
    use FeatureTestTrait;

    private int $category;

    protected function setUp(): void
    {
        parent::setUp();
        cache()->clean();

        $this->category = (int) (new DirectoryCategoryModel())->insert([
            'name' => 'Bakeries', 'slug' => 'bakeries', 'group_name' => 'Food & Drink', 'is_active' => 1,
        ], true);
    }

    public function testEveryPageOfAQualifyingListIsIndexedUnderItsOwnCanonical(): void
    {
        $this->bakeries(30);

        $one = (string) $this->get('directory/bakeries')->getBody();
        $this->assertStringContainsString('<link rel="canonical" href="' . base_url('directory/bakeries') . '">', $one);
        $this->assertStringContainsString('<link rel="next" href="' . base_url('directory/bakeries') . '?page=2">', $one);
        $this->assertStringNotContainsString('rel="prev"', $one);

        $two = (string) $this->get('directory/bakeries?page=2')->getBody();
        $this->assertStringContainsString('<meta name="robots" content="index, follow">', $two);
        $this->assertStringContainsString('<link rel="canonical" href="' . base_url('directory/bakeries') . '?page=2">', $two);
        $this->assertStringContainsString('<link rel="prev" href="' . base_url('directory/bakeries') . '">', $two);
        $this->assertStringContainsString('(page 2) |', $two);

        // A sorted list is still a duplicate, on any page.
        $sorted = (string) $this->get('directory/bakeries?page=2&sort=new')->getBody();
        $this->assertStringContainsString('<meta name="robots" content="noindex, follow">', $sorted);
        $this->assertStringContainsString('<link rel="canonical" href="' . base_url('directory/bakeries') . '">', $sorted);

        // So is a search, however deep.
        $search = (string) $this->get('directory?q=bread&page=2')->getBody();
        $this->assertStringContainsString('<meta name="robots" content="noindex, follow">', $search);
        $this->assertStringNotContainsString('rel="next"', $search);
    }

    public function testThe404PageNamesNoCanonical(): void
    {
        $result = $this->get('no-such-page-at-all');
        $result->assertStatus(404);
        $this->assertStringNotContainsString('rel="canonical"', (string) $result->getBody());
        $this->assertStringNotContainsString('og:url', (string) $result->getBody());
    }

    public function testACapitalisedProfileSlugRedirectsToTheLowercaseOne(): void
    {
        $this->bakeries(1);

        $result = $this->get('directory/Bakery-1');
        $result->assertStatus(301);
        $this->assertSame(base_url('directory/bakery-1'), $result->getRedirectUrl());
    }

    public function testTheSitemapHasOneCacheControlValue(): void
    {
        $result = $this->get('sitemap.xml');
        $result->assertOK();
        $this->assertSame('public, max-age=900', $result->response()->getHeaderLine('Cache-Control'));

        $xml = (string) $result->getBody();
        $this->assertStringContainsString(base_url('recommend') . '<', $xml);
        $this->assertStringContainsString(base_url('jobs/post') . '<', $xml);
    }

    public function testOurOwnTextOnAProfilePrintsNoEnDash(): void
    {
        $this->bakeries(1, ['trading_hours' => json_encode([
            'mon' => ['open' => '08:00', 'close' => '17:00'], 'tue' => ['open' => '08:00', 'close' => '17:00'],
            'wed' => ['open' => '08:00', 'close' => '17:00'], 'thu' => ['open' => '08:00', 'close' => '17:00'],
            'fri' => ['open' => '08:00', 'close' => '17:00'], 'sat' => ['closed' => true], 'sun' => ['closed' => true],
        ])]);

        $html = (string) $this->get('directory/bakery-1')->getBody();
        $this->assertStringContainsString('Mon to Fri 08:00 to 17:00', $html, 'the meta description reads the hours in words');
        // Comments and scripts aside, the page we write has no en dash in it.
        $text = preg_replace(['#<script\b.*?</script>#s', '#<!--.*?-->#s'], '', $html) ?? $html;
        $this->assertStringNotContainsString('–', $text);
    }

    public function testTheLogoHasAnAlternativeText(): void
    {
        $this->bakeries(1, ['logo_path' => 'listings/logo-test.webp']);

        $this->assertStringContainsString('alt="Bakery 1 logo"', (string) $this->get('directory/bakery-1')->getBody());
    }

    /** @param array<string,mixed> $overrides */
    private function bakeries(int $count, array $overrides = []): void
    {
        $listings = new DirectoryListingModel();
        for ($i = 1; $i <= $count; $i++) {
            $listings->insert($overrides + [
                'type' => 'practice', 'display_name' => 'Bakery ' . $i, 'slug' => 'bakery-' . $i,
                'email' => 'bakery' . $i . '@example.test', 'status' => 'published', 'is_verified' => 1,
                'category_id' => $this->category, 'city' => 'Cape Town', 'province' => 'Western Cape',
                'country' => 'South Africa', 'description' => 'Fresh bread baked every morning in Cape Town, with cakes to order.',
                'published_at' => date('Y-m-d H:i:s'),
            ]);
        }
    }
}

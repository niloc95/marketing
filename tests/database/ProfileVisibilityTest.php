<?php

use App\Models\DirectoryCategoryModel;
use App\Models\DirectoryListingModel;
use App\Services\DirectoryService;
use App\Services\ProfileNudgeService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * What the quality score does besides order search results: keep thin
 * profiles away from Google, and send their owners one nudge.
 *
 * What these pin:
 *  - a scored profile under Config\Directory::$indexMinQuality is noindex and
 *    out of the sitemap, one at or above it is in both, and an unscored one is
 *    let through (the fail-open rule recent() uses);
 *  - the page itself still renders for a thin profile — publishing is never
 *    gated on score;
 *  - the nudge selects only the day window, under target, opted in, never sent;
 *  - it stamps after a send and never mails the same profile twice.
 *
 * Requires the `tests` database group — see the Tests section of README.md.
 *
 * @internal
 */
final class ProfileVisibilityTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $namespace   = 'App';
    protected $migrate     = true;
    protected $migrateOnce = false;
    protected $refresh     = true;

    private DirectoryListingModel $listings;
    private int $categoryId;

    protected function setUp(): void
    {
        parent::setUp();
        helper('directory_ui');
        $this->listings   = new DirectoryListingModel();
        $this->categoryId = (int) (new DirectoryCategoryModel())->insert([
            'name' => 'Yoga Studios', 'slug' => 'yoga-studios',
            'group_name' => 'Fitness & Sport', 'is_active' => 1,
        ], true);
        cache()->delete('directory_sitemap_xml');
    }

    // ------------------------------------------------------------- Google

    public function testTheIndexRuleFailsOpenOnlyForUnscoredProfiles(): void
    {
        $min = (int) config('Directory')->indexMinQuality;

        $this->assertTrue(listing_is_indexable(['quality_scored_at' => null, 'quality_score' => 0]));
        $this->assertFalse(listing_is_indexable(['quality_scored_at' => '2026-10-08 03:15:00', 'quality_score' => $min - 1]));
        $this->assertTrue(listing_is_indexable(['quality_scored_at' => '2026-10-08 03:15:00', 'quality_score' => $min]));
    }

    public function testTheSitemapLeavesOutThinProfiles(): void
    {
        $this->listing('thin', 30);
        $this->listing('solid', 50);
        $this->listing('unscored', null);

        $locs = array_column((new DirectoryService())->sitemapUrls(), 'loc');

        $this->assertNotContains(base_url('directory/thin'), $locs);
        $this->assertContains(base_url('directory/solid'), $locs);
        $this->assertContains(base_url('directory/unscored'), $locs);
    }

    public function testAThinProfileIsLiveButNoindex(): void
    {
        $this->listing('thin', 30);
        $this->listing('solid', 50);

        $thin = $this->get('directory/thin');
        $thin->assertStatus(200);
        $this->assertStringContainsString('<meta name="robots" content="noindex, follow">', $thin->getBody());

        $solid = $this->get('directory/solid');
        $solid->assertStatus(200);
        $this->assertStringContainsString('<meta name="robots" content="index, follow">', $solid->getBody());
    }

    // -------------------------------------------------------------- nudge

    public function testOnlyProfilesInTheWindowUnderTargetAndOptedInAreDue(): void
    {
        $due      = $this->listing('due', 30, ['published_at' => $this->daysAgo(4)]);
        $this->listing('too-new', 30, ['published_at' => $this->daysAgo(1)]);
        $this->listing('too-old', 30, ['published_at' => $this->daysAgo(40)]);
        $this->listing('strong', 80, ['published_at' => $this->daysAgo(4)]);
        $this->listing('opted-out', 30, ['published_at' => $this->daysAgo(4), 'marketing_opt_in' => 0]);
        $this->listing('sent', 30, ['published_at' => $this->daysAgo(4), 'quality_nudge_sent_at' => $this->daysAgo(1)]);
        $this->listing('unscored', null, ['published_at' => $this->daysAgo(4)]);

        $ids = array_map('intval', array_column((new ProfileNudgeService())->due(3, 65), 'id'));

        $this->assertSame([$due], $ids);
    }

    public function testANudgeIsSentOnceAndNamesTheProfile(): void
    {
        $id      = $this->listing('flow-yoga', 30, ['published_at' => $this->daysAgo(4)]);
        $service = new ProfileNudgeService();

        $this->assertSame('sent', $service->nudge($this->listings->find($id), 65));

        $body = html_entity_decode((string) (service('email')->archive['body'] ?? ''));
        $this->assertStringContainsString('directory/flow-yoga', $body);
        $this->assertStringContainsString('Finish my profile', $body);
        $this->assertMatchesRegularExpression('#/manage/[A-Za-z0-9_-]{20,}#', $body);
        $this->assertStringContainsString('/unsubscribe/', $body);

        $this->assertNotNull($this->listings->find($id)['quality_nudge_sent_at']);
        $this->assertSame([], $service->due(3, 65));
    }

    public function testAProfileThatCrossedTheTargetIsStampedButNotMailed(): void
    {
        $id = $this->listing('flow-yoga', 30, ['published_at' => $this->daysAgo(4)]);
        service('email')->archive = [];

        // A target of 1 stands in for "they filled it in this morning": the
        // fresh re-score inside nudge() clears it, so no email goes.
        $this->assertSame('improved', (new ProfileNudgeService())->nudge($this->listings->find($id), 1));
        $this->assertSame([], service('email')->archive);
        $this->assertNotNull($this->listings->find($id)['quality_nudge_sent_at']);
    }

    // ------------------------------------------------------------ helpers

    /**
     * A published listing, its score forced to $score (null = never scored).
     *
     * @param array<string,mixed> $overrides
     */
    private function listing(string $slug, ?int $score, array $overrides = []): int
    {
        $id = (int) $this->listings->insert(array_merge([
            'display_name'     => ucwords(str_replace('-', ' ', $slug)),
            'slug'             => $slug,
            'email'            => $slug . '@example.test',
            'category_id'      => $this->categoryId,
            'address_line'     => '1 Adderley Street',
            'city'             => 'Cape Town',
            'province'         => 'Western Cape',
            'postal_code'      => '8001',
            'status'           => 'published',
            'is_verified'      => 1,
            'marketing_opt_in' => 1,
            'published_at'     => date('Y-m-d H:i:s'),
        ], $overrides), true);

        $this->listings->db->table('xs_directory_listings')->where('id', $id)->update([
            'quality_score'     => $score ?? 0,
            'quality_scored_at' => $score === null ? null : date('Y-m-d H:i:s'),
        ]);

        return $id;
    }

    private function daysAgo(int $days): string
    {
        return date('Y-m-d H:i:s', strtotime("-{$days} days"));
    }
}

<?php

use App\Filters\AdminFilter;
use App\Models\DirectoryCategoryModel;
use App\Models\DirectoryListingModel;
use App\Services\DirectoryService;
use App\Services\Search\DiscoveryEventService;
use App\Services\Search\DiscoveryModelService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use Tests\Support\FreshDatabase;

/**
 * Layers 2 to 4 of the discovery model: related words that widen a search,
 * spelling from the platform's own words, and learning from what people
 * open, plus the owner's block list at /admin/discovery.
 *
 * What these pin: template words never become related words, a widened
 * match always ranks below a direct one, a known word is never "corrected",
 * nothing identifying is stored with a search, crawlers teach nothing, and a
 * blocked link stays gone after the nightly rebuild.
 *
 * @internal
 */
final class DiscoveryLayersTest extends CIUnitTestCase
{
    use FreshDatabase;
    use FeatureTestTrait;

    private const BROWSER = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 15_0) AppleWebKit/605.1.15 Safari/605.1.15';

    private DirectoryListingModel $listings;
    private int $oncology;
    /** @var list<int> */
    private array $oncologists = [];

    protected function setUp(): void
    {
        parent::setUp();
        cache()->clean();
        $this->listings = new DirectoryListingModel();
        $categories     = new DirectoryCategoryModel();
        $this->oncology = (int) $categories->insert(['name' => 'Oncologist', 'slug' => 'oncologist', 'group_name' => 'Health', 'is_active' => 1], true);

        // Imported profiles share a template sentence; only "chemotherapy"
        // is also a topic (a service), so only it may become a related word.
        foreach (['a', 'b', 'c'] as $n) {
            $id = $this->listing('Onco ' . $n, $this->oncology, 'Cancer care, guiding patients through chemotherapy.');
            db_connect()->table('xs_directory_listing_services')->insert(['listing_id' => $id, 'name' => 'Chemotherapy', 'sort_order' => 0]);
            $this->oncologists[] = $id;
        }
        foreach (['Plumbers' => 'Geyser repairs and burst pipes.', 'Cafes' => 'Coffee and cake daily.', 'Bakeries' => 'Fresh bread every morning.'] as $name => $text) {
            $c = (int) $categories->insert(['name' => $name, 'slug' => strtolower($name), 'group_name' => 'Other', 'is_active' => 1], true);
            foreach (['a', 'b', 'c'] as $n) {
                $this->listing($name . ' ' . $n, $c, $text);
            }
        }
    }

    protected function tearDown(): void
    {
        foreach (['discoveryExpand', 'discoverySpelling', 'discoveryLog'] as $switch) {
            config('Search')->{$switch} = true;
        }
        cache()->clean();
        parent::tearDown();
    }

    // ------------------------------------------------------- related words

    public function testRelatedWordsAreTopicsNeverTemplateWords(): void
    {
        $model = new DiscoveryModelService();
        $model->learn();

        $this->assertSame(['chemotherapy'], $model->expansions('cancer'));
    }

    public function testAWidenedMatchIsFoundAndRanksBelowADirectOne(): void
    {
        $this->listing('Chemo Only Clinic', $this->oncology, 'We offer chemotherapy in comfortable rooms.');

        $result = (new DirectoryService())->browse(['q' => 'cancer', 'expand' => ['chemotherapy']]);
        $names  = array_column($result['items'], 'display_name');

        $this->assertContains('Chemo Only Clinic', $names);
        $this->assertSame('Chemo Only Clinic', end($names), 'a related word scores below every direct match');
        $this->assertNotContains('Chemo Only Clinic', array_column((new DirectoryService())->browse(['q' => 'cancer'])['items'], 'display_name'));
    }

    public function testTheResultsPageSaysWhatItAlsoLookedFor(): void
    {
        (new DiscoveryModelService())->learn();

        $this->assertStringContainsString('Also including profiles that mention chemotherapy', (string) $this->get('directory?q=cancer')->getBody());

        config('Search')->discoveryExpand = false;
        $this->assertStringNotContainsString('Also including', (string) $this->get('directory?q=cancer')->getBody());
    }

    // ------------------------------------------------------------ spelling

    public function testAMisspeltWordIsCorrectedFromThePlatformsOwnWords(): void
    {
        $model = new DiscoveryModelService();
        $model->learn();

        $this->assertSame('oncologist', $model->correct('oncoligist'));
        $this->assertSame('chemotherapy', $model->correct('chemotheraphy'));
        $this->assertNull($model->correct('cancer'), 'a known word is left alone');
        $this->assertNull($model->correct('cake'), 'short words are left alone');
        $this->assertNull($model->correct('zzzzzzzz'), 'nothing close: as typed');
    }

    public function testTheResultsPageRetriesASearchThatFoundNothing(): void
    {
        (new DiscoveryModelService())->learn();

        $body = (string) $this->get('directory?q=oncoligist')->getBody();
        $this->assertStringContainsString('Showing results for', $body);
        $this->assertStringContainsString('Onco a', $body);
        $this->assertStringContainsString('exact=1', $body, 'a way back to the words as typed');

        $this->assertStringNotContainsString('Showing results for', (string) $this->get('directory?q=oncoligist&exact=1')->getBody());
    }

    public function testTheDropdownOffersTheCorrectedSearch(): void
    {
        (new DiscoveryModelService())->learn();

        $items = json_decode($this->get('directory/suggest?q=oncoligist')->getJSON(), true)['items'];
        $this->assertSame('search', $items[0]['type'] ?? null);
        $this->assertSame('Did you mean "oncologist"?', $items[0]['label']);
    }

    // -------------------------------------------------- learning from people

    public function testASearchIsRecordedWithoutAnythingAboutWhoTypedIt(): void
    {
        $this->withHeaders(['User-Agent' => self::BROWSER])->get('directory?q=Cancer  Care');

        $rows = db_connect()->table('xs_directory_discovery_events')->get()->getResultArray();
        $this->assertCount(1, $rows);
        $this->assertSame('cancer care', $rows[0]['query']);
        $this->assertSame(3, (int) $rows[0]['results']);
        $this->assertSame(['id', 'site', 'query', 'listing_id', 'position', 'results', 'created_at'], array_keys($rows[0]), 'no IP, session or user column');
    }

    public function testCrawlersAreNotRecorded(): void
    {
        $bot = ['User-Agent' => 'Mozilla/5.0 (compatible; Googlebot/2.1)'];
        $this->withHeaders($bot)->get('directory?q=cancer');
        $this->withHeaders($bot)->post('discovery/opened', ['q' => 'cancer', 'slug' => 'onco-a', 'position' => '1']);

        $this->assertSame(0, db_connect()->table('xs_directory_discovery_events')->countAllResults());
    }

    public function testAnOpenedResultIsRecordedOnlyForAPublishedProfile(): void
    {
        $slug = (string) $this->listings->find($this->oncologists[0])['slug'];
        $this->listing('Hidden Onco', $this->oncology, 'Cancer.', ['status' => 'pending', 'slug' => 'hidden-onco']);

        $browser = ['User-Agent' => self::BROWSER];
        $this->withHeaders($browser)->post('discovery/opened', ['q' => 'cancer', 'slug' => $slug, 'position' => '2'])->assertStatus(204);
        $this->withHeaders($browser)->post('discovery/opened', ['q' => 'cancer', 'slug' => 'hidden-onco', 'position' => '1'])->assertStatus(204);
        $this->withHeaders($browser)->post('discovery/opened', ['q' => 'cancer', 'slug' => 'no-such-profile', 'position' => '1'])->assertStatus(204);

        $rows = db_connect()->table('xs_directory_discovery_events')->where('listing_id IS NOT NULL', null, false)->get()->getResultArray();
        $this->assertCount(1, $rows);
        $this->assertSame($this->oncologists[0], (int) $rows[0]['listing_id']);
        $this->assertSame(2, (int) $rows[0]['position']);
    }

    public function testOpenedResultsTeachAWordItsCategory(): void
    {
        $events = new DiscoveryEventService();
        foreach ($this->oncologists as $id) {
            $events->logClick('lump in breast', $id, 1);
        }
        // The same profile again the same day counts once.
        $events->logClick('lump in breast', $this->oncologists[0], 1);

        $model = new DiscoveryModelService();
        $model->learn();
        $this->assertSame(['Oncologist'], array_column($model->related('lump'), 'name'));
    }

    public function testFewerOpenedResultsThanTheBarTeachNothing(): void
    {
        $events = new DiscoveryEventService();
        $events->logClick('lump', $this->oncologists[0], 1);
        $events->logClick('lump', $this->oncologists[0], 1);

        $model = new DiscoveryModelService();
        $model->learn();
        $this->assertSame([], $model->related('lump'));
    }

    public function testOldEventsArePurged(): void
    {
        $db = db_connect();
        $db->table('xs_directory_discovery_events')->insert(['site' => 'za', 'query' => 'old', 'results' => 1, 'created_at' => date('Y-m-d H:i:s', strtotime('-181 days'))]);
        $db->table('xs_directory_discovery_events')->insert(['site' => 'za', 'query' => 'new', 'results' => 1, 'created_at' => date('Y-m-d H:i:s')]);

        $this->assertSame(1, (new DiscoveryEventService())->purge());
        $this->assertSame(['new'], array_column($db->table('xs_directory_discovery_events')->get()->getResultArray(), 'query'));
    }

    public function testSwitchedOffNothingIsRecorded(): void
    {
        config('Search')->discoveryLog = false;
        $this->withHeaders(['User-Agent' => self::BROWSER])->get('directory?q=cancer');

        $this->assertSame(0, db_connect()->table('xs_directory_discovery_events')->countAllResults());
    }

    // ---------------------------------------------------------------- admin

    public function testABlockedLinkIsGoneAndStaysGone(): void
    {
        $model = new DiscoveryModelService();
        $model->learn();
        $this->assertSame(['chemotherapy'], $model->expansions('cancer'));
        $this->assertSame(['Oncologist'], array_column($model->related('cancer'), 'name'));

        $this->assertTrue($model->block(DiscoveryModelService::KIND_RELATED, 'cancer', 'chemotherapy'));
        $this->assertTrue($model->block(DiscoveryModelService::KIND_CATEGORY, 'cancer', (string) $this->oncology));
        $this->assertSame([], $model->expansions('cancer'));

        $model->learn();
        $this->assertSame([], $model->expansions('cancer'), 'the nightly rebuild brings a blocked word back');
        $this->assertSame([], $model->related('cancer'));
        $this->assertSame(['Oncologist'], array_column($model->related('chemotherapy'), 'name'), 'a block is that one link only');
    }

    public function testTheAdminPageListsEmptySearchesAndBlocks(): void
    {
        (new DiscoveryModelService())->learn();
        (new DiscoveryEventService())->logSearch('gluten free bakery', 0);
        $session = ['dir_admin' => true, AdminFilter::SEEN_KEY => time()];

        $page = $this->withSession($session)->get('admin/discovery?term=cancer');
        $page->assertOK();
        $this->assertStringContainsString('gluten free bakery', (string) $page->getBody());
        $this->assertStringContainsString('chemotherapy', (string) $page->getBody());

        $this->withSession($session)->post('admin/discovery/block', [
            csrf_token() => csrf_hash(),
            'site'   => 'za',
            'kind'   => 'related',
            'term'   => 'cancer',
            'target' => 'chemotherapy',
        ])->assertRedirect();
        $this->assertSame(1, db_connect()->table('xs_directory_discovery_blocks')->countAllResults());
    }

    /** @param array<string,mixed> $extra */
    private function listing(string $name, int $categoryId, string $description, array $extra = []): int
    {
        return (int) $this->listings->insert($extra + [
            'type'             => 'practice',
            'display_name'     => $name,
            'slug'             => strtolower(str_replace(' ', '-', $name)),
            'email'            => 'layers-' . bin2hex(random_bytes(4)) . '@example.test',
            'category_id'      => $categoryId,
            'status'           => 'published',
            'is_verified'      => 1,
            'city'             => 'Pretoria',
            'province'         => 'Gauteng',
            'country'          => 'South Africa',
            'description_text' => $description,
        ], true);
    }
}

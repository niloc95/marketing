<?php

use App\Models\DirectoryCategoryModel;
use App\Models\DirectoryListingModel;
use App\Services\Search\DiscoveryModelService;
use App\Services\SiteContext;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;
use Tests\Support\FreshDatabase;
use Tests\Support\WithEnv;

/**
 * The discovery model: which categories a word is typical of, learned from
 * the profiles (DiscoveryModelService), and where it shows: the dropdown's
 * Category rows and the results page's "Related categories".
 *
 * What these pin: one stray profile never links a word to its category, a
 * word in a service name counts as much as one in the description, places
 * and filler are never learned, each site learns only from its own
 * profiles, and the model suggests without ever filtering.
 *
 * @internal
 */
final class DiscoveryModelTest extends CIUnitTestCase
{
    use FreshDatabase;
    use FeatureTestTrait;
    use WithEnv;

    private DirectoryListingModel $listings;
    private int $oncology;
    private int $skin;
    private int $plumbers;

    protected function setUp(): void
    {
        parent::setUp();
        cache()->clean();
        $this->listings = new DirectoryListingModel();
        $categories     = new DirectoryCategoryModel();
        $this->oncology = (int) $categories->insert(['name' => 'Oncologist', 'slug' => 'oncologist', 'group_name' => 'Health', 'is_active' => 1], true);
        $this->skin     = (int) $categories->insert(['name' => 'Dermatologist', 'slug' => 'dermatologist', 'group_name' => 'Health', 'is_active' => 1], true);
        $this->plumbers = (int) $categories->insert(['name' => 'Plumbers', 'slug' => 'plumbers', 'group_name' => 'Home & Trades', 'is_active' => 1], true);

        foreach (['a', 'b', 'c'] as $n) {
            $this->listing('Onco ' . $n, $this->oncology, 'We treat cancer in Pretoria with care.');
        }
        $this->listing('Skin One', $this->skin, 'Skin cancer checks and moles.');
        foreach (['a', 'b', 'c'] as $n) {
            $this->listing('Pipe ' . $n, $this->plumbers, 'Geyser repairs and burst pipes in Pretoria.');
        }
        // The rest of the platform: a word only counts as typical of a
        // category when it is rarer everywhere else.
        $cafes = (int) $categories->insert(['name' => 'Cafes', 'slug' => 'cafes', 'group_name' => 'Food', 'is_active' => 1], true);
        foreach (['a', 'b', 'c'] as $n) {
            $this->listing('Brew ' . $n, $cafes, 'Coffee and cake in Pretoria.');
        }
    }

    protected function tearDown(): void
    {
        $this->restoreEnv();
        Services::resetSingle('site');
        config('Search')->discoveryModel = true;
        cache()->clean();
        parent::tearDown();
    }

    public function testAWordLinksToTheCategoryWhoseProfilesUseIt(): void
    {
        $model = new DiscoveryModelService();
        $model->learn();

        $this->assertSame(['Oncologist'], array_column($model->related('cancer'), 'name'), 'one dermatologist is not enough to link cancer to skin');
        $this->assertSame(['Plumbers'], array_column($model->related('geysers'), 'name'), 'plurals fold');
        $this->assertSame([], $model->related('moles'), 'a word only one profile uses links nowhere');
    }

    public function testTheLastWordIsAPrefixAndTheOthersAreWhole(): void
    {
        $model = new DiscoveryModelService();
        $model->learn();

        $this->assertSame(['Oncologist'], array_column($model->related('canc'), 'name'));
        $this->assertSame(['Plumbers'], array_column($model->related('canc geys'), 'name'), 'only the last word is still being typed');
    }

    public function testServiceNamesCountAndPlacesDoNot(): void
    {
        $ids = $this->listings->where('category_id', $this->oncology)->findColumn('id');
        foreach (array_slice($ids, 0, 2) as $id) {
            db_connect()->table('xs_directory_listing_services')->insert(['listing_id' => $id, 'name' => 'Chemotherapy', 'sort_order' => 0]);
        }

        $model = new DiscoveryModelService();
        $model->learn();

        $this->assertSame(['Oncologist'], array_column($model->related('chemotherapy'), 'name'));
        $this->assertSame(0, db_connect()->table('xs_directory_discovery_terms')->where('term', 'pretoria')->countAllResults(), 'a town says where, not what');
    }

    public function testRelearningReplacesTheSitesRows(): void
    {
        $model = new DiscoveryModelService();
        $model->learn();
        $first = db_connect()->table('xs_directory_discovery_terms')->countAllResults();
        $model->learn();

        $this->assertGreaterThan(0, $first);
        $this->assertSame($first, db_connect()->table('xs_directory_discovery_terms')->countAllResults());
    }

    public function testEachSiteLearnsOnlyFromItsOwnProfiles(): void
    {
        $this->setEnv('sites.app.enabled', 'true');
        $bakers = (int) (new DirectoryCategoryModel())->insert(['name' => 'Bakeries', 'slug' => 'bakeries', 'group_name' => 'Food', 'is_active' => 1], true);
        foreach (['a', 'b'] as $n) {
            $this->listing('Loaf ' . $n, $bakers, 'Sourdough every morning.', ['country' => 'United Kingdom', 'city' => 'London']);
            $this->listing('Boiler ' . $n, $this->plumbers, 'Boiler servicing.', ['country' => 'United Kingdom', 'city' => 'London']);
        }

        Services::injectMock('site', new SiteContext('example.com'));
        (new DiscoveryModelService())->learn();
        $this->assertSame([], (new DiscoveryModelService())->related('sourdough'), 'co.za learned from a British profile');

        Services::injectMock('site', new SiteContext('local.webscheduler.app'));
        (new DiscoveryModelService())->learn();
        $this->assertSame(['Bakeries'], array_column((new DiscoveryModelService())->related('sourdough'), 'name'));
        $this->assertSame([], (new DiscoveryModelService())->related('cancer'), '.app learned from a South African profile');
    }

    public function testTheDropdownOffersTheLearnedCategory(): void
    {
        $ids = $this->listings->where('category_id', $this->oncology)->findColumn('id');
        foreach (array_slice($ids, 0, 2) as $id) {
            db_connect()->table('xs_directory_listing_services')->insert(['listing_id' => $id, 'name' => 'Chemotherapy', 'sort_order' => 0]);
        }
        (new DiscoveryModelService())->learn();

        // "Chemotherapy" is in no description, so only the model can say
        // which category it belongs to.
        $items      = json_decode($this->get('directory/suggest?q=chemotherapy')->getJSON(), true)['items'];
        $categories = array_values(array_filter($items, static fn ($i) => $i['type'] === 'category'));
        $this->assertSame('Oncologist', $categories[0]['label'] ?? null);
        $this->assertSame('2 related profiles', $categories[0]['sub']);
    }

    public function testTheResultsPageLinksRelatedCategoriesWithoutFilteringByThem(): void
    {
        (new DiscoveryModelService())->learn();

        $result = $this->get('directory?q=cancer');
        $result->assertOK();
        $body = (string) $result->getBody();
        $this->assertStringContainsString('Related categories', $body);
        $this->assertStringContainsString('/directory/oncologist', html_entity_decode($body));
        $this->assertStringContainsString('Skin One', $body, 'the dermatologist who says cancer is still a result');
    }

    public function testSwitchedOffTheResultsPageHasNoRelatedCategories(): void
    {
        (new DiscoveryModelService())->learn();
        config('Search')->discoveryModel = false;

        $this->assertStringNotContainsString('Related categories', (string) $this->get('directory?q=cancer')->getBody());
    }

    /** @param array<string,mixed> $extra */
    private function listing(string $name, int $categoryId, string $description, array $extra = []): int
    {
        return (int) $this->listings->insert($extra + [
            'type'             => 'practice',
            'display_name'     => $name,
            'slug'             => strtolower(str_replace(' ', '-', $name)) . '-' . bin2hex(random_bytes(2)),
            'email'            => 'model-' . bin2hex(random_bytes(4)) . '@example.test',
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

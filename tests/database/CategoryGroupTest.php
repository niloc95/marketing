<?php

use App\Database\Seeds\DirectoryCategoriesSeeder;
use App\Filters\AdminFilter;
use App\Libraries\ListingGeocoder;
use App\Models\DirectoryCategoryGroupModel;
use App\Models\DirectoryCategoryModel;
use App\Models\DirectoryListingModel;
use App\Services\DirectoryAdminService;
use App\Services\DirectoryService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * Main-category groups: the order they are shown in, the ?group= search
 * filter, and the seeder's regrouping (Hair into Beauty & Wellness, the new
 * Alternative & Traditional Medicine group, the hand-made healer row).
 *
 * The bug this exists for: groups used to sort alphabetically, so an
 * admin-added "Alternative…" group led every category picker.
 *
 * Requires the `tests` database group — see the Tests section of README.md.
 *
 * @internal
 */
final class CategoryGroupTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $namespace   = 'App';
    protected $migrate     = true;
    protected $migrateOnce = false;
    protected $refresh     = true;

    private DirectoryCategoryModel $categories;
    private DirectoryCategoryGroupModel $groups;
    private DirectoryListingModel $listings;

    protected function setUp(): void
    {
        parent::setUp();
        $this->categories = new DirectoryCategoryModel();
        $this->groups     = new DirectoryCategoryGroupModel();
        $this->listings   = new DirectoryListingModel();
    }

    // ---------------------------------------------------------------- order

    public function testAlternativeSortsLastAndAnUnknownGroupJustBeforeIt(): void
    {
        $this->category('Traditional Healer', 'Alternative & Traditional Medicine');
        $this->category('Spa', 'Beauty & Wellness');
        $this->category('Widget Polisher', 'Widgets');
        $this->category('Plumber', 'Home & Trades');

        $this->assertSame(
            ['Beauty & Wellness', 'Home & Trades', 'Widgets', 'Alternative & Traditional Medicine'],
            $this->groupOrder((new DirectoryService())->categories()),
        );
    }

    public function testAGroupWithNoRowStillShowsAfterTheKnownOnes(): void
    {
        $this->category('Spa', 'Beauty & Wellness');
        // Straight in, bypassing ensure(): a row typed in before the groups
        // table existed and never backfilled.
        $this->categories->insert(['name' => 'Orphan', 'slug' => 'orphan', 'group_name' => 'Aardvarks', 'is_active' => 1]);

        $this->assertSame(['Beauty & Wellness', 'Aardvarks'], $this->groupOrder((new DirectoryService())->categories()));
    }

    public function testTheAdminPositionWins(): void
    {
        $this->category('Traditional Healer', 'Alternative & Traditional Medicine');
        $this->category('Spa', 'Beauty & Wellness');
        $alt = $this->groups->where('name', 'Alternative & Traditional Medicine')->first();

        $result = (new DirectoryAdminService())->saveCategoryGroup((int) $alt['id'], ['sort_order' => '1']);

        $this->assertTrue($result['ok'], $result['message']);
        $this->assertSame(
            ['Alternative & Traditional Medicine', 'Beauty & Wellness'],
            $this->groupOrder((new DirectoryService())->categories()),
        );
    }

    public function testAPositionMustBeAWholeNumber(): void
    {
        $this->category('Spa', 'Beauty & Wellness');
        $group = $this->groups->where('name', 'Beauty & Wellness')->first();

        $result = (new DirectoryAdminService())->saveCategoryGroup((int) $group['id'], ['sort_order' => 'first']);

        $this->assertFalse($result['ok']);
        $this->assertSame(DirectoryCategoryGroupModel::defaultSortFor('Beauty & Wellness'), (int) $this->groups->find($group['id'])['sort_order']);
    }

    public function testSavingACategoryUnderANewGroupCreatesItsRow(): void
    {
        $result = (new DirectoryAdminService())->saveCategory(null, [
            'name' => 'Candle Maker', 'group_name' => 'Candles & Soap', 'is_active' => 1,
        ]);

        $this->assertTrue($result['ok'], $result['message']);
        $row = $this->groups->where('name', 'Candles & Soap')->first();
        $this->assertNotNull($row);
        $this->assertSame('candles-soap', $row['slug']);
        $this->assertLessThan(DirectoryCategoryGroupModel::defaultSortFor('Alternative & Traditional Medicine'), (int) $row['sort_order']);
    }

    public function testTheAdminPageListsTheGroupsInOrder(): void
    {
        $this->category('Traditional Healer', 'Alternative & Traditional Medicine');
        $this->category('Spa', 'Beauty & Wellness');

        $html = $this->withSession(['dir_admin' => true, AdminFilter::SEEN_KEY => time()])
            ->get('admin/categories')->getBody();

        $this->assertStringContainsString('Main category order', $html);
        $this->assertLessThan(
            strpos($html, 'Alternative &amp; Traditional Medicine'),
            strpos($html, 'Beauty &amp; Wellness'),
        );
    }

    // --------------------------------------------------------------- search

    public function testAGroupFilterSearchesEveryCategoryInIt(): void
    {
        $spa    = $this->category('Spa', 'Beauty & Wellness');
        $barber = $this->category('Barber', 'Beauty & Wellness');
        $plumb  = $this->category('Plumber', 'Home & Trades');
        $this->listing('Calm Spa', $spa);
        $this->listing('Sharp Cuts', $barber);
        $this->listing('Pipe Pros', $plumb);

        $svc = new DirectoryService();

        $this->assertSame(['Calm Spa', 'Sharp Cuts'], $this->names($svc->browse(['group' => 'beauty-wellness'])));
        $this->assertSame(['Sharp Cuts'], $this->names($svc->browse(['group' => 'beauty-wellness', 'category' => 'barber'])));
        // Same filter on the map, which builds its own query.
        $this->assertCount(2, $svc->mapPoints(['group' => 'beauty-wellness']));
    }

    public function testAnUnknownGroupIsIgnoredRatherThanEmptyingTheSearch(): void
    {
        $spa = $this->category('Spa', 'Beauty & Wellness');
        $this->listing('Calm Spa', $spa);

        $this->assertSame(['Calm Spa'], $this->names((new DirectoryService())->browse(['group' => 'no-such-group'])));
    }

    public function testAGroupResultsPageIsTitledAndKeptOutOfTheIndex(): void
    {
        $spa = $this->category('Spa', 'Beauty & Wellness');
        $this->listing('Calm Spa', $spa);

        $result = $this->get('directory?group=beauty-wellness');
        $result->assertOK();
        $html = $result->getBody();

        $this->assertStringContainsString('Calm Spa', $html);
        $this->assertMatchesRegularExpression('/<title>Beauty &amp; Wellness &mdash; /', $html);
        $this->assertMatchesRegularExpression('/<meta name="robots" content="noindex/', $html);
        $this->assertStringContainsString('data-selected-group="beauty-wellness"', $html);
    }

    public function testTheSignupPickerCarriesGroupSlugs(): void
    {
        $this->category('Spa', 'Beauty & Wellness');
        $this->category('Traditional Healer', 'Alternative & Traditional Medicine');

        $html = $this->get('add-listing')->getBody();

        $this->assertStringContainsString('data-category-picker', $html);
        $this->assertStringContainsString('data-slug="beauty-wellness"', $html);
        $this->assertLessThan(
            strpos($html, 'data-slug="alternative-traditional-medicine"'),
            strpos($html, 'data-slug="beauty-wellness"'),
        );
    }

    // --------------------------------------------------------------- seeder

    public function testTheSeederFoldsHairAndFilesAlternativeLast(): void
    {
        $this->seed(DirectoryCategoriesSeeder::class);

        $this->assertSame('Beauty & Wellness', $this->groupOf('hair-salon'));
        $this->assertSame('Beauty & Wellness', $this->groupOf('barber'));
        $this->assertSame('Alternative & Traditional Medicine', $this->groupOf('homeopath'));
        $this->assertNull($this->groups->where('name', 'Hair')->first());

        $order = array_column($this->groups->ordered(), 'name');
        $this->assertSame('Beauty & Wellness', $order[0]);
        $this->assertSame('Alternative & Traditional Medicine', end($order));
    }

    public function testTheSeederAdoptsTheHandMadeHealerRow(): void
    {
        // As it was made on production from /admin/categories.
        $id = (int) $this->categories->insert([
            'name' => 'Alternative Medicine Traditional Healer', 'slug' => 'alternative-medicine-traditional-healer',
            'group_name' => 'Alternative Medicine', 'is_active' => 1,
        ], true);
        $this->groups->ensure('Alternative Medicine');
        $listing = $this->listing('Gogo Healing', $id);

        $this->seed(DirectoryCategoriesSeeder::class);

        $row = $this->categories->find($id);
        $this->assertSame('Traditional Healer', $row['name']);
        $this->assertSame('alternative-medicine-traditional-healer', $row['slug'], 'the slug, and every link to it, is kept');
        $this->assertSame('Alternative & Traditional Medicine', $row['group_name']);
        $this->assertNull($this->categories->where('slug', 'traditional-healer')->first(), 'no second healer row');
        $this->assertSame($id, (int) $this->listings->find($listing)['category_id']);
        $this->assertNull($this->groups->where('name', 'Alternative Medicine')->first(), 'the emptied group is gone');
    }

    public function testReseedingKeepsTheAdminOrder(): void
    {
        $this->seed(DirectoryCategoriesSeeder::class);
        $alt = $this->groups->where('name', 'Alternative & Traditional Medicine')->first();
        $this->groups->update($alt['id'], ['sort_order' => 1]);

        $this->seed(DirectoryCategoriesSeeder::class);

        $this->assertSame('Alternative & Traditional Medicine', $this->groups->ordered()[0]['name']);
    }

    // -------------------------------------------------------------- helpers

    private function category(string $name, string $group): int
    {
        helper('slug');
        $this->groups->ensure($group);

        return (int) $this->categories->insert([
            'name' => $name, 'slug' => slugify($name), 'group_name' => $group, 'is_active' => 1,
        ], true);
    }

    private function listing(string $name, int $categoryId): int
    {
        helper('slug');

        $id = (int) $this->listings->insert([
            'type'         => 'practice',
            'display_name' => $name,
            'email'        => 'group-' . bin2hex(random_bytes(4)) . '@example.test',
            'category_id'  => $categoryId,
            'slug'         => slugify($name),
            'status'       => 'published',
            'is_verified'  => 1,
            'city'         => 'Cape Town',
            'province'     => 'Western Cape',
            'latitude'     => -33.92,
            'longitude'    => 18.42,
        ], true);
        // The map reads pins from the spatial table, which the save path fills.
        (new ListingGeocoder())->syncPoint($id, ['latitude' => -33.92, 'longitude' => 18.42]);

        return $id;
    }

    /** @return list<string> group names in first-seen order */
    private function groupOrder(array $categories): array
    {
        return array_values(array_unique(array_column($categories, 'group_name')));
    }

    /** @return list<string> sorted listing names */
    private function names(array $result): array
    {
        $names = array_column($result['items'], 'display_name');
        sort($names);

        return $names;
    }

    private function groupOf(string $slug): ?string
    {
        return $this->categories->where('slug', $slug)->first()['group_name'] ?? null;
    }
}

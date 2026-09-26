<?php

use App\Models\DirectoryCategoryModel;
use App\Models\DirectoryListingFacetModel;
use App\Models\DirectoryListingModel;
use App\Services\DirectoryService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * Restaurants & Food — the first group whose facets come from the GROUP tier of
 * Config\ListingFacets rather than a per-slug entry, and the "Newest" sort that
 * stands in for Yelp's "New Restaurants".
 *
 * Three things pinned:
 *
 * 1. **A group-tier facet filters.** Every facet before this one was reached
 *    through $byCategory, so sanitiseFacets() resolving the group was never
 *    exercised. A pizza category with no entry of its own must still be
 *    filterable on takeaway and delivery.
 * 2. **sort=new orders by publication, newest first**, ahead of quality — and
 *    without it the editorial order is untouched.
 * 3. **A sorted landing page is noindexed** and carries the toggle, and a sort
 *    value that is not on the whitelist is ignored rather than echoed.
 *
 * Requires the `tests` database group — see the Tests section of README.md.
 *
 * @internal
 */
final class RestaurantsFoodTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $namespace   = 'App';
    protected $migrate     = true;
    protected $migrateOnce = false;
    protected $refresh     = true;

    private DirectoryListingModel $listings;
    private int $pizzaId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->listings = new DirectoryListingModel();

        // The real slug and group: the facets hang off 'Restaurants & Food'.
        $this->pizzaId = (int) (new DirectoryCategoryModel())->insert([
            'name' => 'Pizza', 'slug' => 'pizza', 'group_name' => 'Restaurants & Food', 'is_active' => 1,
        ], true);
    }

    public function testAGroupTierFacetFiltersACategoryWithNoEntryOfItsOwn(): void
    {
        $a = $this->listing(['display_name' => 'Roman Slice', 'slug' => 'roman-slice']);
        $this->facet($a, 'service_options', 'delivery');
        $this->facet($a, 'service_options', 'takeaway');
        $b = $this->listing(['display_name' => 'Sit Down Pizza', 'slug' => 'sit-down-pizza']);
        $this->facet($b, 'service_options', 'dine-in');

        $svc    = new DirectoryService();
        $facets = $svc->sanitiseFacets(['service_options' => ['delivery'], 'curriculum' => ['ieb']], 'pizza');

        // curriculum is a school facet and must not survive onto a pizzeria.
        $this->assertSame(['service_options' => ['delivery']], $facets);

        $result = $svc->browse(['category' => 'pizza', 'facets' => $facets]);
        $this->assertSame(1, $result['total']);
        $this->assertSame('roman-slice', $result['items'][0]['slug']);
    }

    public function testSortNewPutsTheMostRecentlyPublishedFirst(): void
    {
        // The older listing has the richer profile, so the default order and
        // the newest-first order genuinely disagree.
        $this->listing(['slug' => 'old-favourite', 'published_at' => '2026-01-10 09:00:00', 'quality_score' => 90]);
        $this->listing(['slug' => 'just-opened', 'published_at' => '2026-09-20 09:00:00', 'quality_score' => 10]);

        $svc = new DirectoryService();

        $default = $svc->browse(['category' => 'pizza']);
        $this->assertSame('old-favourite', $default['items'][0]['slug'], 'the default order is still quality first');

        $newest = $svc->browse(['category' => 'pizza', 'sort' => 'new']);
        $this->assertSame('just-opened', $newest['items'][0]['slug']);
    }

    public function testASortedLandingPageIsNoindexedAndOffersTheToggle(): void
    {
        $this->listing(['slug' => 'pizza-one', 'published_at' => '2026-03-01 09:00:00']);
        $this->listing(['slug' => 'pizza-two', 'published_at' => '2026-04-01 09:00:00']);

        $page = $this->get('directory/pizza?sort=new');
        $page->assertOK();

        $html = (string) $page->response()->getBody();
        $this->assertStringContainsString('noindex', $html);
        $this->assertStringContainsString('Newest', $html);
        $this->assertStringContainsString('cat-tint-rose', $html, 'Restaurants & Food is rose');
    }

    public function testAnUnknownSortValueIsIgnored(): void
    {
        $this->listing(['slug' => 'pizza-one']);
        $this->listing(['slug' => 'pizza-two']);

        // Anything but 'new' is the default order, and the toggle shows
        // "Best match" as the current choice rather than echoing the input.
        $html = (string) $this->get('directory/pizza?sort=oldest')->response()->getBody();
        $this->assertStringNotContainsString('sort=oldest', $html);
        $this->assertMatchesRegularExpression('/near-chip is-active"[^>]*>Best match</', $html);
    }

    private function listing(array $overrides): int
    {
        return (int) $this->listings->insert($overrides + [
            'type'         => 'facility',
            'display_name' => 'Test Pizzeria',
            'email'        => 'food-' . bin2hex(random_bytes(4)) . '@example.test',
            'category_id'  => $this->pizzaId,
            'slug'         => 'test-pizzeria-' . bin2hex(random_bytes(3)),
            'status'       => 'published',
            'is_verified'  => 1,
            'city'         => 'Johannesburg',
            'province'     => 'Gauteng',
        ], true);
    }

    private function facet(int $listingId, string $key, string $value): void
    {
        (new DirectoryListingFacetModel())->insert([
            'listing_id' => $listingId,
            'facet_key'  => $key,
            'value'      => $value,
            'num_low'    => null,
            'num_high'   => null,
        ]);
    }
}

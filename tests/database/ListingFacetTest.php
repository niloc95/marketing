<?php

use App\Models\DirectoryCategoryModel;
use App\Models\DirectoryListingFacetModel;
use App\Models\DirectoryListingModel;
use App\Services\DirectoryService;
use App\Services\ListingFacetService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * Facets — the structured facts a listing states, and the filters built on them.
 *
 * Four things are pinned here, each quiet when broken:
 *
 * 1. **A facet filter narrows.** browse() builds one OR group for the search
 *    term, and a filter added with orWhere() escapes it — "IEB schools matching
 *    'montessori'" would then list every business in the country while looking
 *    perfectly normal. Same trap VenueTest pins for the venue filter.
 * 2. **The category decides what may be stored.** Switching a listing from a
 *    school to a salon must drop the school's values on the next save, and a
 *    crafted POST must not invent a facet the category never offered.
 * 3. **Ranges match by overlap**, so a preschool taking 18 to 72 months answers
 *    a search for a three-year-old, and a one-sided 'fees from' is not failed by
 *    its own missing upper bound.
 * 4. **Sanitising happens before the query is built.** sanitiseFacets() runs a
 *    query of its own, and CodeIgniter keeps its table-alias registry on the
 *    connection: doing it mid-build makes the *category* filter fail with
 *    "Unknown column 'xs_p.slug'". The test for it is an ordinary faceted
 *    browse, which is the point — every faceted search hit it.
 *
 * Requires the `tests` database group — see the Tests section of README.md.
 *
 * @internal
 */
final class ListingFacetTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $namespace   = 'App';
    protected $migrate     = true;
    protected $migrateOnce = false;
    protected $refresh     = true;

    private DirectoryListingModel $listings;
    private DirectoryListingFacetModel $facets;
    private int $preschoolId;
    private int $highSchoolId;
    private int $salonId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->listings = new DirectoryListingModel();
        $this->facets   = new DirectoryListingFacetModel();

        // Real slugs and groups: Config\ListingFacets is keyed by them, so a
        // made-up slug would offer no facets and pass everything vacuously.
        $categories         = new DirectoryCategoryModel();
        $this->preschoolId  = (int) $categories->insert(['name' => 'Preschool & Daycare', 'slug' => 'preschool-daycare', 'group_name' => 'Education & Training', 'is_active' => 1], true);
        $this->highSchoolId = (int) $categories->insert(['name' => 'High School', 'slug' => 'high-school', 'group_name' => 'Education & Training', 'is_active' => 1], true);
        $this->salonId      = (int) $categories->insert(['name' => 'Hair Salon', 'slug' => 'hair-salon', 'group_name' => 'Hair', 'is_active' => 1], true);
    }

    // ------------------------------------------------------------- the filter

    public function testAFacetFilterNarrowsASearchRatherThanWideningIt(): void
    {
        // The orWhere trap. 'montessori' is in one listing's name, so the search
        // term alone matches it; adding a curriculum it does not have must take
        // the count to zero, not to everything on the site.
        $a = $this->listing(['display_name' => 'Little Montessori', 'slug' => 'little-montessori']);
        $this->facet($a, 'curriculum', 'montessori');
        $b = $this->listing(['display_name' => 'Bright Start', 'slug' => 'bright-start']);
        $this->facet($b, 'curriculum', 'caps');
        $this->listing(['display_name' => 'Curls Salon', 'slug' => 'curls-salon', 'category_id' => $this->salonId]);

        $svc  = new DirectoryService();
        $base = $svc->browse(['category' => 'preschool-daycare', 'q' => 'montessori']);
        $this->assertSame(1, $base['total']);

        $narrowed = $svc->browse([
            'category' => 'preschool-daycare',
            'q'        => 'montessori',
            'facets'   => ['curriculum' => ['caps']],
        ]);

        $this->assertSame(0, $narrowed['total'], 'the facet widened the search instead of narrowing it');
    }

    public function testAFacetedBrowseKeepsItsCategoryFilter(): void
    {
        // Regression: sanitiseFacets() resolves the category with a query, and a
        // query completed while the browse builder is half-built clears the
        // connection's alias registry, so `p`.`slug` becomes `xs_p`.`slug`.
        $a = $this->listing(['display_name' => 'Ridge Prep', 'slug' => 'ridge-prep', 'category_id' => $this->highSchoolId]);
        $this->facet($a, 'curriculum', 'ieb');
        $b = $this->listing(['display_name' => 'Tiny Tots', 'slug' => 'tiny-tots']);
        $this->facet($b, 'curriculum', 'caps');

        $result = (new DirectoryService())->browse([
            'category' => 'high-school',
            'facets'   => ['curriculum' => ['ieb']],
        ]);

        $this->assertSame(1, $result['total']);
        $this->assertSame('ridge-prep', $result['items'][0]['slug']);
    }

    public function testValuesWithinOneFacetAreOredAndFacetsAreAnded(): void
    {
        $a = $this->listing(['slug' => 'ieb-boarding', 'category_id' => $this->highSchoolId]);
        $this->facet($a, 'curriculum', 'ieb');
        $this->facet($a, 'boarding', 'day-boarding');

        $b = $this->listing(['slug' => 'caps-day', 'category_id' => $this->highSchoolId]);
        $this->facet($b, 'curriculum', 'caps');
        $this->facet($b, 'boarding', 'day-only');

        $svc = new DirectoryService();

        $either = $svc->browse(['category' => 'high-school', 'facets' => ['curriculum' => ['ieb', 'caps']]]);
        $this->assertSame(2, $either['total'], 'two values in one facet must be an OR');

        $both = $svc->browse(['category' => 'high-school', 'facets' => [
            'curriculum' => ['ieb'],
            'boarding'   => ['day-only'],
        ]]);
        $this->assertSame(0, $both['total'], 'two different facets must be an AND');
    }

    public function testARangeMatchesByOverlap(): void
    {
        $toddlers = $this->listing(['slug' => 'toddler-house']);
        $this->range($toddlers, 'ages', 18, 72);
        $olders = $this->listing(['slug' => 'big-kids']);
        $this->range($olders, 'ages', 60, 144);

        $svc = new DirectoryService();

        $this->assertSame(1, $svc->browse(['category' => 'preschool-daycare', 'facets' => ['ages' => 24]])['total']);
        $this->assertSame(2, $svc->browse(['category' => 'preschool-daycare', 'facets' => ['ages' => 66]])['total']);
        $this->assertSame(0, $svc->browse(['category' => 'preschool-daycare', 'facets' => ['ages' => 200]])['total']);
    }

    public function testAOneSidedRangeIsNotFailedByItsMissingUpperBound(): void
    {
        $cheap = $this->listing(['slug' => 'cheap-school', 'category_id' => $this->highSchoolId]);
        $this->range($cheap, 'fees_from', 2000, null);
        $dear = $this->listing(['slug' => 'dear-school', 'category_id' => $this->highSchoolId]);
        $this->range($dear, 'fees_from', 9000, null);

        $result = (new DirectoryService())->browse(['category' => 'high-school', 'facets' => ['fees_from' => 5000]]);

        $this->assertSame(1, $result['total']);
        $this->assertSame('cheap-school', $result['items'][0]['slug']);
    }

    public function testFacetsAreIgnoredWithoutACategoryToScopeThem(): void
    {
        // "IEB" is not a question you can ask of every business in the country,
        // and silently applying it to an unscoped browse would hide everything
        // that has no facets at all — which is most of the site.
        $a = $this->listing(['slug' => 'some-school', 'category_id' => $this->highSchoolId]);
        $this->facet($a, 'curriculum', 'ieb');
        $this->listing(['slug' => 'some-salon', 'category_id' => $this->salonId]);

        $result = (new DirectoryService())->browse(['facets' => ['curriculum' => ['ieb']]]);

        $this->assertSame(2, $result['total']);
    }

    // ---------------------------------------------------------- sanitisation

    public function testSanitisingDropsUnknownFacetsAndUnknownValues(): void
    {
        $svc = new DirectoryService();

        $this->assertSame([], $svc->sanitiseFacets(['no_such_facet' => ['x']], 'high-school'));
        $this->assertSame([], $svc->sanitiseFacets(['curriculum' => ['not-a-curriculum']], 'high-school'));
        $this->assertSame(['curriculum' => ['ieb']], $svc->sanitiseFacets(['curriculum' => ['ieb', 'nope']], 'high-school'));

        // A facet the category does not offer, even though another one does.
        $this->assertSame([], $svc->sanitiseFacets(['boarding' => ['day-only']], 'preschool-daycare'));
        // No facets at all for this category.
        $this->assertSame([], $svc->sanitiseFacets(['curriculum' => ['ieb']], 'hair-salon'));
        // An unknown category cannot offer anything.
        $this->assertSame([], $svc->sanitiseFacets(['curriculum' => ['ieb']], 'no-such-category'));
    }

    public function testSanitisingClampsARangeToTheFacetsBounds(): void
    {
        $svc = new DirectoryService();

        $this->assertSame(['ages' => 36], $svc->sanitiseFacets(['ages' => '36'], 'high-school'));
        $this->assertSame(['ages' => 252], $svc->sanitiseFacets(['ages' => '99999'], 'high-school'));
        $this->assertSame(['ages' => 0], $svc->sanitiseFacets(['ages' => '-500'], 'high-school'));
        $this->assertSame([], $svc->sanitiseFacets(['ages' => 'drop table'], 'high-school'));
    }

    // ----------------------------------------------------------- the write path

    public function testSavingKeepsOnlyWhatTheChosenCategoryOffers(): void
    {
        $id  = $this->listing(['slug' => 'saver', 'category_id' => $this->highSchoolId]);
        $svc = new ListingFacetService();

        $svc->sync($id, $this->highSchoolId, [
            ListingFacetService::FACETS_MARKER => '1',
            'facets'                           => [
                'curriculum' => ['ieb', 'not-real'],
                'boarding'   => 'day-only',
                'ages'       => ['low' => '156', 'high' => '216'],
                'programme'  => ['full-day'],   // preschool only
                'invented'   => ['anything'],
            ],
        ]);

        $stored = $svc->storedFor($id);

        $this->assertSame(['ieb'], array_column($stored['curriculum'], 'value'));
        $this->assertSame(['day-only'], array_column($stored['boarding'], 'value'));
        $this->assertSame(156, $stored['ages'][0]['num_low']);
        $this->assertSame(216, $stored['ages'][0]['num_high']);
        $this->assertArrayNotHasKey('programme', $stored, 'a facet from another category was stored');
        $this->assertArrayNotHasKey('invented', $stored);
    }

    public function testSwitchingCategoryDropsTheOldCategorysValues(): void
    {
        $id  = $this->listing(['slug' => 'switcher', 'category_id' => $this->highSchoolId]);
        $svc = new ListingFacetService();

        $input = [
            ListingFacetService::FACETS_MARKER => '1',
            'facets'                           => ['curriculum' => ['ieb'], 'boarding' => 'day-only'],
        ];
        $svc->sync($id, $this->highSchoolId, $input);
        $this->assertNotSame([], $svc->storedFor($id));

        // The form hides the old fieldset but its boxes may still be ticked;
        // the server is what has to drop them.
        $svc->sync($id, $this->salonId, $input);

        $this->assertSame([], $svc->storedFor($id));
    }

    public function testAnAbsentMarkerLeavesStoredFacetsAloneAndAnEmptyOneClearsThem(): void
    {
        $id  = $this->listing(['slug' => 'marker', 'category_id' => $this->highSchoolId]);
        $svc = new ListingFacetService();

        $svc->sync($id, $this->highSchoolId, [
            ListingFacetService::FACETS_MARKER => '1',
            'facets'                           => ['curriculum' => ['ieb']],
        ]);

        // A form that did not carry the section at all.
        $svc->sync($id, $this->highSchoolId, ['display_name' => 'Renamed']);
        $this->assertArrayHasKey('curriculum', $svc->storedFor($id), 'an absent marker must not clear the section');

        // The section was on the form and everything was cleared.
        $svc->sync($id, $this->highSchoolId, [ListingFacetService::FACETS_MARKER => '1']);
        $this->assertSame([], $svc->storedFor($id), 'a present marker with nothing submitted must clear the section');
    }

    public function testASingleChoiceFacetStoresOnlyOneValue(): void
    {
        $id  = $this->listing(['slug' => 'radio', 'category_id' => $this->highSchoolId]);
        $svc = new ListingFacetService();

        // A radio group cannot submit two, but a crafted POST can.
        $svc->sync($id, $this->highSchoolId, [
            ListingFacetService::FACETS_MARKER => '1',
            'facets'                           => ['boarding' => ['day-only', 'boarding-only']],
        ]);

        $this->assertCount(1, $svc->storedFor($id)['boarding']);
    }

    public function testAnInvertedRangeIsStoredTheRightWayRound(): void
    {
        $id  = $this->listing(['slug' => 'inverted', 'category_id' => $this->highSchoolId]);
        $svc = new ListingFacetService();

        $svc->sync($id, $this->highSchoolId, [
            ListingFacetService::FACETS_MARKER => '1',
            'facets'                           => ['ages' => ['low' => '216', 'high' => '156']],
        ]);

        $stored = $svc->storedFor($id)['ages'][0];
        $this->assertSame(156, $stored['num_low']);
        $this->assertSame(216, $stored['num_high'], 'an inverted range would have matched nothing at all');
    }

    public function testValidateRejectsAnInvertedRangeOnTheForm(): void
    {
        $errors = (new ListingFacetService())->validate([
            ListingFacetService::FACETS_MARKER => '1',
            'facets'                           => ['ages' => ['low' => '216', 'high' => '156']],
        ], $this->highSchoolId);

        $this->assertArrayHasKey('facets.ages', $errors);
    }

    public function testDeletingAListingTakesItsFacetsWithIt(): void
    {
        $id = $this->listing(['slug' => 'doomed', 'category_id' => $this->highSchoolId]);
        $this->facet($id, 'curriculum', 'ieb');

        $this->listings->delete($id, true);

        $this->assertSame([], $this->facets->forListing($id));
    }

    // ------------------------------------------------------------- the card line

    public function testBrowseAttachesFacetsToItsResultsForTheCardLine(): void
    {
        $id = $this->listing(['slug' => 'carded']);
        $this->range($id, 'ages', 18, 72);
        $this->facet($id, 'curriculum', 'montessori');

        $result = (new DirectoryService())->browse(['category' => 'preschool-daycare']);

        helper('directory_ui');
        $line = listing_card_facets(
            $result['items'][0]['facets'] ?? [],
            'Education & Training',
            'preschool-daycare'
        );

        $this->assertNotEmpty($line);
        $this->assertContains('Ages 18 months – 6 years', $line);
        $this->assertContains('Montessori', $line);
    }

    // ---------------------------------------------------------------- helpers

    private function listing(array $overrides): int
    {
        return (int) $this->listings->insert($overrides + [
            'type'         => 'facility',
            'display_name' => 'Test School',
            'email'        => 'facet-' . bin2hex(random_bytes(4)) . '@example.test',
            'category_id'  => $this->preschoolId,
            'slug'         => 'test-school-' . bin2hex(random_bytes(3)),
            'status'       => 'published',
            'is_verified'  => 1,
            'city'         => 'Johannesburg',
            'province'     => 'Gauteng',
        ], true);
    }

    private function facet(int $listingId, string $key, string $value): void
    {
        $this->facets->insert([
            'listing_id' => $listingId,
            'facet_key'  => $key,
            'value'      => $value,
            'num_low'    => null,
            'num_high'   => null,
        ]);
    }

    private function range(int $listingId, string $key, ?int $low, ?int $high): void
    {
        $this->facets->insert([
            'listing_id' => $listingId,
            'facet_key'  => $key,
            'value'      => '',
            'num_low'    => $low,
            'num_high'   => $high,
        ]);
    }
}

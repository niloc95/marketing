<?php

use App\Models\DirectoryCategoryModel;
use App\Models\DirectoryListingModel;
use App\Models\DirectoryVenueModel;
use App\Services\DirectoryAdminService;
use App\Services\DirectoryListingMutationService;
use App\Services\DirectoryService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * Venues — the complex, mall or building a set of listings shares.
 *
 * The three things worth pinning, each of which is quiet when broken:
 *
 * 1. The filter narrows. browse() builds one OR group for the search term, and
 *    a filter added with orWhere() escapes it — a venue page would then list
 *    every business in the country while looking perfectly normal.
 * 2. An owner cannot set it. This is the regression the tag-based version would
 *    have had: an owner save replaces their whole tag set, so a venue kept there
 *    would vanish on any profile edit. Here the owner path must not touch it at
 *    all, in either direction.
 * 3. Deleting a venue ungroups its businesses and deletes none of them — the FK
 *    is ON DELETE SET NULL, and nothing else may be relied on to hold that.
 *
 * Requires the `tests` database group — see the Tests section of README.md.
 *
 * @internal
 */
final class VenueTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $namespace   = 'App';
    protected $migrate     = true;
    protected $migrateOnce = false;
    protected $refresh     = true;

    private DirectoryListingModel $listings;
    private DirectoryVenueModel $venues;
    private int $categoryId;
    private int $otherCategoryId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->listings = new DirectoryListingModel();
        $this->venues   = new DirectoryVenueModel();

        $categories            = new DirectoryCategoryModel();
        $this->categoryId      = (int) $categories->insert(['name' => 'Tailors', 'slug' => 'tailors', 'group_name' => 'Retail', 'is_active' => 1], true);
        $this->otherCategoryId = (int) $categories->insert(['name' => 'Jewellers', 'slug' => 'jewellers', 'group_name' => 'Retail', 'is_active' => 1], true);
    }

    // ------------------------------------------------------------- the filter

    public function testBrowseByVenueReturnsOnlyThatVenuesPublishedListings(): void
    {
        $venue = $this->venue();
        $this->listing(['display_name' => 'Inside One', 'slug' => 'inside-one', 'venue_id' => $venue]);
        $this->listing(['display_name' => 'Inside Two', 'slug' => 'inside-two', 'venue_id' => $venue]);
        $this->listing(['display_name' => 'Down The Road', 'slug' => 'down-the-road']);
        $this->listing(['display_name' => 'Not Live Yet', 'slug' => 'not-live-yet', 'venue_id' => $venue, 'status' => 'pending']);

        $result = (new DirectoryService())->browse(['venue' => $venue]);

        $this->assertSame(2, $result['total']);
        $names = array_column($result['items'], 'display_name');
        sort($names);
        $this->assertSame(['Inside One', 'Inside Two'], $names);
    }

    public function testTheVenueFilterNarrowsASearchRatherThanWideningIt(): void
    {
        $venue = $this->venue();
        $this->listing(['display_name' => 'Plaza Tailors', 'slug' => 'plaza-tailors', 'venue_id' => $venue]);
        $this->listing(['display_name' => 'Plaza Tailors Elsewhere', 'slug' => 'plaza-tailors-elsewhere']);

        $result = (new DirectoryService())->browse(['venue' => $venue, 'q' => 'Plaza']);

        $this->assertSame(1, $result['total'], 'a search inside a venue must not escape it');
        $this->assertSame('Plaza Tailors', $result['items'][0]['display_name']);
    }

    public function testACategoryFilterCombinesWithTheVenue(): void
    {
        $venue = $this->venue();
        $this->listing(['display_name' => 'Venue Tailor', 'slug' => 'venue-tailor', 'venue_id' => $venue]);
        $this->listing(['display_name' => 'Venue Jeweller', 'slug' => 'venue-jeweller', 'venue_id' => $venue, 'category_id' => $this->otherCategoryId]);

        $result = (new DirectoryService())->browse(['venue' => $venue, 'category' => 'jewellers']);

        $this->assertSame(1, $result['total']);
        $this->assertSame('Venue Jeweller', $result['items'][0]['display_name']);
    }

    // ---------------------------------------------------------------- the page

    public function testTheVenuePageListsItsBusinessesAndIsIndexableOnceItHasEnough(): void
    {
        $venue = $this->venue();
        for ($i = 0; $i < 3; $i++) {
            $this->listing(['display_name' => 'Shop Number ' . $i, 'slug' => 'shop-number-' . $i, 'venue_id' => $venue]);
        }

        $page = $this->get('directory/at/oriental-plaza');

        $page->assertOK();
        $page->assertSee('Oriental Plaza');
        $page->assertSee('Shop Number 1');
        $this->assertStringNotContainsString('noindex', (string) $page->response()->getBody());
    }

    public function testAThinVenuePageRendersButStaysOutOfTheIndex(): void
    {
        $venue = $this->venue();
        $this->listing(['display_name' => 'Lonely Shop', 'slug' => 'lonely-shop', 'venue_id' => $venue]);

        $page = $this->get('directory/at/oriental-plaza');

        $page->assertOK();
        $page->assertSee('Lonely Shop');
        $this->assertStringContainsString('noindex', (string) $page->response()->getBody());
    }

    public function testAnUnknownOrInactiveVenueIs404(): void
    {
        $this->venue(['is_active' => 0]);

        // The controller throws rather than returning a response, and
        // FeatureTestTrait lets that surface — so assert the throw itself.
        foreach (['no-such-place', 'oriental-plaza'] as $slug) {
            try {
                $this->get('directory/at/' . $slug);
                $this->fail('expected 404 for ' . $slug);
            } catch (\CodeIgniter\Exceptions\PageNotFoundException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testTheVenueRouteBeatsTheListingCatchAll(): void
    {
        helper('slug');
        $this->assertContains('at', listing_reserved_slugs(), 'a listing taking this slug would shadow every venue page');
    }

    // ------------------------------------------------------- who may write it

    public function testAnOwnerSaveCannotSetOrClearTheVenue(): void
    {
        $venue = $this->venue();
        $id    = $this->listing(['display_name' => 'Owned Shop', 'slug' => 'owned-shop', 'venue_id' => $venue]);
        $other = (int) $this->venues->insert(['name' => 'Somewhere Else', 'slug' => 'somewhere-else', 'is_active' => 1], true);

        $result = (new DirectoryListingMutationService())->updateOwn($id, [
            'display_name' => 'Owned Shop',
            'category_id'  => $this->categoryId,
            // Required on an owner save too — see validate().
            'title'          => 'Mr',
            'contact_person' => 'Test Owner',
            'latitude'     => '-26.2055556',
            'longitude'    => '28.0222222',
            // Both a move and a clear, in one crafted POST.
            'venue_id'     => $other,
            'venue'        => '',
        ]);

        $this->assertTrue($result['ok'], $result['message']);
        $this->assertSame($venue, (int) $this->listings->find($id)['venue_id']);
    }

    public function testAnAdminSaveSetsAndClearsTheVenue(): void
    {
        $venue = $this->venue();
        $id    = $this->listing(['display_name' => 'Admin Shop', 'slug' => 'admin-shop']);
        $svc   = new DirectoryAdminService();

        $set = $svc->upsert($id, $this->adminPost(['venue_id' => $venue]));
        $this->assertTrue($set['ok'], $set['message']);
        $this->assertSame($venue, (int) $this->listings->find($id)['venue_id']);

        $clear = $svc->upsert($id, $this->adminPost(['venue_id' => '']));
        $this->assertTrue($clear['ok'], $clear['message']);
        $this->assertNull($this->listings->find($id)['venue_id']);
    }

    public function testDeletingAVenueUngroupsItsBusinessesAndDeletesNone(): void
    {
        $venue = $this->venue();
        $id    = $this->listing(['display_name' => 'Survivor', 'slug' => 'survivor', 'venue_id' => $venue]);

        $result = (new DirectoryAdminService())->deleteVenue($venue);

        $this->assertTrue($result['ok'], $result['message']);
        $this->assertStringContainsString('1 profile', $result['message']);
        $row = $this->listings->find($id);
        $this->assertNotNull($row, 'the business must survive its venue');
        $this->assertNull($row['venue_id']);
    }

    // ------------------------------------------------------ suggest + sitemap

    public function testTheTypeaheadOffersTheVenue(): void
    {
        $venue = $this->venue();
        $this->listing(['display_name' => 'Anything', 'slug' => 'anything', 'venue_id' => $venue]);

        $items = json_decode($this->get('directory/suggest?q=orien')->getJSON(), true)['items'];
        $venues = array_values(array_filter($items, static fn ($i) => $i['type'] === 'venue'));

        $this->assertNotSame([], $venues, 'typing a venue name should offer the venue');
        $this->assertSame('Oriental Plaza', $venues[0]['label']);
        $this->assertStringEndsWith('/directory/at/oriental-plaza', $venues[0]['url']);
    }

    public function testTheSitemapCarriesAVenueOnlyOnceItClearsTheThreshold(): void
    {
        $venue = $this->venue();
        $this->listing(['display_name' => 'Only One', 'slug' => 'only-one', 'venue_id' => $venue]);

        $svc = new DirectoryService();
        $this->assertSame([], $svc->sitemapVenueUrls(3));

        for ($i = 0; $i < 2; $i++) {
            $this->listing(['display_name' => 'More ' . $i, 'slug' => 'more-' . $i, 'venue_id' => $venue]);
        }

        $urls = array_column($svc->sitemapVenueUrls(3), 'loc');
        $this->assertCount(1, $urls);
        $this->assertStringEndsWith('/directory/at/oriental-plaza', $urls[0]);
    }

    // -------------------------------------------------------------- backfill

    public function testTheBackfillAssignsByTheSharedAddressLine(): void
    {
        $venue = $this->venue();
        $this->listing(['display_name' => 'Shop A', 'slug' => 'shop-a', 'address_line_2' => 'Oriental Plaza, 62 Bree Street']);
        $this->listing(['display_name' => 'Shop B', 'slug' => 'shop-b', 'address_line_2' => 'Oriental Plaza, 62 Bree Street']);
        $this->listing(['display_name' => 'Somewhere Else', 'slug' => 'somewhere-else-shop', 'address_line_2' => 'Other Mall']);

        // --dry-run writes nothing…
        command('directory:venue-assign --venue oriental-plaza --address2 "Oriental Plaza, 62 Bree Street" --dry-run');
        $this->assertSame(0, $this->listings->where('venue_id', $venue)->countAllResults());

        // …and without it, the two matching shops are assigned and the third is not.
        command('directory:venue-assign --venue oriental-plaza --address2 "Oriental Plaza, 62 Bree Street" --set-venue-point');

        $this->assertSame(2, $this->listings->where('venue_id', $venue)->countAllResults());
        $this->assertNull($this->listings->where('slug', 'somewhere-else-shop')->first()['venue_id']);
        // The shops share one coordinate, so the venue takes it as its pin.
        $this->assertSame('-26.2055556', (string) $this->venues->find($venue)['latitude']);
    }

    // -------------------------------------------------------------- helpers

    /** @param array<string,mixed> $overrides */
    private function venue(array $overrides = []): int
    {
        return (int) $this->venues->insert($overrides + [
            'name'         => 'Oriental Plaza',
            'slug'         => 'oriental-plaza',
            'address_line' => '62 Bree Street',
            'suburb'       => 'Fordsburg',
            'city'         => 'Johannesburg',
            'province'     => 'Gauteng',
            'is_active'    => 1,
        ], true);
    }

    /** @param array<string,mixed> $overrides */
    private function listing(array $overrides): int
    {
        return (int) $this->listings->insert($overrides + [
            'type'         => 'person',
            'display_name' => 'Test Shop',
            'email'        => 'venue-' . bin2hex(random_bytes(4)) . '@example.test',
            'category_id'  => $this->categoryId,
            'slug'         => 'test-shop-' . bin2hex(random_bytes(3)),
            'status'       => 'published',
            'is_verified'  => 1,
            'city'         => 'Johannesburg',
            'province'     => 'Gauteng',
            'latitude'     => '-26.2055556',
            'longitude'    => '28.0222222',
        ], true);
    }

    /**
     * The admin form always posts every field; `status` is what tells upsert()
     * the privileged block is meaningful.
     *
     * @param array<string,mixed> $overrides
     * @return array<string,mixed>
     */
    private function adminPost(array $overrides): array
    {
        return $overrides + [
            'display_name' => 'Admin Shop',
            'category_id'  => $this->categoryId,
            'status'       => 'published',
            'latitude'     => '-26.2055556',
            'longitude'    => '28.0222222',
        ];
    }
}

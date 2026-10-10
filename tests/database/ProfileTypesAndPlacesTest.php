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
use Tests\Support\ValidListingInput;

/**
 * Profiles that are not businesses (NGO, community body, foundation, project)
 * and Places, such as a sports club, that hold other businesses.
 *
 * What these pin: the type list is one list and the model rule agrees with
 * it; the new types save through owner edit and admin intake and an unknown
 * one never does; only an admin can turn a Place into a venue, and then the
 * place lists what is inside it (published only, never itself) while each
 * business inside links back to the place.
 *
 * Requires the `tests` database group — see the Tests section of README.md.
 *
 * @internal
 */
final class ProfileTypesAndPlacesTest extends CIUnitTestCase
{
    use ValidListingInput;
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $namespace   = 'App';
    protected $migrate     = true;
    protected $migrateOnce = false;
    protected $refresh     = true;

    private DirectoryListingModel $listings;
    private DirectoryVenueModel $venues;
    private int $sportId;
    private int $ngoId;

    protected function setUp(): void
    {
        parent::setUp();
        helper(['directory_ui', 'schema']);
        $this->listings = new DirectoryListingModel();
        $this->venues   = new DirectoryVenueModel();
        $categories     = new DirectoryCategoryModel();
        $this->sportId  = (int) $categories->insert(['name' => 'Sports Club', 'slug' => 'sports-club', 'group_name' => 'Fitness & Sport', 'is_active' => 1], true);
        $this->ngoId    = (int) $categories->insert(['name' => 'NGO & Nonprofit', 'slug' => 'ngo-nonprofit', 'group_name' => 'Community & Nonprofit', 'is_active' => 1], true);
    }

    // ------------------------------------------------------------- types

    public function testTheModelRuleAllowsExactlyTheTypeList(): void
    {
        $rule = (new DirectoryListingModel())->getValidationRules()['type'];
        preg_match('/in_list\[([^\]]+)\]/', $rule, $m);

        $this->assertSame(array_keys(DirectoryListingModel::TYPES), explode(',', $m[1]));
    }

    public function testEveryShortlistEntryIsASeededCategoryOrGroup(): void
    {
        helper('slug');
        $source = (string) file_get_contents(APPPATH . 'Database/Seeds/DirectoryCategoriesSeeder.php');
        // Only the $groups array, with comments stripped: an apostrophe in a
        // comment would otherwise pair up the wrong quotes.
        preg_match('/\$groups = \[(.*?)\n        \];/s', $source, $block);
        preg_match_all("/'([^']+)'/", (string) preg_replace('#^\s*//.*$#m', '', $block[1] ?? ''), $m);
        $seededSlugs = array_map('slugify', $m[1]);

        foreach (DirectoryListingModel::TYPE_SUGGESTIONS as $type => $entries) {
            $this->assertArrayHasKey($type, DirectoryListingModel::TYPES);
            foreach ($entries as $entry) {
                if (str_starts_with($entry, 'group:')) {
                    $this->assertStringContainsString("'" . substr($entry, 6) . "' => [", $source, "{$type}: {$entry} is not a seeded group");
                } else {
                    $this->assertContains($entry, $seededSlugs, "{$type}: {$entry} is not a seeded category");
                }
            }
        }
    }

    public function testEachTypeCarriesItsShortlistInTheForm(): void
    {
        $categories = new DirectoryCategoryModel();
        $hall   = (int) $categories->insert(['name' => 'Community Hall & Centre', 'slug' => 'community-hall-centre', 'group_name' => 'Places & Venues', 'is_active' => 1], true);
        $mall   = (int) $categories->insert(['name' => 'Shopping Centre & Mall', 'slug' => 'shopping-centre-mall', 'group_name' => 'Places & Venues', 'is_active' => 1], true);
        $church = (int) $categories->insert(['name' => 'Church', 'slug' => 'church', 'group_name' => 'Faith & Worship', 'is_active' => 1], true);

        $html = (string) $this->get('add-profile')->response()->getBody();
        $this->assertStringContainsString('data-type-picker', $html);

        $suggest = static function (string $type) use ($html): array {
            preg_match('#<option value="' . $type . '"[^>]*data-suggest="([0-9,]+)"#', $html, $m);

            return array_map('intval', explode(',', $m[1] ?? ''));
        };

        // A whole group, then single slugs from other groups, in that order.
        $place = $suggest('place');
        $this->assertSame([$hall, $mall], array_slice($place, 0, 2), 'Places & Venues comes first for a Place');
        $this->assertContains($this->sportId, $place, 'Sports Club is on the Place shortlist');
        $this->assertContains($church, $place);

        $this->assertSame($this->ngoId, $suggest('ngo')[0]);
        $this->assertDoesNotMatchRegularExpression('#<option value="practice"[^>]*data-suggest#', $html, 'a business type gets the full list');
        $this->assertStringNotContainsString(' / ', implode('', DirectoryListingModel::TYPES));
    }

    public function testEverySearchSynonymPointsAtASeededCategory(): void
    {
        helper('slug');
        $source = (string) file_get_contents(APPPATH . 'Database/Seeds/DirectoryCategoriesSeeder.php');
        preg_match('/\$groups = \[(.*?)\n        \];/s', $source, $block);
        preg_match_all("/'([^']+)'/", (string) preg_replace('#^\s*//.*$#m', '', $block[1] ?? ''), $m);
        $seeded = array_map('slugify', $m[1]);

        // A synonym whose slug is not seeded is skipped silently at runtime,
        // so a typo here would just never match. Catch it here instead.
        foreach (config('Search')->categorySynonyms as $phrase => $slug) {
            $this->assertContains($slug, $seeded, "synonym '{$phrase}' points at '{$slug}'");
        }
    }

    public function testAnOwnerCanChooseANewTypeButNotAnUnknownOne(): void
    {
        $id  = $this->listing(['slug' => 'hope-trust', 'display_name' => 'Hope Trust', 'category_id' => $this->ngoId]);
        $svc = new DirectoryListingMutationService();

        $ok = $svc->updateOwn($id, $this->ownerPost(['type' => 'foundation', 'display_name' => 'Hope Trust', 'category_id' => $this->ngoId]));
        $this->assertTrue($ok['ok'], $ok['message']);
        $this->assertSame('foundation', $this->listings->find($id)['type']);

        $svc->updateOwn($id, $this->ownerPost(['type' => 'charity-shop', 'display_name' => 'Hope Trust', 'category_id' => $this->ngoId]));
        $this->assertSame('foundation', $this->listings->find($id)['type'], 'an unknown type keeps the stored one');
    }

    public function testAdminIntakeSavesAPlaceAndFallsBackToPerson(): void
    {
        $svc = new DirectoryAdminService();
        $id  = $this->listing(['slug' => 'the-club']);

        $svc->upsert($id, $this->adminPost(['type' => 'place']));
        $this->assertSame('place', $this->listings->find($id)['type']);

        $svc->upsert($id, $this->adminPost(['type' => 'nonsense']));
        $this->assertSame('person', $this->listings->find($id)['type']);
    }

    public function testANonprofitShowsItsTypeAndPublishesAsAnNgo(): void
    {
        $this->listing(['slug' => 'hope-trust', 'display_name' => 'Hope Trust', 'type' => 'ngo', 'category_id' => $this->ngoId]);

        $html = (string) $this->get('directory/hope-trust')->response()->getBody();

        $this->assertStringContainsString('badge-type', $html);
        $this->assertStringContainsString('Nonprofit', $html);
        $this->assertStringContainsString('"@type":["LocalBusiness","NGO"]', $html);
    }

    public function testABusinessTypeRendersNoTypeBadge(): void
    {
        $this->listing(['slug' => 'plain-shop', 'type' => 'practice']);

        $this->assertStringNotContainsString('badge-type', (string) $this->get('directory/plain-shop')->response()->getBody());
    }

    public function testSchemaTypeFollowsTheProfileTypeFirst(): void
    {
        $this->assertSame(['LocalBusiness', 'NGO'], schema_business_type('Fitness & Sport', 'sports-club', 'project'));
        $this->assertSame('SportsClub', schema_business_type('Fitness & Sport', 'sports-club', 'place'));
        $this->assertSame(['LocalBusiness', 'NGO'], schema_business_type('Community & Nonprofit', 'ngo-nonprofit', 'practice'));
    }

    public function testAPlaceOfWorshipIsNamedExactlyAndNotAsAnNgo(): void
    {
        $this->assertSame(['LocalBusiness', 'Church'], schema_business_type('Community & Nonprofit', 'church', 'place'));
        $this->assertSame(['LocalBusiness', 'Mosque'], schema_business_type('Community & Nonprofit', 'mosque', 'ngo'));
        $this->assertSame(['LocalBusiness', 'PlaceOfWorship'], schema_business_type('Faith & Worship', 'place-of-worship'));
        $this->assertSame('ShoppingCenter', schema_business_type('Places & Venues', 'shopping-centre-mall', 'place'));
        $this->assertSame(['LocalBusiness', 'Museum'], schema_business_type('Places & Venues', 'museum-gallery', 'place'));
    }

    public function testAPlaceOfWorshipShowsServiceTimesAndALanguageFilter(): void
    {
        $vertical = config('Verticals')->forCategory('Faith & Worship', 'church');
        $this->assertSame('Service times', $vertical['headings']['hours']);
        $this->assertSame('Registrations', $vertical['headings']['credentials'], 'the group headings still apply');

        $facets = config('ListingFacets')->forCategory('Faith & Worship', 'mosque');
        $this->assertArrayHasKey('worship_language', $facets);
    }

    // ------------------------------------------------------------ places

    public function testOnlyAPlaceCanBecomeAVenueAndOnlyOnce(): void
    {
        $svc  = new DirectoryAdminService();
        $shop = $this->listing(['slug' => 'karate-dojo', 'type' => 'practice']);
        $this->assertFalse($svc->venueFromPlace($shop)['ok']);

        $club   = $this->place();
        $result = $svc->venueFromPlace($club);
        $this->assertTrue($result['ok'], $result['message']);

        $venue = $this->venues->find($result['venue_id']);
        $this->assertSame($club, (int) $venue['listing_id']);
        $this->assertSame('Johannesburg', $venue['city']);
        $this->assertSame($result['venue_id'], (int) $this->listings->find($club)['venue_id'], 'the place sits in its own venue');

        $this->assertFalse($svc->venueFromPlace($club)['ok']);
    }

    public function testTheVenueFormLinksOnlyAPlaceProfile(): void
    {
        $svc  = new DirectoryAdminService();
        $shop = $this->listing(['slug' => 'karate-dojo', 'type' => 'practice']);
        $this->place();

        $refused = $svc->saveVenue(null, ['name' => 'Club Grounds', 'place_slug' => 'karate-dojo']);
        $this->assertFalse($refused['ok']);

        $ok = $svc->saveVenue(null, ['name' => 'Club Grounds', 'place_slug' => 'wanderers-club', 'is_active' => 1]);
        $this->assertTrue($ok['ok'], $ok['message']);
        $this->assertNotNull($this->venues->where('listing_id', $this->listings->where('slug', 'wanderers-club')->first()['id'])->first());
        $this->assertNull($this->listings->find($shop)['venue_id']);
    }

    public function testAPlaceListsWhatIsInsideAndEachBusinessLinksBack(): void
    {
        $club  = $this->place();
        $venue = (new DirectoryAdminService())->venueFromPlace($club)['venue_id'];
        $this->listing(['slug' => 'karate-dojo', 'display_name' => 'Kicks Karate Dojo', 'venue_id' => $venue]);
        $this->listing(['slug' => 'pending-cafe', 'display_name' => 'Pending Cafe', 'venue_id' => $venue, 'status' => 'pending']);

        $inside = (new DirectoryService())->insidePlace($venue, $club);
        $this->assertSame(['karate-dojo'], array_column($inside['items'], 'slug'));
        $this->assertSame(1, $inside['total']);

        $place = (string) $this->get('directory/wanderers-club')->response()->getBody();
        $this->assertStringContainsString('Inside Wanderers Club', $place);
        $this->assertStringContainsString('Kicks Karate Dojo', $place);
        $this->assertStringNotContainsString('Pending Cafe', $place);

        $dojo = (string) $this->get('directory/karate-dojo')->response()->getBody();
        $this->assertMatchesRegularExpression('#href="[^"]*/directory/wanderers-club"#', $dojo);

        $venuePage = (string) $this->get('directory/at/wanderers-club')->response()->getBody();
        $this->assertStringContainsString('About Wanderers Club and its facilities', $venuePage);
    }

    public function testAnOwnerStillCannotAttachThemselvesToAPlace(): void
    {
        $club  = $this->place();
        $venue = (new DirectoryAdminService())->venueFromPlace($club)['venue_id'];
        $id    = $this->listing(['slug' => 'gate-crasher', 'display_name' => 'Gate Crasher']);

        (new DirectoryListingMutationService())->updateOwn($id, $this->ownerPost(['display_name' => 'Gate Crasher', 'venue_id' => $venue]));

        $this->assertNull($this->listings->find($id)['venue_id']);
    }

    // ------------------------------------------------------------ helpers

    private function place(): int
    {
        return $this->listing(['slug' => 'wanderers-club', 'display_name' => 'Wanderers Club', 'type' => 'place']);
    }

    /** @param array<string,mixed> $overrides */
    private function listing(array $overrides = []): int
    {
        return (int) $this->listings->insert($overrides + [
            'type'         => 'practice',
            'display_name' => 'Test Place',
            'email'        => 'pl-' . bin2hex(random_bytes(4)) . '@example.test',
            'category_id'  => $this->sportId,
            'status'       => 'published',
            'is_verified'  => 1,
            'address_line' => '21 North Street',
            'suburb'       => 'Illovo',
            'city'         => 'Johannesburg',
            'province'     => 'Gauteng',
            'latitude'     => '-26.1300000',
            'longitude'    => '28.0500000',
        ], true);
    }

    /**
     * @param array<string,mixed> $fields
     * @return array<string,mixed>
     */
    private function ownerPost(array $fields): array
    {
        return $this->withRequiredSections($fields + [
            'category_id'    => $this->sportId,
            'title'          => 'Mr',
            'contact_person' => 'Test Owner',
            'latitude'       => '-26.1300000',
            'longitude'      => '28.0500000',
        ]);
    }

    /**
     * @param array<string,mixed> $overrides
     * @return array<string,mixed>
     */
    private function adminPost(array $overrides): array
    {
        return $overrides + [
            'display_name' => 'The Club',
            'category_id'  => $this->sportId,
            'status'       => 'published',
            'latitude'     => '-26.1300000',
            'longitude'    => '28.0500000',
        ];
    }
}

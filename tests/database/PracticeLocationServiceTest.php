<?php

use App\Libraries\Geocoding\GeocoderInterface;
use App\Libraries\ListingGeocoder;
use App\Models\DirectoryListingModel;
use App\Models\DirectoryPracticeLocationModel;
use App\Services\DirectoryService;
use App\Services\PracticeLocationService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * Extra branches, reconciled from one form submission.
 *
 * The contract is deliberately identical to TeamMemberServiceTest's — same
 * blank-row rule, same delete-what-was-not-submitted rule, and above all the
 * same cross-listing rule, because the ids ride in on a form body either way.
 * The differences tested here are the ones that are genuinely different: no
 * files, a lower cap, and province values that have to match the list the
 * province landing pages are built from.
 *
 * Requires the `tests` database group — see the Tests section of README.md.
 *
 * @internal
 */
final class PracticeLocationServiceTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $namespace   = 'App';
    protected $migrate     = true;
    protected $migrateOnce = false;
    protected $refresh     = true;

    private PracticeLocationService $service;
    private DirectoryPracticeLocationModel $locations;
    private DirectoryListingModel $listings;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service   = new PracticeLocationService();
        $this->locations = new DirectoryPracticeLocationModel();
        $this->listings  = new DirectoryListingModel();
    }

    // ------------------------------------------------------------- ownership

    public function testASubmittedIdFromAnotherListingCannotHijackTheirRow(): void
    {
        $mine   = $this->listing('Smith Attorneys');
        $theirs = $this->listing('Jones Attorneys');

        $this->service->syncFromForm($theirs, [['name' => 'Their Branch']]);
        $victim = $this->locations->forListing($theirs)[0];

        $result = $this->service->syncFromForm($mine, [
            ['id' => $victim['id'], 'name' => 'Hijacked'],
        ]);

        $this->assertSame([], $result['errors']);
        $this->assertSame('Their Branch', $this->locations->find($victim['id'])['name']);

        $mineRows = $this->locations->forListing($mine);
        $this->assertCount(1, $mineRows);
        $this->assertNotSame((int) $victim['id'], (int) $mineRows[0]['id']);
    }

    public function testSyncingOneListingLeavesAnotherListingsRowsAlone(): void
    {
        $a = $this->listing('Smith Attorneys');
        $b = $this->listing('Jones Attorneys');

        $this->service->syncFromForm($a, [['name' => 'Branch A']]);
        $this->service->syncFromForm($b, [['name' => 'Branch B']]);

        $this->service->syncFromForm($a, []);

        $this->assertSame(0, count($this->locations->forListing($a)));
        $this->assertSame(1, count($this->locations->forListing($b)));
    }

    // ------------------------------------------------------------- badge gate

    public function testCanManageFollowsTheBadgeDate(): void
    {
        $this->assertFalse($this->service->canManage(['verified_until' => null]));
        $this->assertFalse($this->service->canManage(['verified_until' => date('Y-m-d', strtotime('-1 day'))]));
        $this->assertTrue($this->service->canManage(['verified_until' => date('Y-m-d')]));
    }

    // ------------------------------------------------------ the sync contract

    public function testBlankNamedRowsAreSkipped(): void
    {
        $listingId = $this->listing('Smith Attorneys');

        $result = $this->service->syncFromForm($listingId, [
            ['name' => 'Claremont office', 'city' => 'Cape Town'],
            ['name' => '  ', 'city' => 'Typed a city and gave up'],
        ]);

        $this->assertSame([], $result['errors']);
        $this->assertCount(1, $this->locations->forListing($listingId));
    }

    public function testAStoredRowMissingFromTheSubmissionIsDeleted(): void
    {
        $listingId = $this->listing('Smith Attorneys');
        $this->service->syncFromForm($listingId, [['name' => 'Stays'], ['name' => 'Goes']]);
        $rows = $this->locations->forListing($listingId);

        $this->service->syncFromForm($listingId, [['id' => $rows[0]['id'], 'name' => 'Stays']]);

        $this->assertSame(['Stays'], array_column($this->locations->forListing($listingId), 'name'));
    }

    public function testFieldsArePersistedAndPlacesNormalised(): void
    {
        $listingId = $this->listing('Smith Attorneys');

        $this->service->syncFromForm($listingId, [[
            'name'         => 'Claremont office',
            'address_line' => '12 Main Road',
            'suburb'       => 'claremont',
            'city'         => '  cape   town ',
            'province'     => 'Western Cape',
            'phone'        => '021 555 0100',
        ]]);

        $row = $this->locations->forListing($listingId)[0];

        $this->assertSame('Claremont office', $row['name']);
        $this->assertSame('12 Main Road', $row['address_line']);
        // normalise_place(), the same treatment the listing's own address gets.
        $this->assertSame('Claremont', $row['suburb']);
        $this->assertSame('Cape Town', $row['city']);
        $this->assertSame('Western Cape', $row['province']);
        $this->assertSame('021 555 0100', $row['phone']);
        // The listing's own address is the primary one by definition.
        $this->assertSame(0, (int) $row['is_primary']);
    }

    public function testAnUnknownProvinceIsDroppedRatherThanStored(): void
    {
        $listingId = $this->listing('Smith Attorneys');

        $this->service->syncFromForm($listingId, [[
            'name'     => 'Nowhere office',
            'province' => 'Atlantis',
        ]]);

        // The province columns feed the province landing pages, which only work
        // if the values match the known list exactly.
        $this->assertNull($this->locations->forListing($listingId)[0]['province']);
    }

    public function testSubmittedOrderBecomesSortOrder(): void
    {
        $listingId = $this->listing('Smith Attorneys');
        $this->service->syncFromForm($listingId, [
            ['name' => 'First'], ['name' => 'Second'], ['name' => 'Third'],
        ]);
        $rows = $this->locations->forListing($listingId);

        $this->service->syncFromForm($listingId, [
            ['id' => $rows[2]['id'], 'name' => 'Third'],
            ['id' => $rows[0]['id'], 'name' => 'First'],
            ['id' => $rows[1]['id'], 'name' => 'Second'],
        ]);

        $this->assertSame(
            ['Third', 'First', 'Second'],
            array_column($this->locations->forListing($listingId), 'name')
        );
    }

    // -------------------------------------------------------------- the cap

    public function testTheCapRefusesTheWholeSubmission(): void
    {
        $listingId = $this->listing('Big Practice');

        $rows = [];
        for ($i = 0; $i <= PracticeLocationService::MAX_LOCATIONS; $i++) {
            $rows[] = ['name' => 'Branch ' . $i];
        }

        $result = $this->service->syncFromForm($listingId, $rows);

        $this->assertArrayHasKey('locations', $result['errors']);
        $this->assertCount(0, $this->locations->forListing($listingId));
    }

    public function testExactlyTheCapIsAccepted(): void
    {
        $listingId = $this->listing('Big Practice');

        $rows = [];
        for ($i = 0; $i < PracticeLocationService::MAX_LOCATIONS; $i++) {
            $rows[] = ['name' => 'Branch ' . $i];
        }

        $result = $this->service->syncFromForm($listingId, $rows);

        $this->assertSame([], $result['errors']);
        $this->assertCount(PracticeLocationService::MAX_LOCATIONS, $this->locations->forListing($listingId));
    }

    // -------------------------------------------------------------- fixtures

    // ------------------------------------------- parity with the main address

    /**
     * A branch carries the listing's full field set now, and every one of them
     * has to survive a round trip — the profile renders a branch with the same
     * partials as the primary, so a column that silently fails to save shows up
     * as a missing panel rather than an error.
     */
    public function testABranchRoundTripsTheFullFieldSet(): void
    {
        $listing = $this->listing('Full Field Co');

        $result = $this->service->syncFromForm($listing, [[
            'name'           => 'Claremont office',
            'contact_person' => 'Thandi Mokoena',
            'address_line'   => '12 Main Road',
            'address_line_2' => 'Unit 4B',
            'suburb'         => 'claremont',
            'city'           => 'cape town',
            'province'       => 'Western Cape',
            'postal_code'    => '7708',
            'phone'          => '021 555 0100',
            'phone_alt'      => '082 555 0100',
            'email'          => 'claremont@example.test',
            'hours'          => [
                'mon' => ['open' => '08:00', 'close' => '17:00'],
                'sun' => ['closed' => '1'],
            ],
        ]]);

        $this->assertSame([], $result['errors']);

        $row = $this->locations->forListing($listing)[0];
        $this->assertSame('Claremont office', $row['name']);
        $this->assertSame('Thandi Mokoena', $row['contact_person']);
        $this->assertSame('12 Main Road', $row['address_line']);
        $this->assertSame('Unit 4B', $row['address_line_2']);
        $this->assertSame('7708', $row['postal_code']);
        $this->assertSame('082 555 0100', $row['phone_alt']);
        $this->assertSame('claremont@example.test', $row['email']);

        // normalise_place(), the same helper the listing's own address uses.
        $this->assertSame('Claremont', $row['suburb']);
        $this->assertSame('Cape Town', $row['city']);

        // Stored as the same JSON the listing's hours use, so the shared hours
        // panel needs no branch-specific case.
        $hours = hours_decode($row['trading_hours']);
        $this->assertSame('08:00', $hours['mon']['open']);
        $this->assertNotEmpty($hours['sun']['closed']);
    }

    public function testABranchWithoutACountryGetsTheDefault(): void
    {
        $listing = $this->listing('Default Country Co');
        $this->service->syncFromForm($listing, [['name' => 'Branch', 'city' => 'Durban']]);

        // Not cosmetic: ListingGeocoder appends a country to its query, and a
        // branch without one resolves against the whole world.
        $this->assertSame('South Africa', $this->locations->forListing($listing)[0]['country']);
    }

    public function testAnInvalidBranchEmailIsRejected(): void
    {
        $listing = $this->listing('Bad Email Co');

        $result = $this->service->syncFromForm($listing, [[
            'name'  => 'Branch',
            'email' => 'not-an-address',
        ]]);

        $this->assertArrayHasKey('locations.0.email', $result['errors']);
        $this->assertSame([], $this->locations->forListing($listing), 'nothing may be written on a validation failure');
    }

    // ------------------------------------------------------------ geocoding

    public function testABranchAddressIsGeocodedOnSave(): void
    {
        $listing = $this->listing('Geocoded Co');
        $service = $this->serviceWithStubGeocoder();

        $service->syncFromForm($listing, [[
            'name'         => 'Branch',
            'address_line' => '12 Main Road',
            'city'         => 'Cape Town',
        ]]);

        $row = $this->locations->forListing($listing)[0];
        $this->assertSame(-33.9249, (float) $row['latitude']);
        $this->assertSame(18.4241, (float) $row['longitude']);
        $this->assertSame('exact', $row['geocode_precision']);
        $this->assertSame('ok', $row['geocoding_status']);
        $this->assertNotNull($row['geocoded_at']);
    }

    /**
     * The reason resolve() is handed the stored row: with MAX_LOCATIONS branches
     * a save that re-geocoded every one of them would spend six network round
     * trips to change a phone number.
     */
    public function testEditingOnlyThePhoneDoesNotRegeocode(): void
    {
        $listing = $this->listing('No Regeocode Co');
        $service = $this->serviceWithStubGeocoder();

        $service->syncFromForm($listing, [[
            'name'         => 'Branch',
            'address_line' => '12 Main Road',
            'city'         => 'Cape Town',
        ]]);
        $before = $this->locations->forListing($listing)[0];

        $service->syncFromForm($listing, [[
            'id'           => $before['id'],
            'name'         => 'Branch',
            'address_line' => '12 Main Road',
            'city'         => 'Cape Town',
            'phone'        => '021 555 0199',
        ]]);
        $after = $this->locations->forListing($listing)[0];

        $this->assertSame('021 555 0199', $after['phone'], 'the edit must still land');
        $this->assertSame($before['latitude'], $after['latitude']);
        $this->assertSame($before['longitude'], $after['longitude']);
        $this->assertSame($before['geocoded_at'], $after['geocoded_at'], 'no lookup should have been spent');
    }

    public function testChangingTheCityDoesRegeocode(): void
    {
        $listing = $this->listing('Regeocode Co');
        $service = $this->serviceWithStubGeocoder();

        $service->syncFromForm($listing, [[
            'name'         => 'Branch',
            'address_line' => '12 Main Road',
            'city'         => 'Cape Town',
        ]]);
        $before = $this->locations->forListing($listing)[0];

        $service->syncFromForm($listing, [[
            'id'           => $before['id'],
            'name'         => 'Branch',
            'address_line' => '12 Main Road',
            'city'         => 'Durban',
        ]]);
        $after = $this->locations->forListing($listing)[0];

        $this->assertNotSame($before['geocoded_address'], $after['geocoded_address']);
        $this->assertSame('Durban', $after['city']);
    }

    /**
     * Branches deliberately stay out of the spatial index, so one listing stays
     * one search result. If this ever changes it is a decision, not a drift.
     */
    public function testABranchDoesNotEnterTheSearchIndex(): void
    {
        $listing = $this->listing('No Search Pin Co');
        $service = $this->serviceWithStubGeocoder();

        $service->syncFromForm($listing, [[
            'name'         => 'Branch',
            'address_line' => '12 Main Road',
            'city'         => 'Cape Town',
        ]]);

        $points = $this->db->table('directory_listing_points')
            ->where('listing_id', $listing)->countAllResults();
        $this->assertSame(0, $points);
    }

    // ------------------------------------------------------------- the read

    public function testGetProfileDecodesEachBranchesHours(): void
    {
        $listing = $this->listing('Decoded Hours Co');
        $this->service->syncFromForm($listing, [[
            'name'  => 'Branch',
            'hours' => ['mon' => ['open' => '09:00', 'close' => '16:00']],
        ]]);

        $slug    = $this->listings->find($listing)['slug'];
        $profile = (new DirectoryService())->getProfile($slug);

        // The shared hours panel is handed this directly and expects an array —
        // a raw JSON string would render nothing at all.
        $hours = $profile['locations'][0]['trading_hours'];
        $this->assertIsArray($hours);
        $this->assertSame('09:00', $hours['mon']['open']);
    }

    /**
     * A service whose geocoder answers without a network call.
     *
     * The stub returns a different label per city so the re-geocode test can
     * tell one lookup from another.
     */
    private function serviceWithStubGeocoder(): PracticeLocationService
    {
        $stub = new class implements GeocoderInterface {
            public function suggest(string $query, int $limit = 5): array
            {
                return [];
            }

            public function geocodeParts(array $parts): ?array
            {
                $city = (string) ($parts['city'] ?? '');
                if ($city === '' && (string) ($parts['address_line'] ?? '') === '') {
                    return null;
                }

                return [
                    'lat'       => -33.9249,
                    'lng'       => 18.4241,
                    'precision' => 'exact',
                    'label'     => trim($parts['address_line'] . ', ' . $city, ', '),
                ];
            }

            public function reverse(float $lat, float $lng): ?array
            {
                return null;
            }
        };

        return new PracticeLocationService(new ListingGeocoder($stub));
    }

    private function listing(string $name): int
    {
        return (int) $this->listings->insert([
            'type'         => 'practice',
            'display_name' => $name,
            'email'        => strtolower(str_replace(' ', '', $name)) . '@example.test',
            'slug'         => strtolower(str_replace(' ', '-', $name)),
            'status'       => 'published',
        ], true);
    }
}

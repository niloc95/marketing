<?php

use App\Models\DirectoryListingModel;
use App\Models\DirectoryPracticeLocationModel;
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

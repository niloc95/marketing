<?php

use App\Models\DirectoryListingModel;
use App\Models\DirectoryListingTeamModel;
use App\Services\DirectoryAdminService;
use App\Services\TeamMemberService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * Team members: what one form submission does to a listing's stored rows.
 *
 * The cross-listing test is the point of this file. Team rows moved inside the
 * listing form, so their ids now arrive in a form body the owner controls rather
 * than in a URL — and an owner session still grants authority over exactly one
 * listing. DirectoryListingTeamModel::findForListing() is the only thing
 * standing between those two facts. If it is ever weakened, or a new write path
 * skips it, testASubmittedIdFromAnotherListingCannotHijackTheirRow fails rather
 * than the bug shipping.
 *
 * Requires the `tests` database group — see the Tests section of README.md.
 *
 * @internal
 */
final class TeamMemberServiceTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $namespace   = 'App';
    protected $migrate     = true;
    protected $migrateOnce = false;
    protected $refresh     = true;

    private TeamMemberService $service;
    private DirectoryListingTeamModel $members;
    private DirectoryListingModel $listings;

    /** Files written under FCPATH by a test, removed again in tearDown(). */
    private array $written = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->service  = new TeamMemberService();
        $this->members  = new DirectoryListingTeamModel();
        $this->listings = new DirectoryListingModel();
    }

    protected function tearDown(): void
    {
        foreach ($this->written as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }
        $this->written = [];
        parent::tearDown();
    }

    // ------------------------------------------------------------- ownership

    public function testASubmittedIdFromAnotherListingCannotHijackTheirRow(): void
    {
        $mine   = $this->listing('Smith Attorneys');
        $theirs = $this->listing('Jones Attorneys');

        $this->service->syncFromForm($theirs, [['name' => 'Their Person']]);
        $victim = $this->members->forListing($theirs)[0];

        // Listing A submits a row carrying listing B's member id.
        $result = $this->service->syncFromForm($mine, [
            ['id' => $victim['id'], 'name' => 'Hijacked'],
        ]);

        $this->assertSame([], $result['errors']);

        // Their row is untouched…
        $this->assertSame('Their Person', $this->members->find($victim['id'])['name']);

        // …and the submission became a new row on the submitting listing,
        // rather than being silently dropped.
        $mineRows = $this->members->forListing($mine);
        $this->assertCount(1, $mineRows);
        $this->assertSame('Hijacked', $mineRows[0]['name']);
        $this->assertNotSame((int) $victim['id'], (int) $mineRows[0]['id']);
    }

    public function testSyncingOneListingLeavesAnotherListingsRowsAlone(): void
    {
        $a = $this->listing('Smith Attorneys');
        $b = $this->listing('Jones Attorneys');

        $this->service->syncFromForm($a, [['name' => 'Person A']]);
        $this->service->syncFromForm($b, [['name' => 'Person B']]);

        // An empty submission clears A's team — and must not reach past it.
        $this->service->syncFromForm($a, []);

        $this->assertSame(0, $this->members->countForListing($a));
        $this->assertSame(1, $this->members->countForListing($b));
    }

    // ------------------------------------------------------------- badge gate

    public function testCanManageFollowsTheBadgeDate(): void
    {
        $this->assertFalse($this->service->canManage(['verified_until' => null]));
        $this->assertFalse($this->service->canManage(['verified_until' => '']));
        $this->assertFalse($this->service->canManage(['verified_until' => date('Y-m-d', strtotime('-1 day'))]));

        // Today still counts — the badge is paid through the end of that day.
        $this->assertTrue($this->service->canManage(['verified_until' => date('Y-m-d')]));
        $this->assertTrue($this->service->canManage(['verified_until' => date('Y-m-d', strtotime('+1 month'))]));
    }

    // ------------------------------------------------------ the sync contract

    public function testBlankNamedRowsAreSkippedRatherThanRejected(): void
    {
        $listingId = $this->listing('Smith Attorneys');

        // The trailing blank slot the form always renders, plus a row someone
        // half-filled and abandoned. Neither is an error.
        $result = $this->service->syncFromForm($listingId, [
            ['name' => 'Real Person'],
            ['name' => '   '],
            ['name' => '', 'role' => 'Typed then thought better of it'],
        ]);

        $this->assertSame([], $result['errors']);
        $this->assertSame(1, $this->members->countForListing($listingId));
    }

    public function testAStoredRowMissingFromTheSubmissionIsDeleted(): void
    {
        $listingId = $this->listing('Smith Attorneys');
        $this->service->syncFromForm($listingId, [['name' => 'Stays'], ['name' => 'Goes']]);

        $rows = $this->members->forListing($listingId);
        $this->service->syncFromForm($listingId, [
            ['id' => $rows[0]['id'], 'name' => 'Stays'],
        ]);

        $this->assertSame(['Stays'], array_column($this->members->forListing($listingId), 'name'));
    }

    public function testATickedRemoveBoxDeletesTheRow(): void
    {
        $listingId = $this->listing('Smith Attorneys');
        $this->service->syncFromForm($listingId, [['name' => 'Stays'], ['name' => 'Goes']]);
        $rows = $this->members->forListing($listingId);

        $this->service->syncFromForm($listingId, [
            ['id' => $rows[0]['id'], 'name' => 'Stays'],
            ['id' => $rows[1]['id'], 'name' => 'Goes', '_remove' => '1'],
        ]);

        $this->assertSame(['Stays'], array_column($this->members->forListing($listingId), 'name'));
    }

    public function testAnExistingRowIsUpdatedInPlaceNotReplaced(): void
    {
        $listingId = $this->listing('Smith Attorneys');
        $this->service->syncFromForm($listingId, [['name' => 'Jane Smith']]);
        $before = $this->members->forListing($listingId)[0];

        $this->service->syncFromForm($listingId, [
            ['id' => $before['id'], 'name' => 'Jane Smith-Brown', 'role' => 'Partner'],
        ]);

        $after = $this->members->forListing($listingId);
        $this->assertCount(1, $after);
        $this->assertSame((int) $before['id'], (int) $after[0]['id']);
        $this->assertSame('Jane Smith-Brown', $after[0]['name']);
        $this->assertSame('Partner', $after[0]['role']);
    }

    public function testSubmittedOrderBecomesSortOrder(): void
    {
        $listingId = $this->listing('Smith Attorneys');
        $this->service->syncFromForm($listingId, [
            ['name' => 'First'], ['name' => 'Second'], ['name' => 'Third'],
        ]);
        $rows = $this->members->forListing($listingId);

        // Resubmitted back to front.
        $this->service->syncFromForm($listingId, [
            ['id' => $rows[2]['id'], 'name' => 'Third'],
            ['id' => $rows[1]['id'], 'name' => 'Second'],
            ['id' => $rows[0]['id'], 'name' => 'First'],
        ]);

        $this->assertSame(
            ['Third', 'Second', 'First'],
            array_column($this->members->forListing($listingId), 'name')
        );
    }

    // -------------------------------------------------------------- the cap

    public function testTheCapRefusesTheWholeSubmission(): void
    {
        $listingId = $this->listing('Big Practice');

        $rows = [];
        for ($i = 0; $i <= TeamMemberService::MAX_MEMBERS; $i++) {
            $rows[] = ['name' => 'Member ' . $i];
        }

        $result = $this->service->syncFromForm($listingId, $rows);

        $this->assertArrayHasKey('team', $result['errors']);
        $this->assertSame(0, $this->members->countForListing($listingId));
    }

    // --------------------------------------------------------------- slugs

    public function testSlugsDedupeWithinAListingButRepeatAcrossListings(): void
    {
        $a = $this->listing('Smith Attorneys');
        $b = $this->listing('Jones Attorneys');

        $this->service->syncFromForm($a, [['name' => 'Jane Smith'], ['name' => 'Jane Smith']]);
        $this->service->syncFromForm($b, [['name' => 'Jane Smith']]);

        $this->assertSame(
            ['jane-smith', 'jane-smith-2'],
            array_column($this->members->forListing($a), 'slug')
        );

        // The unique key is on the pair, so the other firm's Jane Smith keeps
        // the clean slug rather than being pushed to -3 for no reason.
        $this->assertSame('jane-smith', $this->members->forListing($b)[0]['slug']);
    }

    public function testRenamingToTheSameSlugDoesNotCollideWithItself(): void
    {
        $listingId = $this->listing('Smith Attorneys');
        $this->service->syncFromForm($listingId, [['name' => 'Jane Smith']]);
        $row = $this->members->forListing($listingId)[0];

        $this->service->syncFromForm($listingId, [
            ['id' => $row['id'], 'name' => 'Jane  Smith', 'role' => 'Partner'],
        ]);

        $this->assertSame('jane-smith', $this->members->find($row['id'])['slug']);
    }

    // ----------------------------------------------------- specializations

    public function testSpecializationsAreTrimmedDedupedAndRejoined(): void
    {
        $listingId = $this->listing('Smith Attorneys');

        $result = $this->service->syncFromForm($listingId, [[
            'name'            => 'Jane Smith',
            'specializations' => '  Conveyancing ,, commercial litigation ,Conveyancing,  Estates  ,',
        ]]);

        $this->assertSame([], $result['errors']);

        $stored = $this->members->forListing($listingId)[0]['specializations'];
        $this->assertSame('Conveyancing, commercial litigation, Estates', $stored);
        $this->assertSame(
            ['Conveyancing', 'commercial litigation', 'Estates'],
            TeamMemberService::splitSpecializations($stored)
        );
    }

    public function testTooManySpecializationsIsAnErrorNotASilentTruncation(): void
    {
        $listingId = $this->listing('Smith Attorneys');
        $areas     = implode(',', array_map(static fn (int $i) => 'Area ' . $i, range(1, 15)));

        $result = $this->service->syncFromForm($listingId, [[
            'name'            => 'Jane Smith',
            'specializations' => $areas,
        ]]);

        $this->assertArrayHasKey('team.0.specializations', $result['errors']);
        $this->assertSame(0, $this->members->countForListing($listingId));
    }

    // ----------------------------------------------------------------- files

    public function testAReplacedHeadshotComesBackAsAnOrphan(): void
    {
        $listingId = $this->listing('Smith Attorneys');
        $old       = $this->headshot('team_old.webp');
        $new       = $this->headshot('team_new.webp');

        $this->service->syncFromForm($listingId, [['name' => 'Jane', 'photo_path' => $old]]);
        $row = $this->members->forListing($listingId)[0];

        $result = $this->service->syncFromForm($listingId, [
            ['id' => $row['id'], 'name' => 'Jane', 'photo_path' => $new],
        ]);

        // Returned, not deleted — the caller unlinks after its transaction
        // commits, so a rollback cannot leave a row pointing at a missing file.
        $this->assertSame([$old], $result['orphans']);
        $this->assertFileExists(rtrim(FCPATH, '/') . '/' . $old);
        $this->assertSame($new, $this->members->find($row['id'])['photo_path']);

        $this->service->discardFiles($result['orphans']);
        $this->assertFileDoesNotExist(rtrim(FCPATH, '/') . '/' . $old);
    }

    public function testADeletedRowReturnsItsHeadshotAsAnOrphan(): void
    {
        $listingId = $this->listing('Smith Attorneys');
        $path      = $this->headshot('team_deleted.webp');

        $this->service->syncFromForm($listingId, [['name' => 'Jane', 'photo_path' => $path]]);

        $result = $this->service->syncFromForm($listingId, []);

        $this->assertSame([$path], $result['orphans']);
        $this->assertSame(0, $this->members->countForListing($listingId));
    }

    public function testARejectedSubmissionHandsBackTheFilesItUploaded(): void
    {
        $listingId = $this->listing('Smith Attorneys');
        $path      = $this->headshot('team_rejected.webp');

        // One good row with a new photo, one that fails validation.
        $result = $this->service->syncFromForm($listingId, [
            ['name' => 'Jane', 'photo_path' => $path],
            ['name' => 'X'], // too short
        ]);

        $this->assertNotSame([], $result['errors']);
        $this->assertSame(0, $this->members->countForListing($listingId));
        $this->assertSame([$path], $result['orphans']);
    }

    public function testPurgingAListingRemovesItsTeamHeadshots(): void
    {
        $listingId = $this->listing('Smith Attorneys');
        $path      = $this->headshot('team_purge_me.webp');
        $this->service->syncFromForm($listingId, [['name' => 'Jane Smith', 'photo_path' => $path]]);

        // purge() only operates on the trash — see DirectoryAdminService.
        $this->listings->delete($listingId);
        $this->assertTrue((new DirectoryAdminService())->purge($listingId));

        $this->assertFileDoesNotExist(rtrim(FCPATH, '/') . '/' . $path);
        $this->assertSame(0, $this->members->countForListing($listingId));
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

    /** Write a stand-in headshot under public/ and return its FCPATH-relative path. */
    private function headshot(string $filename): string
    {
        $dir = rtrim(FCPATH, '/') . '/assets/listings/team';
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $full = $dir . '/' . $filename;
        file_put_contents($full, 'not-really-a-webp');
        $this->written[] = $full;

        return 'assets/listings/team/' . $filename;
    }
}

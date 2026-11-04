<?php

use App\Libraries\ProfessionalRegistration;
use App\Models\DirectoryCategoryModel;
use App\Models\DirectoryListingModel;
use App\Models\DirectoryListingTeamModel;
use App\Services\DirectoryAdminService;
use App\Services\DirectoryListingMutationService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use Tests\Support\ValidListingInput;
use Tests\Support\FreshDatabase;

/**
 * Each person's registration number, ORCID and ResearchGate (per person since
 * 4 Nov 2026, so Verified only like the team) on the owner and admin write
 * paths, and on the rendered profile. The rules themselves are pinned in
 * tests/unit/ProfessionalRegistrationTest.php.
 *
 * Requires the `tests` database group — see the Tests section of README.md.
 *
 * @internal
 */
final class ProfessionalRegistrationFlowTest extends CIUnitTestCase
{
    use ValidListingInput;
    use FreshDatabase;
    use FeatureTestTrait;

    private DirectoryListingModel $listings;
    private int $doctorCat;
    private int $plumberCat;

    protected function setUp(): void
    {
        parent::setUp();
        $this->listings  = new DirectoryListingModel();
        $cats            = new DirectoryCategoryModel();
        $this->doctorCat = (int) $cats->insert(['name' => 'General Practitioner', 'slug' => 'general-practitioner', 'group_name' => 'Health & Medical', 'is_active' => 1], true);
        $this->plumberCat = (int) $cats->insert(['name' => 'Plumber', 'slug' => 'plumber', 'group_name' => 'Home & Trades', 'is_active' => 1], true);
    }

    public function testOwnerSavesAPersonsRegistrationAndTheCardShowsIt(): void
    {
        $id     = $this->listing();
        $result = (new DirectoryListingMutationService())->updateOwn($id, $this->post(['team' => [[
            'name'                           => 'Dr Jane Surgeon',
            ProfessionalRegistration::MARKER => '1',
            'reg_number'                     => 'mp0123456',
            'orcid'                          => 'https://orcid.org/0000-0002-1825-0097',
            'researchgate_url'               => 'researchgate.net/profile/Josiah-Carberry',
        ]]]));
        $this->assertTrue($result['ok'], $result['message'] . json_encode($result['errors']));

        $member = $this->member($id);
        $this->assertSame('MP0123456', $member['reg_number']);
        $this->assertSame('0000-0002-1825-0097', $member['orcid']);

        $page = $this->get('directory/test-practice');
        $page->assertOK();
        $page->assertSee('MP0123456');
        $page->assertSee('self declared');
        $page->assertSee('https://orcid.org/0000-0002-1825-0097');
        $page->assertSee('"propertyID":"HPCSA"');
    }

    public function testWithoutTheBadgeNothingShows(): void
    {
        $id = $this->listing(['verified_until' => null]);
        $this->team($id, ['reg_number' => 'MP0123456']);

        $this->get('directory/test-practice')->assertDontSee('MP0123456');
    }

    public function testAPlumberNeverShowsAStoredNumber(): void
    {
        $id = $this->listing(['category_id' => $this->plumberCat]);
        $this->team($id, ['reg_number' => 'MP0123456']);

        $this->get('directory/test-practice')->assertDontSee('MP0123456');
    }

    public function testTheProfileHasNoRegistrationOfItsOwnAnyMore(): void
    {
        $id = $this->listing(['reg_number' => 'MP7777777', 'registration_visible' => 1]);
        (new DirectoryListingMutationService())->updateOwn($id, $this->post([
            ProfessionalRegistration::MARKER => '1',
            'reg_number'                     => 'MP0123456',
        ]));

        $this->assertSame('MP7777777', $this->listings->find($id)['reg_number'], 'the profile level fields are no longer written');
        $this->get('directory/test-practice')->assertDontSee('MP7777777');
    }

    public function testOwnerEditRefusesABadNumberAndOrcid(): void
    {
        $id     = $this->listing();
        $result = (new DirectoryListingMutationService())->updateOwn($id, $this->post(['team' => [[
            'name'                           => 'Dr Jane Surgeon',
            ProfessionalRegistration::MARKER => '1',
            'reg_number'                     => '0123456',
            'orcid'                          => '0000-0002-1825-0098',
        ]]]));

        $this->assertFalse($result['ok']);
        $this->assertArrayHasKey('team.0.reg_number', $result['errors']);
        $this->assertArrayHasKey('team.0.orcid', $result['errors']);
        $this->assertNull($this->member($id));
    }

    public function testOwnerCannotSetTheAdminCheck(): void
    {
        $id = $this->listing();
        (new DirectoryListingMutationService())->updateOwn($id, $this->post(['team' => [[
            'name'                           => 'Dr Jane Surgeon',
            ProfessionalRegistration::MARKER => '1',
            'reg_number'                     => 'MP0123456',
            'reg_checked_present'            => '1',
            'reg_checked'                    => '1',
            'reg_checked_at'                 => '2026-01-01 00:00:00',
        ]]]));

        $this->assertNull($this->member($id)['reg_checked_at']);
    }

    public function testARowWithoutTheMarkerLeavesTheNumberAlone(): void
    {
        $id  = $this->listing();
        $mid = $this->team($id, ['reg_number' => 'MP0123456']);
        $result = (new DirectoryListingMutationService())->updateOwn($id, $this->post(['team' => [[
            'id' => $mid, 'name' => 'Dr Jane Surgeon',
        ]]]));

        $this->assertTrue($result['ok'], $result['message']);
        $this->assertSame('MP0123456', $this->member($id)['reg_number']);
    }

    public function testAdminChecksAndAnOwnerChangeClearsIt(): void
    {
        $id  = $this->listing();
        $mid = $this->team($id, ['reg_number' => 'MP0123456']);

        $admin = (new DirectoryAdminService())->upsert($id, $this->post(['status' => 'published', 'team' => [[
            'id' => $mid, 'name' => 'Dr Jane Surgeon',
            ProfessionalRegistration::MARKER => '1', 'reg_number' => 'MP0123456',
            'reg_checked_present' => '1', 'reg_checked' => '1',
        ]]]));
        $this->assertTrue($admin['ok'], $admin['message'] . json_encode($admin['errors'] ?? []));
        $this->assertNotNull($this->member($id)['reg_checked_at']);
        $this->get('directory/test-practice')->assertSee('checked by');

        (new DirectoryListingMutationService())->updateOwn($id, $this->post(['team' => [[
            'id' => $mid, 'name' => 'Dr Jane Surgeon',
            ProfessionalRegistration::MARKER => '1', 'reg_number' => 'MP0999999',
        ]]]));
        $this->assertNull($this->member($id)['reg_checked_at']);
    }

    public function testTheFieldsSitInEachPersonRowForADoctorOnly(): void
    {
        $render = fn (int $cat, bool $admin = false): string => view('directory/_team_fields', [
            'rows'       => [['id' => 5, 'name' => 'Dr Jane Surgeon', 'reg_number' => 'MP0123456']],
            'err'        => static fn (string $f): string => '',
            'v'          => static fn (string $f): string => $f === 'category_id' ? (string) $cat : '',
            'categories' => (new DirectoryCategoryModel())->findAll(),
            'adminEdit'  => $admin,
        ], ['saveData' => false]);

        $doctor = $render($this->doctorCat);
        $this->assertStringContainsString('HPCSA</span> registration number', $doctor);
        $this->assertStringContainsString('name="team[0][reg_number]"', $doctor);
        $this->assertDoesNotMatchRegularExpression('/data-registration\s+hidden/', $doctor);
        $this->assertStringNotContainsString('reg_checked', $doctor, 'the check is admin only');
        $this->assertStringContainsString('name="team[0][reg_checked]"', $render($this->doctorCat, true));

        $this->assertMatchesRegularExpression('/data-registration\s+hidden disabled/', $render($this->plumberCat));
    }

    /** @return array<string,mixed>|null */
    private function member(int $listingId): ?array
    {
        return (new DirectoryListingTeamModel())->where('listing_id', $listingId)->first();
    }

    /** @param array<string,mixed> $fields */
    private function team(int $listingId, array $fields = []): int
    {
        return (int) (new DirectoryListingTeamModel())->insert($fields + [
            'listing_id' => $listingId,
            'name'       => 'Dr Jane Surgeon',
            'slug'       => 'dr-jane-surgeon',
        ], true);
    }

    /** @param array<string,mixed> $overrides */
    private function listing(array $overrides = []): int
    {
        return (int) $this->listings->insert($overrides + [
            'type'           => 'practice',
            'display_name'   => 'Test Practice',
            'email'          => 'practice@example.test',
            'slug'           => 'test-practice',
            'status'         => 'published',
            'category_id'    => $this->doctorCat,
            // The team is a Verified feature, and so is the registration.
            'verified_until' => date('Y-m-d', strtotime('+30 days')),
        ], true);
    }

    /**
     * A valid owner/admin submission; see BookingUrlTest::post().
     *
     * @param array<string,mixed> $fields
     * @return array<string,mixed>
     */
    private function post(array $fields = []): array
    {
        return $this->withRequiredSections($fields + [
            'display_name'   => 'Test Practice',
            'email'          => 'practice@example.test',
            'category_id'    => $this->doctorCat,
            'title'          => 'Dr',
            'contact_person' => 'Test Doctor',
            'latitude'       => '-33.9249',
            'longitude'      => '18.4241',
        ]);
    }
}

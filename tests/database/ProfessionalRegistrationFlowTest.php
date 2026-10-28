<?php

use App\Libraries\ProfessionalRegistration;
use App\Models\DirectoryCategoryModel;
use App\Models\DirectoryListingModel;
use App\Services\DirectoryAdminService;
use App\Services\DirectoryListingMutationService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Tests\Support\ValidListingInput;

/**
 * Registration number, ORCID and ResearchGate on the owner and admin write
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
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $namespace   = 'App';
    protected $migrate     = true;
    protected $migrateOnce = false;
    protected $refresh     = true;

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

    public function testOwnerSavesAndShowsTheRegistration(): void
    {
        $id     = $this->listing();
        $result = (new DirectoryListingMutationService())->updateOwn($id, $this->post([
            ProfessionalRegistration::MARKER => '1',
            'reg_number'           => 'mp0123456',
            'orcid'                => 'https://orcid.org/0000-0002-1825-0097',
            'researchgate_url'     => 'researchgate.net/profile/Josiah-Carberry',
            'registration_visible' => '1',
        ]));
        $this->assertTrue($result['ok'], $result['message'] . json_encode($result['errors']));

        $row = $this->listings->find($id);
        $this->assertSame('MP0123456', $row['reg_number']);
        $this->assertSame('0000-0002-1825-0097', $row['orcid']);
        $this->assertSame('1', (string) $row['registration_visible']);

        $page = $this->get('directory/test-doctor');
        $page->assertOK();
        $page->assertSee('MP0123456');
        $page->assertSee('self declared');
        $page->assertSee('https://orcid.org/0000-0002-1825-0097');
        $page->assertSee('"propertyID":"HPCSA"');
    }

    public function testSwitchedOffShowsNothing(): void
    {
        $this->listing(['reg_number' => 'MP0123456', 'registration_visible' => 0]);

        $page = $this->get('directory/test-doctor');
        $page->assertOK();
        $page->assertDontSee('MP0123456');
    }

    public function testAPlumberNeverShowsAStoredNumber(): void
    {
        $this->listing(['category_id' => $this->plumberCat, 'reg_number' => 'MP0123456', 'registration_visible' => 1]);

        $this->get('directory/test-doctor')->assertDontSee('MP0123456');
    }

    public function testOwnerEditRefusesABadNumberAndOrcid(): void
    {
        $id     = $this->listing();
        $result = (new DirectoryListingMutationService())->updateOwn($id, $this->post([
            ProfessionalRegistration::MARKER => '1',
            'reg_number' => '0123456',
            'orcid'      => '0000-0002-1825-0098',
        ]));

        $this->assertFalse($result['ok']);
        $this->assertArrayHasKey('reg_number', $result['errors']);
        $this->assertArrayHasKey('orcid', $result['errors']);
        $this->assertNull($this->listings->find($id)['reg_number']);
    }

    public function testOwnerCannotSetTheAdminCheck(): void
    {
        $id = $this->listing();
        (new DirectoryListingMutationService())->updateOwn($id, $this->post([
            ProfessionalRegistration::MARKER => '1',
            'reg_number'     => 'MP0123456',
            'reg_checked'    => '1',
            'reg_checked_present' => '1',
            'reg_checked_at' => '2026-01-01 00:00:00',
        ]));

        $this->assertNull($this->listings->find($id)['reg_checked_at']);
    }

    public function testAPostWithoutTheSectionLeavesItAlone(): void
    {
        $id = $this->listing(['reg_number' => 'MP0123456', 'registration_visible' => 1]);
        $result = (new DirectoryListingMutationService())->updateOwn($id, $this->post());

        $this->assertTrue($result['ok'], $result['message']);
        $row = $this->listings->find($id);
        $this->assertSame('MP0123456', $row['reg_number']);
        $this->assertSame('1', (string) $row['registration_visible']);
    }

    public function testAdminChecksAndAnOwnerChangeClearsIt(): void
    {
        $id = $this->listing(['reg_number' => 'MP0123456']);

        $admin = (new DirectoryAdminService())->upsert($id, $this->post([
            'status' => 'published', 'reg_checked_present' => '1', 'reg_checked' => '1',
        ]));
        $this->assertTrue($admin['ok'], $admin['message']);
        $this->assertNotNull($this->listings->find($id)['reg_checked_at']);

        (new DirectoryListingMutationService())->updateOwn($id, $this->post([
            ProfessionalRegistration::MARKER => '1',
            'reg_number' => 'MP0999999',
        ]));
        $this->assertNull($this->listings->find($id)['reg_checked_at']);
    }

    public function testFormShowsTheSectionForADoctorOnly(): void
    {
        $html = view('directory/_registration_fields', [
            'v'          => fn (string $f): string => $f === 'category_id' ? (string) $this->doctorCat : '',
            'err'        => static fn (string $f): string => '',
            'categories' => (new DirectoryCategoryModel())->findAll(),
        ]);
        $this->assertStringContainsString('HPCSA</span> registration number', $html);
        $this->assertDoesNotMatchRegularExpression('/<details[^>]*data-registration[^>]*hidden/', $html);

        $plumber = view('directory/_registration_fields', [
            'v'          => fn (string $f): string => $f === 'category_id' ? (string) $this->plumberCat : '',
            'err'        => static fn (string $f): string => '',
            'categories' => (new DirectoryCategoryModel())->findAll(),
        ]);
        $this->assertMatchesRegularExpression('/<fieldset class="disclosure-body"\s+disabled>/', $plumber);
    }

    /** @param array<string,mixed> $overrides */
    private function listing(array $overrides = []): int
    {
        return (int) $this->listings->insert($overrides + [
            'type'         => 'person',
            'display_name' => 'Test Doctor',
            'email'        => 'doctor@example.test',
            'slug'         => 'test-doctor',
            'status'       => 'published',
            'category_id'  => $this->doctorCat,
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
            'display_name'   => 'Test Doctor',
            'email'          => 'doctor@example.test',
            'category_id'    => $this->doctorCat,
            'title'          => 'Dr',
            'contact_person' => 'Test Doctor',
            'latitude'       => '-33.9249',
            'longitude'      => '18.4241',
        ]);
    }
}

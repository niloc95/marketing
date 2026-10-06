<?php

use App\Libraries\DomainChecker;
use App\Models\DirectoryCategoryModel;
use App\Models\DirectoryListingModel;
use App\Services\DirectoryAdminService;
use App\Services\DirectoryListingMutationService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;
use Tests\Support\ValidListingInput;

/**
 * The rules added after a spam profile ("Polokwane ➸ [+②⑦⑦…] A TRADITIONAL
 * HEALER…") went live, and after most owners were found skipping the
 * "Make your profile stand out" sections:
 *
 *  - the business name refuses spam shapes and has its capitals fixed;
 *  - the description is compulsory and tidied;
 *  - the website is upgraded to https and must exist in DNS;
 *  - title and position come from fixed lists;
 *  - opening hours (or "by appointment only") and one service are compulsory,
 *    and so is a picture when the controller says whether there is one.
 *
 * Held on signup and owner edit. Admin intake is exempt.
 *
 * Requires the `tests` database group — see the Tests section of README.md.
 *
 * @internal
 */
final class SignupQualityRulesTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;
    use ValidListingInput;

    protected $namespace   = 'App';
    protected $migrate     = true;
    protected $migrateOnce = false;
    protected $refresh     = true;

    private DirectoryListingMutationService $svc;
    private DirectoryListingModel $listings;
    private int $categoryId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc      = new DirectoryListingMutationService();
        $this->listings = new DirectoryListingModel();

        $this->categoryId = (int) (new DirectoryCategoryModel())->insert([
            'name'       => 'Plumbers',
            'slug'       => 'plumbers',
            'group_name' => 'Home Services',
            'is_active'  => 1,
        ], true);
    }

    protected function tearDown(): void
    {
        Services::resetSingle('domainChecker');
        parent::tearDown();
    }

    // ------------------------------------------------------------- the name

    public function testTheProductionSpamNameIsRefused(): void
    {
        $result = $this->svc->submitPublic($this->signup([
            'display_name' => 'Polokwane ➸ [+②⑦⑦①③③⑥③⓪④⑦]”➸ A TRADITIONAL HEALER /SANGOMA /LOVE SPELLS in Polokwane, Mankweng, Tzaneen, Giyani',
        ]));

        $this->assertFalse($result['ok']);
        $this->assertArrayHasKey('display_name', $result['errors']);
        $this->assertSame(0, $this->listings->countAllResults());
    }

    public function testAShoutingNameIsSavedInTitleCase(): void
    {
        $result = $this->svc->submitPublic($this->signup(['display_name' => 'FLOW PLUMBING CC']));

        $this->assertTrue($result['ok'], json_encode($result['errors']));
        $row = $this->listings->find((int) $result['id']);
        $this->assertSame('Flow Plumbing CC', $row['display_name']);
        $this->assertSame('flow-plumbing-cc', $row['slug']);
    }

    public function testAStoredNameFromBeforeTheRulesStillSaves(): void
    {
        $id = $this->legacyListing(['display_name' => 'Old [Legacy] Plumbing']);

        $result = $this->svc->updateOwn($id, $this->edit(['display_name' => 'Old [Legacy] Plumbing']));
        $this->assertTrue($result['ok'], json_encode($result['errors']));

        $renamed = $this->svc->updateOwn($id, $this->edit(['display_name' => 'New [Legacy] Plumbing']));
        $this->assertArrayHasKey('display_name', $renamed['errors'], 'a changed name meets the rules');
    }

    // ------------------------------------------------------ the description

    public function testTheDescriptionIsCompulsory(): void
    {
        $result = $this->svc->submitPublic($this->signup(['description' => '']));
        $this->assertArrayHasKey('description', $result['errors']);

        $short = $this->svc->submitPublic($this->signup(['description' => 'Plumber.']));
        $this->assertArrayHasKey('description', $short['errors']);
    }

    public function testAShoutingDescriptionIsSavedInSentenceCase(): void
    {
        $result = $this->svc->submitPublic($this->signup([
            'description' => 'WE FIX GEYSERS AND BURST PIPES FAST. AVAILABLE ACROSS CAPE TOWN ➸ CALL US!',
        ]));

        $this->assertTrue($result['ok'], json_encode($result['errors']));
        $row = $this->listings->find((int) $result['id']);
        $this->assertSame('We fix geysers and burst pipes fast. Available across cape town call us!', $row['description_text']);
    }

    // ---------------------------------------------------------- the website

    public function testHttpIsUpgradedAndABareHostGetsHttps(): void
    {
        $this->assertSame('https://example.co.za/Path', $this->svc->normaliseUrl('http://EXAMPLE.co.za/Path'));
        $this->assertSame('https://example.co.za', $this->svc->normaliseUrl('example.co.za'));
        $this->assertNull($this->svc->normaliseUrl('https://localhost'));
        $this->assertNull($this->svc->normaliseUrl('https://10.0.0.1'));
        $this->assertNull($this->svc->normaliseUrl('https://mysite'));
        $this->assertNull($this->svc->normaliseUrl('https://user:pw@example.co.za'));
    }

    public function testAWebsiteWhoseDomainDoesNotExistIsRefused(): void
    {
        Services::injectMock('domainChecker', new class () extends DomainChecker {
            public function exists(string $host): bool
            {
                return $host !== 'nosuchdomain-xyz.co.za';
            }
        });

        $bad = $this->svc->submitPublic($this->signup(['website' => 'nosuchdomain-xyz.co.za']));
        $this->assertArrayHasKey('website', $bad['errors']);

        $good = $this->svc->submitPublic($this->signup(['website' => 'http://flowplumbing.co.za']));
        $this->assertTrue($good['ok'], json_encode($good['errors']));
        $this->assertSame('https://flowplumbing.co.za', $this->listings->find((int) $good['id'])['website']);
    }

    // ----------------------------------------------------- title & position

    public function testTitleAndPositionComeFromTheLists(): void
    {
        $result = $this->svc->submitPublic($this->signup(['title' => 'King', 'position' => 'Boss', 'email' => 'lists@example.test']));

        $this->assertArrayHasKey('title', $result['errors']);
        $this->assertArrayHasKey('position', $result['errors']);

        $ok = $this->svc->submitPublic($this->signup(['title' => 'Adv', 'position' => 'Managing Director']));
        $this->assertTrue($ok['ok'], json_encode($ok['errors']));
        $this->assertSame('Managing Director', $this->listings->find((int) $ok['id'])['position']);
    }

    public function testALegacyTitleSurvivesAnEditThatLeavesItAlone(): void
    {
        $id = $this->legacyListing(['title' => 'Pastor']);

        $result = $this->svc->updateOwn($id, $this->edit(['title' => 'Pastor']));

        $this->assertTrue($result['ok'], json_encode($result['errors']));
        $this->assertSame('Pastor', $this->listings->find($id)['title']);
    }

    // --------------------------------------------------- required sections

    public function testOpeningHoursAreCompulsory(): void
    {
        $result = $this->svc->submitPublic($this->signup(['hours' => ['mon' => ['open' => '08:00']]]));

        $this->assertArrayHasKey('hours', $result['errors']);
    }

    public function testByAppointmentOnlyAnswersTheHoursQuestion(): void
    {
        $result = $this->svc->submitPublic($this->signup(['hours' => [], 'by_appointment' => '1']));

        $this->assertTrue($result['ok'], json_encode($result['errors']));
        $this->assertSame('1', (string) $this->listings->find((int) $result['id'])['by_appointment']);
    }

    public function testAtLeastOneServiceIsCompulsory(): void
    {
        $result = $this->svc->submitPublic($this->signup(['services' => [['name' => 'Leaks', '_remove' => '1'], ['name' => '  ']]]));

        $this->assertArrayHasKey('services', $result['errors']);
    }

    public function testAPictureIsCompulsoryWhenTheControllerSaysThereIsNone(): void
    {
        $this->assertArrayHasKey('photo', $this->svc->submitPublic($this->signup(['_has_photo' => false]))['errors']);
        $this->assertTrue($this->svc->submitPublic($this->signup(['_has_photo' => true]))['ok']);
    }

    public function testTheSignupFormRefusesASaveWithNoPicture(): void
    {
        // The controller decides _has_photo from the actual upload, and
        // overwrites whatever the form posted — so a forged "yes" is ignored.
        $this->withSession(['listing_form_rendered_at' => time() - 60])
            ->post('add-profile', $this->signup([
                '_has_photo'   => '1',
                csrf_token()   => csrf_hash(),
            ]))
            ->assertRedirect();

        $this->assertArrayHasKey('photo', (array) session()->getFlashdata('errors'));
        $this->assertSame(0, $this->listings->countAllResults());
    }

    public function testAnOwnerEditMustFillTheSectionsIn(): void
    {
        $id = $this->legacyListing();

        $result = $this->svc->updateOwn($id, $this->edit([
            'hours'       => [],
            'services'    => [],
            'description' => '',
        ]));

        $this->assertFalse($result['ok']);
        $this->assertArrayHasKey('hours', $result['errors']);
        $this->assertArrayHasKey('services', $result['errors']);
        $this->assertArrayHasKey('description', $result['errors']);
    }

    public function testAdminIntakeIsExempt(): void
    {
        $result = (new DirectoryAdminService())->upsert(null, [
            'display_name' => 'Imported Plumbing',
            'category_id'  => $this->categoryId,
            'status'       => 'published',
        ]);

        $this->assertTrue($result['ok'], $result['message']);
    }

    // -------------------------------------------------------------- fixtures

    /**
     * @param array<string,mixed> $overrides
     * @return array<string,mixed>
     */
    private function signup(array $overrides = []): array
    {
        return $this->withRequiredSections($overrides + [
            'display_name'     => 'Flow Plumbing',
            'email'            => 'quality-' . bin2hex(random_bytes(4)) . '@example.test',
            'category_id'      => $this->categoryId,
            'contact_person'   => 'Thandi Mokoena',
            'consent'          => 1,
            'marketing_opt_in' => '0',
            'address_line'     => '1 Adderley Street',
            'city'             => 'Cape Town',
            'postal_code'      => '8001',
            'province'         => 'Western Cape',
            'latitude'         => '-33.9249',
            'longitude'        => '18.4241',
        ]);
    }

    /**
     * @param array<string,mixed> $overrides
     * @return array<string,mixed>
     */
    private function edit(array $overrides = []): array
    {
        return $this->withRequiredSections($overrides + [
            'display_name'   => 'Legacy Plumbing',
            'category_id'    => $this->categoryId,
            'contact_person' => 'Thandi Mokoena',
        ]);
    }

    /** A published row written straight to the table, as the listings from before the rules were. */
    private function legacyListing(array $columns = []): int
    {
        return (int) $this->listings->insert($columns + [
            'type'           => 'practice',
            'display_name'   => 'Legacy Plumbing',
            'email'          => 'legacy-' . bin2hex(random_bytes(4)) . '@example.test',
            'slug'           => 'legacy-plumbing-' . bin2hex(random_bytes(2)),
            'category_id'    => $this->categoryId,
            'title'          => 'Mr',
            'contact_person' => 'Sipho Dlamini',
            'status'         => 'published',
            'is_verified'    => 1,
            'country'        => 'South Africa',
        ], true);
    }
}

<?php

use App\Models\DirectoryCategoryModel;
use App\Models\DirectoryListingModel;
use App\Services\DirectoryAdminService;
use App\Services\DirectoryListingMutationService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * The private "Your details" pair — title and contact person — is compulsory
 * wherever the business itself is filling the form in.
 *
 * Neither is published: _contact_panel.php renders neither, and
 * ListingQualityService scores neither, so nothing about this rule is visible
 * to a searcher. It exists because everything this directory sends about a
 * listing is addressed from them — MarketingConsentService pushes
 * contact_person as the Mautic firstname — and a listing nobody here can
 * address by name cannot be checked against anything but its own claims.
 *
 * Two asymmetries are the point, and both are the sort of thing a later
 * "consistency" pass would flatten:
 *
 *  - Unlike REQUIRED_ADDRESS_FIELDS, this holds on an owner edit as well as at
 *    signup. The address rule exempts edits because a listing that predates it
 *    may have no address to hand; an owner always has their own name.
 *  - Admin intake is exempt, because DirectoryAdminService is a different
 *    write path: imports and phone captures are entered before anyone has said
 *    who to ask for.
 *
 * Requires the `tests` database group — see the Tests section of README.md.
 *
 * @internal
 */
final class PrivateDetailsRequiredTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

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

    // --------------------------------------------------------- public signup

    public function testASignupWithNeitherIsRefused(): void
    {
        $result = $this->svc->submitPublic($this->signup(['title' => '', 'contact_person' => '']));

        $this->assertFalse($result['ok']);
        // Both are named, not just the first — one round trip, not two.
        $this->assertArrayHasKey('title', $result['errors']);
        $this->assertArrayHasKey('contact_person', $result['errors']);
        $this->assertSame(0, $this->listings->countAllResults());
    }

    public function testWhitespaceIsNotAName(): void
    {
        $result = $this->svc->submitPublic($this->signup(['contact_person' => "   \t "]));

        $this->assertFalse($result['ok']);
        $this->assertArrayHasKey('contact_person', $result['errors']);
    }

    public function testACompleteSignupIsAccepted(): void
    {
        $result = $this->svc->submitPublic($this->signup());

        $this->assertTrue($result['ok'], $result['message']);
        $row = $this->listings->find((int) $result['id']);
        $this->assertSame('Mrs', $row['title']);
        $this->assertSame('Thandi Mokoena', $row['contact_person']);
    }

    // ------------------------------------------------------------ owner edit

    public function testAnOwnerCannotBlankThem(): void
    {
        $created = $this->svc->submitPublic($this->signup());
        $id      = (int) $created['id'];

        $result = $this->svc->updateOwn($id, [
            'display_name'   => 'Flow Plumbing',
            'category_id'    => $this->categoryId,
            'title'          => '',
            'contact_person' => '',
            'phone'          => '021 555 0100',
        ]);

        $this->assertFalse($result['ok']);
        $this->assertArrayHasKey('title', $result['errors']);
        $this->assertArrayHasKey('contact_person', $result['errors']);
        $this->assertNotSame('021 555 0100', (string) $this->listings->find($id)['phone'], 'the whole save is refused, not just the pair');
    }

    public function testAListingThatPredatesTheRuleIsAskedForThemOnItsNextEdit(): void
    {
        // The deliberate difference from RequiredAddressTest: a legacy row is
        // NOT waved through here. Its owner types their own name once, which
        // is how these rows get backfilled at all.
        $id = (int) $this->listings->insert([
            'type'         => 'practice',
            'display_name' => 'Legacy Plumbing',
            'email'        => 'legacy@example.test',
            'slug'         => 'legacy-plumbing',
            'status'       => 'published',
            'category_id'  => $this->categoryId,
        ], true);

        $refused = $this->svc->updateOwn($id, [
            'display_name' => 'Legacy Plumbing',
            'category_id'  => $this->categoryId,
            'phone'        => '021 555 0100',
        ]);
        $this->assertFalse($refused['ok']);

        $saved = $this->svc->updateOwn($id, [
            'display_name'   => 'Legacy Plumbing',
            'category_id'    => $this->categoryId,
            'title'          => 'Mr',
            'contact_person' => 'Sipho Dlamini',
            'phone'          => '021 555 0100',
        ]);

        $this->assertTrue($saved['ok'], $saved['message']);
        $this->assertSame('Sipho Dlamini', $this->listings->find($id)['contact_person']);
    }

    // ----------------------------------------------------------- admin intake

    public function testAdminMayStillEnterAListingWithNeither(): void
    {
        $result = (new DirectoryAdminService())->upsert(null, [
            'display_name' => 'Imported Plumbing',
            'category_id'  => $this->categoryId,
            'email'        => 'imported@example.test',
            'status'       => 'published',
        ]);

        $this->assertTrue($result['ok'], $result['message'] ?? '');
    }

    // ------------------------------------------------------------ the forms

    public function testSignupAndTheOwnerFormMarkThemRequiredButAdminDoesNot(): void
    {
        $id = (int) $this->listings->insert([
            'type'         => 'practice',
            'display_name' => 'Legacy Plumbing',
            'email'        => 'legacy@example.test',
            'slug'         => 'legacy-plumbing',
            'status'       => 'published',
            'category_id'  => $this->categoryId,
        ], true);

        foreach ([
            $this->get('add-listing'),
            $this->withSession([\App\Controllers\Manage::SESSION_KEY => $id])->get('manage/edit'),
        ] as $result) {
            $result->assertOK();
            $this->assertStringContainsString('<label>Title *</label>', $result->getBody());
            $this->assertStringContainsString('<label>Contact person *</label>', $result->getBody());
        }

        // Admin passes privateDetailsRequired false explicitly. If it ever
        // stops doing so it inherits the signup form's value through CI4's
        // shared view data and starts demanding a contact name on an import.
        $admin = $this->withSession(['dir_admin' => true])->get('admin/edit/' . $id);
        $admin->assertOK();
        $this->assertStringContainsString('<label>Title</label>', $admin->getBody());
        $this->assertStringNotContainsString('<label>Title *</label>', $admin->getBody());
        $this->assertStringNotContainsString('<label>Contact person *</label>', $admin->getBody());
    }

    // -------------------------------------------------------------- fixtures

    /**
     * Valid public-form input. The coordinates keep ListingGeocoder off the
     * network and match the Cape Town address — see RequiredAddressTest.
     *
     * @param array<string,mixed> $overrides
     * @return array<string,mixed>
     */
    private function signup(array $overrides = []): array
    {
        return array_merge([
            'display_name'   => 'Flow Plumbing',
            'email'          => 'private-' . bin2hex(random_bytes(4)) . '@example.test',
            'category_id'    => $this->categoryId,
            'title'          => 'Mrs',
            'contact_person' => 'Thandi Mokoena',
            'consent'        => 1,
            'marketing_opt_in' => '0',
            'address_line'   => '1 Adderley Street',
            'city'           => 'Cape Town',
            'postal_code'    => '8001',
            'province'       => 'Western Cape',
            'latitude'       => '-33.9249',
            'longitude'      => '18.4241',
        ], $overrides);
    }
}

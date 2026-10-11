<?php

use App\Models\DirectoryCategoryModel;
use App\Models\DirectoryListingModel;
use App\Services\DirectoryListingMutationService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Tests\Support\ValidListingInput;

/**
 * A business's website is never an address on this site.
 *
 * cpt-dental went live with https://local.webscheduler.co.za/add-profile as
 * its website: the profile linked back to the signup page, and the link sat in
 * its JSON-LD sameAs. These pin both halves of the fix: such a link is saved
 * as blank on every write path, and a row already holding one shows and counts
 * no website.
 *
 * Requires the `tests` database group — see the Tests section of README.md.
 *
 * @internal
 */
final class OwnSiteWebsiteTest extends CIUnitTestCase
{
    use ValidListingInput;
    use DatabaseTestTrait;

    protected $namespace   = 'App';
    protected $migrate     = true;
    protected $migrateOnce = false;
    protected $refresh     = true;

    private DirectoryListingModel $listings;
    private int $categoryId;

    protected function setUp(): void
    {
        parent::setUp();
        helper(['directory_ui', 'schema']);
        $this->listings   = new DirectoryListingModel();
        $this->categoryId = (int) (new DirectoryCategoryModel())->insert([
            'name'       => 'Dentists',
            'slug'       => 'dentists',
            'group_name' => 'Health',
            'is_active'  => 1,
        ], true);
    }

    public function testOurOwnHostsAreRecognised(): void
    {
        $this->assertTrue(is_own_site_url('https://local.webscheduler.co.za/add-profile'));
        $this->assertTrue(is_own_site_url('https://LOCAL.webscheduler.co.za/directory/cpt-dental'));
        $this->assertTrue(is_own_site_url('https://listing.webscheduler.co.za/directory/x'));
        $this->assertTrue(is_own_site_url(base_url('directory/x')));

        $this->assertFalse(is_own_site_url('https://dental.webscheduler.co.za'), 'a booking subdomain is the business’s own');
        $this->assertFalse(is_own_site_url('https://www.example.co.za'));
        $this->assertFalse(is_own_site_url(''));
    }

    public function testSignupSavesASelfLinkAsBlank(): void
    {
        $result = (new DirectoryListingMutationService())->submitPublic($this->signup([
            'website' => 'https://local.webscheduler.co.za/add-profile',
        ]));

        $this->assertTrue($result['ok'], $result['message']);
        $this->assertSame('', (string) $this->listings->find((int) $result['id'])['website']);
    }

    public function testOwnerEditSavesASelfLinkAsBlankAndKeepsARealOne(): void
    {
        $id  = $this->listing();
        $svc = new DirectoryListingMutationService();

        $self = $svc->updateOwn($id, $this->post(['website' => 'local.webscheduler.co.za/directory/test-dental']));
        $this->assertTrue($self['ok'], $self['message']);
        $this->assertSame('', (string) $this->listings->find($id)['website']);

        $real = $svc->updateOwn($id, $this->post(['website' => 'www.testdental.co.za']));
        $this->assertTrue($real['ok'], $real['message']);
        $this->assertSame('https://www.testdental.co.za', $this->listings->find($id)['website']);
    }

    public function testAStoredSelfLinkIsNeitherShownNorCounted(): void
    {
        $this->assertSame('', listing_website_url('https://local.webscheduler.co.za/add-profile'));
        $this->assertSame('https://www.testdental.co.za', listing_website_url('https://www.testdental.co.za'));

        $schema = json_encode(schema_local_business($this->listings->find($this->listing([
            'website' => 'https://local.webscheduler.co.za/add-profile',
        ])), base_url('directory/test-dental')));
        $this->assertStringNotContainsString('add-profile', (string) $schema);
    }

    // -------------------------------------------------------------- fixtures

    /** @param array<string,mixed> $overrides */
    private function listing(array $overrides = []): int
    {
        return (int) $this->listings->insert($overrides + [
            'type'         => 'practice',
            'display_name' => 'Test Dental',
            'email'        => 'dental@example.test',
            'slug'         => 'test-dental',
            'status'       => 'published',
            'category_id'  => $this->categoryId,
        ], true);
    }

    /**
     * A valid owner submission; see BookingUrlTest::post().
     *
     * @param array<string,mixed> $fields
     *
     * @return array<string,mixed>
     */
    private function post(array $fields = []): array
    {
        return $this->withRequiredSections($fields + [
            'display_name'   => 'Test Dental',
            'email'          => 'dental@example.test',
            'category_id'    => $this->categoryId,
            'title'          => 'Dr',
            'contact_person' => 'Test Owner',
            'latitude'       => '-33.9249',
            'longitude'      => '18.4241',
        ]);
    }

    /**
     * A valid public signup; see BookingUrlTest::signup().
     *
     * @param array<string,mixed> $overrides
     *
     * @return array<string,mixed>
     */
    private function signup(array $overrides = []): array
    {
        return $this->withRequiredSections(array_merge([
            'address_line'     => '1 Adderley Street',
            'city'             => 'Cape Town',
            'postal_code'      => '8001',
            'province'         => 'Western Cape',
            'display_name'     => 'Self Link Dental',
            'email'            => 'self-' . bin2hex(random_bytes(4)) . '@example.test',
            'category_id'      => $this->categoryId,
            'title'            => 'Dr',
            'contact_person'   => 'Test Owner',
            'consent'          => 1,
            'marketing_opt_in' => '0',
            'latitude'         => '-33.9249',
            'longitude'        => '18.4241',
        ], $overrides));
    }
}

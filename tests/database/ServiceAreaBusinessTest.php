<?php

use App\Libraries\ListingGeocoder;
use App\Libraries\ServiceArea;
use App\Models\DirectoryCategoryModel;
use App\Models\DirectoryListingModel;
use App\Services\DirectoryAdminService;
use App\Services\DirectoryListingMutationService;
use App\Services\DirectoryService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Tests\Support\ValidListingInput;

/**
 * Mobile / service-area businesses: "address required" and "address publicly
 * visible" are two different things.
 *
 * What these pin: the address stays required and stored whatever the owner
 * picks; only a business that travels to customers can hide it; a hidden
 * address reaches no public surface (profile HTML, JSON-LD, map JSON, search
 * by suburb or by position); and a listing that never touches the new settings
 * renders exactly as before.
 *
 * Requires the `tests` database group — see the Tests section of README.md.
 *
 * @internal
 */
final class ServiceAreaBusinessTest extends CIUnitTestCase
{
    use ValidListingInput;
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $namespace   = 'App';
    protected $migrate     = true;
    protected $migrateOnce = false;
    protected $refresh     = true;

    private const LAT = '-26.0920';
    private const LNG = '27.9810';

    private DirectoryListingModel $listings;
    private int $categoryId;

    protected function setUp(): void
    {
        parent::setUp();
        helper('directory_ui');
        $this->listings   = new DirectoryListingModel();
        $this->categoryId = (int) (new DirectoryCategoryModel())->insert([
            'name'       => 'Cleaning Services',
            'slug'       => 'cleaning-services',
            'group_name' => 'Home Services',
            'is_active'  => 1,
        ], true);
    }

    // --------------------------------------------------------- defaults

    public function testAnExistingListingKeepsItsAddressMapAndDirections(): void
    {
        $id   = $this->listing();
        $row  = $this->listings->find($id);
        $html = $this->profile();

        $this->assertSame('visit', $row['customer_location']);
        $this->assertSame('1', (string) $row['show_address']);
        $this->assertStringContainsString('15 Tin Road', $html);
        $this->assertStringContainsString('data-map-view', $html);
        $this->assertStringContainsString('google.com/maps/dir', $html);
        $this->assertStringContainsString('"streetAddress":"15 Tin Road"', $html);
        $this->assertStringNotContainsString('we travel to you', $html);
    }

    // -------------------------------------------------- hidden address

    public function testAHiddenAddressReachesNoPartOfTheProfile(): void
    {
        $this->listing([
            'customer_location' => 'travel',
            'show_address'      => 0,
            'service_areas'     => "Randburg\nSandton\nFourways",
        ]);
        $html = $this->profile();

        foreach (['15 Tin Road', 'Bromhof', '2188', self::LAT, self::LNG, 'data-map-view', 'maps/dir', 'waze.com', 'streetAddress', '"geo"'] as $leak) {
            $this->assertStringNotContainsString($leak, $html, $leak . ' leaked');
        }
        $this->assertStringContainsString('Mobile service: we travel to you', $html);
        $this->assertStringContainsString('Randburg, Sandton and Fourways', $html);
        $this->assertStringContainsString('"areaServed":[{"@type":"Place","name":"Randburg"}', $html);
        // City and province stay — the locality line and landing pages need them.
        $this->assertStringContainsString('"addressLocality":"Randburg"', $html);
    }

    public function testTheStoredAddressIsUntouchedByHiding(): void
    {
        $id = $this->listing();
        $ok = (new DirectoryListingMutationService())->updateOwn($id, $this->post([
            'customer_location' => 'travel',
            'show_address'      => '',
            'service_areas'     => 'Randburg, Sandton',
        ]));

        $this->assertTrue($ok['ok'], $ok['message']);
        $row = $this->listings->find($id);
        $this->assertSame('0', (string) $row['show_address']);
        $this->assertSame('15 Tin Road', $row['address_line']);
        $this->assertSame('Bromhof', $row['suburb']);
        $this->assertSame('2188', $row['postal_code']);
        $this->assertSame("Randburg\nSandton", $row['service_areas']);
    }

    public function testOnlyABusinessThatTravelsCanHideItsAddress(): void
    {
        $svc = new DirectoryListingMutationService();
        foreach (['visit', 'both'] as $location) {
            $id = $this->listing(['slug' => 'crew-' . $location, 'email' => $location . '@example.test']);
            $ok = $svc->updateOwn($id, $this->post([
                'email'             => $location . '@example.test',
                'customer_location' => $location,
                'show_address'      => '',
            ]));
            $this->assertTrue($ok['ok'], $ok['message']);
            $this->assertSame('1', (string) $this->listings->find($id)['show_address'], $location);
        }
    }

    public function testAPostWithoutTheSectionLeavesAHiddenAddressHidden(): void
    {
        $id   = $this->listing(['customer_location' => 'travel', 'show_address' => 0]);
        $post = $this->post();
        unset($post[ServiceArea::MARKER], $post['customer_location'], $post['show_address'], $post['service_areas']);

        $ok = (new DirectoryListingMutationService())->updateOwn($id, $post);

        $this->assertTrue($ok['ok'], $ok['message']);
        $this->assertSame('0', (string) $this->listings->find($id)['show_address']);
    }

    public function testTheAddressIsStillRequiredForAMobileSignup(): void
    {
        $result = (new DirectoryListingMutationService())->submitPublic($this->signup([
            'address_line'      => '',
            'customer_location' => 'travel',
            'show_address'      => '',
        ]));

        $this->assertFalse($result['ok']);
        $this->assertArrayHasKey('address_line', $result['errors']);
    }

    public function testSignupStoresTheSettings(): void
    {
        $result = (new DirectoryListingMutationService())->submitPublic($this->signup([
            'customer_location' => 'travel',
            'show_address'      => '',
            'service_areas'     => "Randburg\nrandburg\n Sandton ",
        ]));

        $this->assertTrue($result['ok'], $result['message']);
        $row = $this->listings->find((int) $result['id']);
        $this->assertSame('travel', $row['customer_location']);
        $this->assertSame('0', (string) $row['show_address']);
        $this->assertSame("Randburg\nSandton", $row['service_areas']);
        $this->assertSame('15 Tin Road', $row['address_line']);
    }

    public function testServiceAreasRefuseSpamAndTheCap(): void
    {
        $svc = new DirectoryListingMutationService();
        $id  = $this->listing();

        foreach (['www.spam.test', 'Call 082 123 4567', implode("\n", range(1, 16))] as $bad) {
            $result = $svc->updateOwn($id, $this->post(['service_areas' => $bad]));
            $this->assertFalse($result['ok'], $bad);
            $this->assertArrayHasKey('service_areas', $result['errors']);
        }
    }

    public function testOwnerCannotSlipProtectedFieldsInBesideTheSettings(): void
    {
        $id = $this->listing(['status' => 'pending']);
        (new DirectoryListingMutationService())->updateOwn($id, $this->post([
            'customer_location' => 'travel',
            'status'            => 'published',
            'slug'              => 'hijacked',
            'email'             => 'attacker@example.test',
        ]));

        $row = $this->listings->find($id);
        $this->assertSame('pending', $row['status']);
        $this->assertSame('the-home-crew', $row['slug']);
        $this->assertSame('crew@example.test', $row['email']);
    }

    public function testAdminAppliesTheSameRules(): void
    {
        $id  = $this->listing();
        $svc = new DirectoryAdminService();

        $bad = $svc->upsert($id, $this->post(['service_areas' => 'https://spam.test']));
        $this->assertFalse($bad['ok']);

        $ok = $svc->upsert($id, $this->post(['customer_location' => 'both', 'show_address' => '']));
        $this->assertTrue($ok['ok'], $ok['message'] ?? '');
        $this->assertSame('1', (string) $this->listings->find($id)['show_address']);
    }

    // ----------------------------------------------- search and the map

    public function testAHiddenListingHasNoMapPinAndIsNotFoundByPosition(): void
    {
        $id = $this->listing(['customer_location' => 'travel', 'show_address' => 0]);
        (new ListingGeocoder())->syncPoint($id, ['latitude' => self::LAT, 'longitude' => self::LNG]);

        $svc = new DirectoryService();
        $this->assertSame([], $svc->mapPoints(['bounds' => '-27,27,-25,29'], 200));

        $this->listings->update($id, ['show_address' => 1]);
        $this->assertCount(1, $svc->mapPoints(['bounds' => '-27,27,-25,29'], 200));
    }

    public function testServiceAreasAreSearchableButAHiddenSuburbIsNot(): void
    {
        $this->listing([
            'customer_location' => 'travel',
            'show_address'      => 0,
            'service_areas'     => "Sandton\nFourways",
        ]);
        $svc = new DirectoryService();

        $this->assertSame(1, (int) $svc->browse(['q' => 'Sandton'])['total']);
        $this->assertSame(0, (int) $svc->browse(['q' => 'Bromhof'])['total']);
    }

    // ------------------------------------------------------------ helpers

    private function profile(): string
    {
        return (string) $this->get('directory/the-home-crew')->response()->getBody();
    }

    /** @param array<string,mixed> $overrides */
    private function listing(array $overrides = []): int
    {
        return (int) $this->listings->insert($overrides + [
            'type'              => 'practice',
            'display_name'      => 'The Home Crew',
            'email'             => 'crew@example.test',
            'slug'              => 'the-home-crew',
            'status'            => 'published',
            'category_id'       => $this->categoryId,
            'address_line'      => '15 Tin Road',
            'suburb'            => 'Bromhof',
            'city'              => 'Randburg',
            'province'          => 'Gauteng',
            'postal_code'       => '2188',
            'latitude'          => self::LAT,
            'longitude'         => self::LNG,
            'geocode_precision' => 'exact',
        ], true);
    }

    /**
     * A valid owner/admin submission, with the service-area section posted.
     *
     * @param array<string,mixed> $fields
     * @return array<string,mixed>
     */
    private function post(array $fields = []): array
    {
        return $this->withRequiredSections($fields + [
            'display_name'      => 'The Home Crew',
            'email'             => 'crew@example.test',
            'category_id'       => $this->categoryId,
            'title'             => 'Mr',
            'contact_person'    => 'Test Owner',
            'address_line'      => '15 Tin Road',
            'suburb'            => 'Bromhof',
            'city'              => 'Randburg',
            'province'          => 'Gauteng',
            'postal_code'       => '2188',
            'latitude'          => self::LAT,
            'longitude'         => self::LNG,
            ServiceArea::MARKER => '1',
            'customer_location' => 'visit',
            'show_address'      => '1',
            'service_areas'     => '',
        ]);
    }

    /**
     * @param array<string,mixed> $overrides
     * @return array<string,mixed>
     */
    private function signup(array $overrides = []): array
    {
        return array_merge($this->post(), [
            'email'            => 'crew-' . bin2hex(random_bytes(4)) . '@example.test',
            'consent'          => 1,
            'marketing_opt_in' => '0',
        ], $overrides);
    }
}

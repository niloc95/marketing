<?php

use App\Models\DirectoryCategoryModel;
use App\Models\DirectoryListingModel;
use App\Services\DirectoryAdminService;
use App\Services\DirectoryListingMutationService;
use CodeIgniter\Test\CIUnitTestCase;
use Tests\Support\FreshDatabase;
use Tests\Support\ValidListingInput;

/**
 * The primary location's own name (`location_name`, "Branch or practice name"
 * in section 4 of the form), on all three write paths. It is the counterpart
 * of a branch row's name, and profile_location_label() shows it on the
 * profile; LocationFormRenderTest pins the form side.
 *
 * @internal
 */
final class PrimaryLocationNameTest extends CIUnitTestCase
{
    use ValidListingInput;
    use FreshDatabase;

    private DirectoryListingModel $listings;
    private int $categoryId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->listings   = new DirectoryListingModel();
        $this->categoryId = (int) (new DirectoryCategoryModel())->insert([
            'name'       => 'Dentists',
            'slug'       => 'dentists',
            'group_name' => 'Health',
            'is_active'  => 1,
        ], true);
    }

    public function testSignupStoresTheName(): void
    {
        $result = (new DirectoryListingMutationService())->submitPublic($this->input([
            'email'         => 'loc-' . bin2hex(random_bytes(4)) . '@example.test',
            'consent'       => 1,
            'marketing_opt_in' => '0',
            'address_line'  => '1 Adderley Street',
            'city'          => 'Cape Town',
            'postal_code'   => '8001',
            'province'      => 'Western Cape',
            'location_name' => '  Main practice ',
        ]));

        $this->assertTrue($result['ok'], $result['message']);
        $this->assertSame('Main practice', $this->listings->find((int) $result['id'])['location_name']);
    }

    public function testSignupRefusesAnOverlongName(): void
    {
        $result = (new DirectoryListingMutationService())->submitPublic($this->input([
            'email'         => 'loc-' . bin2hex(random_bytes(4)) . '@example.test',
            'consent'       => 1,
            'marketing_opt_in' => '0',
            'address_line'  => '1 Adderley Street',
            'city'          => 'Cape Town',
            'postal_code'   => '8001',
            'province'      => 'Western Cape',
            'location_name' => str_repeat('a', 201),
        ]));

        $this->assertFalse($result['ok']);
        $this->assertArrayHasKey('location_name', $result['errors']);
    }

    public function testOwnerCanSetAndClearTheName(): void
    {
        $id  = $this->listing();
        $svc = new DirectoryListingMutationService();

        $set = $svc->updateOwn($id, $this->input(['location_name' => 'Sandton branch']));
        $this->assertTrue($set['ok'], $set['message']);
        $this->assertSame('Sandton branch', $this->listings->find($id)['location_name']);

        $clear = $svc->updateOwn($id, $this->input(['location_name' => '']));
        $this->assertTrue($clear['ok'], $clear['message']);
        $this->assertSame('', (string) $this->listings->find($id)['location_name']);
    }

    public function testAdminCanSetTheNameAndAPartialPostKeepsIt(): void
    {
        $svc     = new DirectoryAdminService();
        $created = $svc->upsert(null, [
            'display_name'  => 'Admin Dental',
            'email'         => 'admin-loc@example.test',
            'status'        => 'published',
            'location_name' => 'Rosebank rooms',
        ]);
        $this->assertTrue($created['ok']);
        $this->assertSame('Rosebank rooms', $this->listings->find($created['id'])['location_name']);

        $svc->upsert($created['id'], ['display_name' => 'Admin Dental', 'email' => 'admin-loc@example.test', 'status' => 'published']);
        $this->assertSame('Rosebank rooms', $this->listings->find($created['id'])['location_name'], 'absent means leave alone');
    }

    private function listing(): int
    {
        return (int) $this->listings->insert([
            'type'         => 'practice',
            'display_name' => 'Test Dental',
            'email'        => 'dental@example.test',
            'slug'         => 'test-dental',
            'status'       => 'published',
            'category_id'  => $this->categoryId,
        ], true);
    }

    /**
     * @param array<string,mixed> $fields
     *
     * @return array<string,mixed>
     */
    private function input(array $fields = []): array
    {
        return $this->withRequiredSections($fields + [
            'display_name'   => 'Test Dental',
            'email'          => 'dental@example.test',
            'category_id'    => $this->categoryId,
            'title'          => 'Mr',
            'contact_person' => 'Test Owner',
            // Coordinates keep ListingGeocoder off the network.
            'latitude'       => '-33.9249',
            'longitude'      => '18.4241',
        ]);
    }
}

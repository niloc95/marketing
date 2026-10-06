<?php

use App\Models\DirectoryCategoryModel;
use App\Models\DirectoryListingModel;
use App\Services\DirectoryAdminService;
use App\Services\DirectoryListingMutationService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Tests\Support\ValidListingInput;

/**
 * Social profiles and the WhatsApp number, on all three write paths.
 *
 * Both are rendered into an href on the public profile, so what these pin is
 * that public signup, owner edit and admin edit each normalise them the same
 * way and refuse a link that is not to the network it is filed under. The
 * rules themselves are in SocialContactHelperTest.
 *
 * Requires the `tests` database group — see the Tests section of README.md.
 *
 * @internal
 */
final class SocialProfilesTest extends CIUnitTestCase
{
    use ValidListingInput;
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $namespace   = 'App';
    protected $migrate     = true;
    protected $migrateOnce = false;
    protected $refresh     = true;

    private DirectoryListingModel $listings;
    private int $categoryId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->listings   = new DirectoryListingModel();
        $this->categoryId = (int) (new DirectoryCategoryModel())->insert([
            'name'       => 'Nail Salons',
            'slug'       => 'nail-salons',
            'group_name' => 'Beauty',
            'is_active'  => 1,
        ], true);
    }

    // ------------------------------------------------------------------ form

    public function testTheSignupFormRendersEveryField(): void
    {
        $result = $this->get('add-profile');

        $result->assertOK();
        foreach (['whatsapp', 'social_facebook', 'social_instagram', 'social_linkedin', 'social_tiktok'] as $field) {
            $result->assertSee('name="' . $field . '"');
        }
    }

    // --------------------------------------------------------- public signup

    public function testSignupNormalisesEveryField(): void
    {
        $result = (new DirectoryListingMutationService())->submitPublic($this->signup([
            'whatsapp'         => '082 123 4567',
            'social_facebook'  => 'facebook.com/socialsalon',
            'social_instagram' => '@socialsalon',
            'social_linkedin'  => 'https://www.linkedin.com/company/socialsalon',
            'social_tiktok'    => 'socialsalon',
        ]));

        $this->assertTrue($result['ok'], $result['message']);
        $row = $this->listings->find((int) $result['id']);
        $this->assertSame('27821234567', $row['whatsapp']);
        $this->assertSame('https://facebook.com/socialsalon', $row['social_facebook']);
        $this->assertSame('https://www.instagram.com/socialsalon', $row['social_instagram']);
        $this->assertSame('https://www.linkedin.com/company/socialsalon', $row['social_linkedin']);
        $this->assertSame('https://www.tiktok.com/@socialsalon', $row['social_tiktok']);
    }

    public function testSignupRefusesALinkToTheWrongSiteAndABadNumber(): void
    {
        $result = (new DirectoryListingMutationService())->submitPublic($this->signup([
            'social_instagram' => 'https://evil.test/socialsalon',
            'social_facebook'  => 'javascript:alert(1)',
            'whatsapp'         => '12',
        ]));

        $this->assertFalse($result['ok']);
        $this->assertArrayHasKey('social_instagram', $result['errors']);
        $this->assertArrayHasKey('social_facebook', $result['errors']);
        $this->assertArrayHasKey('whatsapp', $result['errors']);
    }

    // ------------------------------------------------------------ owner edit

    public function testOwnerCanSetAndClearThem(): void
    {
        $id  = $this->listing();
        $svc = new DirectoryListingMutationService();

        $set = $svc->updateOwn($id, $this->post(['whatsapp' => '+27 82 123 4567', 'social_instagram' => 'instagram.com/testsalon']));
        $this->assertTrue($set['ok'], $set['message']);
        $row = $this->listings->find($id);
        $this->assertSame('27821234567', $row['whatsapp']);
        $this->assertSame('https://instagram.com/testsalon', $row['social_instagram']);

        $clear = $svc->updateOwn($id, $this->post(['whatsapp' => '', 'social_instagram' => '']));
        $this->assertTrue($clear['ok'], $clear['message']);
        $row = $this->listings->find($id);
        $this->assertSame('', (string) $row['whatsapp']);
        $this->assertSame('', (string) $row['social_instagram']);
    }

    public function testOwnerEditRefusesALinkToTheWrongSite(): void
    {
        $id     = $this->listing();
        $result = (new DirectoryListingMutationService())->updateOwn($id, $this->post(['social_linkedin' => 'https://evil.test/in/x']));

        $this->assertFalse($result['ok']);
        $this->assertArrayHasKey('social_linkedin', $result['errors']);
        $this->assertSame('', (string) $this->listings->find($id)['social_linkedin']);
    }

    // ------------------------------------------------------------ admin edit

    public function testAdminPathValidatesAndNormalisesToo(): void
    {
        $id  = $this->listing();
        $svc = new DirectoryAdminService();

        $bad = $svc->upsert($id, $this->post(['social_tiktok' => 'https://evil.test/@x']));
        $this->assertFalse($bad['ok']);
        $this->assertArrayHasKey('social_tiktok', $bad['errors']);

        $badNumber = $svc->upsert($id, $this->post(['whatsapp' => 'call me']));
        $this->assertFalse($badNumber['ok']);
        $this->assertArrayHasKey('whatsapp', $badNumber['errors']);

        $good = $svc->upsert($id, $this->post(['social_tiktok' => '@testsalon', 'whatsapp' => '082 123 4567']));
        $this->assertTrue($good['ok'], $good['message']);
        $row = $this->listings->find($id);
        $this->assertSame('https://www.tiktok.com/@testsalon', $row['social_tiktok']);
        $this->assertSame('27821234567', $row['whatsapp']);
    }

    // -------------------------------------------------------------- fixtures

    /** @param array<string,mixed> $overrides */
    private function listing(array $overrides = []): int
    {
        return (int) $this->listings->insert($overrides + [
            'type'         => 'practice',
            'display_name' => 'Test Salon',
            'email'        => 'salon@example.test',
            'slug'         => 'test-salon',
            'status'       => 'published',
            'category_id'  => $this->categoryId,
        ], true);
    }

    /**
     * A valid owner/admin submission. The coordinates stop ListingGeocoder
     * reaching Nominatim over the network — see SignupDuplicateTest::input().
     *
     * @param array<string,mixed> $fields
     *
     * @return array<string,mixed>
     */
    private function post(array $fields = []): array
    {
        return $this->withRequiredSections($fields + [
            'display_name' => 'Test Salon',
            'email'        => 'salon@example.test',
            'category_id'  => $this->categoryId,
            // The private "Your details" pair — compulsory on both write
            // paths since they became required; see validate().
            'title'          => 'Mr',
            'contact_person' => 'Test Owner',
            'latitude'     => '-33.9249',
            'longitude'    => '18.4241',
        ]);
    }

    /**
     * @param array<string,mixed> $overrides
     *
     * @return array<string,mixed>
     */
    private function signup(array $overrides = []): array
    {
        return $this->withRequiredSections(array_merge([
            // A full address is compulsory for a new signup, and matches the
            // Cape Town coordinates above — see
            // DirectoryListingMutationService::REQUIRED_ADDRESS_FIELDS.
            'address_line' => '1 Adderley Street',
            'city'         => 'Cape Town',
            'postal_code'  => '8001',
            'province'     => 'Western Cape',
            'display_name' => 'Social Link Salon',
            'email'        => 'social-' . bin2hex(random_bytes(4)) . '@example.test',
            'category_id'  => $this->categoryId,
            // The private "Your details" pair — compulsory on both write
            // paths since they became required; see validate().
            'title'          => 'Mr',
            'contact_person' => 'Test Owner',
            'consent'      => 1,
            // A new signup must ANSWER the marketing question; '0' is a
            // complete answer and is what an untouched form used to mean.
            'marketing_opt_in' => '0',
            'latitude'     => '-33.9249',
            'longitude'    => '18.4241',
        ], $overrides));
    }
}

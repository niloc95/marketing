<?php

use App\Models\DirectoryCategoryModel;
use App\Models\DirectoryListingModel;
use App\Services\DirectoryAdminService;
use App\Services\DirectoryListingMutationService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * The online booking link, on all three write paths, and the Suggest an edit
 * pre-fill that the same contact card links to.
 *
 * booking_url is rendered straight into an href on a public page, so the thing
 * these pin is that every path — public signup, owner edit, admin edit — puts
 * it through normaliseUrl() and refuses anything that is not http(s). The
 * second rule is that a saved link switches offers_online_booking on: a Book
 * online button beside a profile saying it does not book online is a lie the
 * form should not be able to tell.
 *
 * Requires the `tests` database group — see the Tests section of README.md.
 *
 * @internal
 */
final class BookingUrlTest extends CIUnitTestCase
{
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

    // --------------------------------------------------------- public signup

    public function testSignupPromotesABareDomainAndTicksTheFlag(): void
    {
        $result = (new DirectoryListingMutationService())->submitPublic($this->signup([
            'booking_url' => 'example.co.za/book',
        ]));

        $this->assertTrue($result['ok'], $result['message']);
        $row = $this->listings->find((int) $result['id']);
        $this->assertSame('https://example.co.za/book', $row['booking_url']);
        $this->assertSame('1', (string) $row['offers_online_booking'], 'a booking link implies online booking');
    }

    public function testSignupRefusesAJavascriptBookingUrl(): void
    {
        $result = (new DirectoryListingMutationService())->submitPublic($this->signup([
            'booking_url' => 'javascript:alert(1)',
        ]));

        $this->assertFalse($result['ok']);
        $this->assertArrayHasKey('booking_url', $result['errors']);
    }

    public function testSignupWithoutALinkLeavesTheFlagToTheCheckbox(): void
    {
        $result = (new DirectoryListingMutationService())->submitPublic($this->signup());

        $this->assertTrue($result['ok'], $result['message']);
        $row = $this->listings->find((int) $result['id']);
        $this->assertSame('', (string) $row['booking_url']);
        $this->assertSame('0', (string) $row['offers_online_booking']);
    }

    // ------------------------------------------------------------ owner edit

    public function testOwnerCanSetAndClearTheLink(): void
    {
        $id  = $this->listing();
        $svc = new DirectoryListingMutationService();

        $set = $svc->updateOwn($id, $this->post(['booking_url' => 'https://book.example.test/salon']));
        $this->assertTrue($set['ok'], $set['message']);
        $row = $this->listings->find($id);
        $this->assertSame('https://book.example.test/salon', $row['booking_url']);
        $this->assertSame('1', (string) $row['offers_online_booking']);

        $clear = $svc->updateOwn($id, $this->post(['booking_url' => '']));
        $this->assertTrue($clear['ok'], $clear['message']);
        $row = $this->listings->find($id);
        $this->assertSame('', (string) $row['booking_url']);
        $this->assertSame('0', (string) $row['offers_online_booking'], 'unticked and no link means off');
    }

    public function testOwnerCannotUncheckTheFlagWhileALinkIsStored(): void
    {
        $id = $this->listing(['booking_url' => 'https://book.example.test/salon', 'offers_online_booking' => 1]);

        // A real form post with the checkbox unticked and the link field absent.
        $result = (new DirectoryListingMutationService())->updateOwn($id, $this->post());

        $this->assertTrue($result['ok'], $result['message']);
        $this->assertSame('1', (string) $this->listings->find($id)['offers_online_booking']);
    }

    public function testOwnerEditRefusesAJavascriptBookingUrl(): void
    {
        $id     = $this->listing();
        $result = (new DirectoryListingMutationService())->updateOwn($id, $this->post(['booking_url' => 'javascript:alert(1)']));

        $this->assertFalse($result['ok']);
        $this->assertArrayHasKey('booking_url', $result['errors']);
        $this->assertSame('', (string) $this->listings->find($id)['booking_url']);
    }

    // ------------------------------------------------------------ admin edit

    public function testAdminPathValidatesAndNormalisesToo(): void
    {
        $id  = $this->listing();
        $svc = new DirectoryAdminService();

        $bad = $svc->upsert($id, $this->post(['booking_url' => 'javascript:alert(1)']));
        $this->assertFalse($bad['ok']);
        $this->assertArrayHasKey('booking_url', $bad['errors']);

        $good = $svc->upsert($id, $this->post(['booking_url' => 'example.co.za/book']));
        $this->assertTrue($good['ok'], $good['message']);
        $row = $this->listings->find($id);
        $this->assertSame('https://example.co.za/book', $row['booking_url']);
        $this->assertSame('1', (string) $row['offers_online_booking']);
    }

    // ------------------------------------------------- suggest an edit prefill

    public function testSuggestEditPrefillsTheContactFormForAPublishedListing(): void
    {
        $this->listing();

        $result = $this->get('contact?listing=test-salon');

        $result->assertOK();
        $result->assertSee('Suggested edit: Test Salon');
        $result->assertSee('directory/test-salon');
    }

    public function testSuggestEditIgnoresAnUnpublishedOrUnknownSlug(): void
    {
        $this->listing(['status' => 'pending']);

        $this->get('contact?listing=test-salon')->assertDontSee('Suggested edit');
        $this->get('contact?listing=no-such-listing')->assertDontSee('Suggested edit');
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
        return $fields + [
            'display_name' => 'Test Salon',
            'email'        => 'salon@example.test',
            'category_id'  => $this->categoryId,
            'latitude'     => '-33.9249',
            'longitude'    => '18.4241',
        ];
    }

    /**
     * @param array<string,mixed> $overrides
     *
     * @return array<string,mixed>
     */
    private function signup(array $overrides = []): array
    {
        return array_merge([
            // A full address is compulsory for a new signup, and matches the
            // Cape Town coordinates above — see
            // DirectoryListingMutationService::REQUIRED_ADDRESS_FIELDS.
            'address_line' => '1 Adderley Street',
            'city'         => 'Cape Town',
            'postal_code'  => '8001',
            'province'     => 'Western Cape',
            'display_name' => 'Booking Link Salon',
            'email'        => 'booking-' . bin2hex(random_bytes(4)) . '@example.test',
            'category_id'  => $this->categoryId,
            'consent'      => 1,
            // A new signup must ANSWER the marketing question; '0' is a
            // complete answer and is what an untouched form used to mean.
            'marketing_opt_in' => '0',
            'latitude'     => '-33.9249',
            'longitude'    => '18.4241',
        ], $overrides);
    }
}

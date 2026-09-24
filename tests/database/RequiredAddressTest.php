<?php

use App\Models\DirectoryCategoryModel;
use App\Models\DirectoryListingModel;
use App\Services\DirectoryListingMutationService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * A new listing must say where it is; an existing one is not held to it.
 *
 * This is a directory of places people walk into, and for most of its life it
 * accepted a signup with no address at all — 10 of the first 16 rows have no
 * street address and 9 have no coordinates. An address-less profile cannot be
 * geocoded, cannot be mapped, and cannot be shown to be in South Africa, which
 * is how businesses with no connection to the country came to be listed.
 *
 * The asymmetry is the point and is the part most likely to be "tidied up" by
 * someone who has not read this. Requiring an address on the owner's edit form
 * would mean a person whose listing predates the rule cannot correct a phone
 * number until they also produce an address they may not have to hand. The
 * description cap hit exactly this when it dropped from 5000 to 1000 and was
 * solved the same way — see RichText::exceedsCap().
 *
 * Requires the `tests` database group — see the Tests section of README.md.
 *
 * @internal
 */
final class RequiredAddressTest extends CIUnitTestCase
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

    public function testASignupWithNoAddressIsRefused(): void
    {
        $result = $this->svc->submitPublic($this->signup([
            'address_line' => '',
            'city'         => '',
            'postal_code'  => '',
            'province'     => '',
        ]));

        $this->assertFalse($result['ok']);
        // Every empty field is named, not just the first — the visitor gets one
        // round trip, not four.
        $this->assertSame(
            ['address_line', 'city', 'postal_code', 'province'],
            array_keys(array_intersect_key($result['errors'], array_flip(
                ['address_line', 'city', 'postal_code', 'province']
            )))
        );
        $this->assertSame(0, $this->listings->countAllResults());
    }

    public function testAnAddressFieldMissingEntirelyIsTreatedAsBlank(): void
    {
        // Not the same as posting an empty string: a crafted POST, or a form
        // rendered before the field existed, omits the key altogether.
        $input = $this->signup();
        unset($input['city']);

        $result = $this->svc->submitPublic($input);

        $this->assertFalse($result['ok']);
        $this->assertArrayHasKey('city', $result['errors']);
    }

    public function testSuburbAndAddressLine2StayOptional(): void
    {
        $result = $this->svc->submitPublic($this->signup([
            'suburb'         => '',
            'address_line_2' => '',
        ]));

        $this->assertTrue($result['ok'], $result['message']);
    }

    public function testAFullAddressIsAccepted(): void
    {
        $result = $this->svc->submitPublic($this->signup());

        $this->assertTrue($result['ok'], $result['message']);
        $row = $this->listings->find((int) $result['id']);
        $this->assertSame('1 Adderley Street', $row['address_line']);
        $this->assertSame('Western Cape', $row['province']);
    }

    /**
     * Signup used to validate address line 2 and then drop it: buildListingData()
     * never copied it, so the unit number an owner typed was lost on the way in.
     */
    public function testSignupKeepsAddressLine2(): void
    {
        $result = $this->svc->submitPublic($this->signup(['address_line_2' => 'Unit 4B, Harbour House']));

        $this->assertTrue($result['ok'], $result['message']);
        $this->assertSame('Unit 4B, Harbour House', $this->listings->find((int) $result['id'])['address_line_2']);
    }

    // ----------------------------------------------------------- postal code

    /**
     * maxlength="4" and inputmode="numeric" are browser-side only. Neither
     * survives a crafted POST, and maxlength does not stop a paste either.
     */
    public function testANonNumericPostalCodeIsRefused(): void
    {
        $result = $this->svc->submitPublic($this->signup(['postal_code' => 'Sandton']));

        $this->assertFalse($result['ok']);
        $this->assertArrayHasKey('postal_code', $result['errors']);
    }

    public function testAPostalCodeOfTheWrongLengthIsRefused(): void
    {
        foreach (['800', '80010'] as $code) {
            $result = $this->svc->submitPublic($this->signup(['postal_code' => $code]));

            $this->assertFalse($result['ok'], "Accepted {$code}");
            $this->assertArrayHasKey('postal_code', $result['errors']);
        }
    }

    // ------------------------------------------------------------ owner edit

    public function testAnOwnerCanStillEditAListingThatHasNoAddress(): void
    {
        // The shape of a row that predates the rule.
        $id = (int) $this->listings->insert([
            'type'         => 'practice',
            'display_name' => 'Legacy Plumbing',
            'email'        => 'legacy@example.test',
            'slug'         => 'legacy-plumbing',
            'status'       => 'published',
            'category_id'  => $this->categoryId,
        ], true);

        $result = $this->svc->updateOwn($id, [
            'display_name' => 'Legacy Plumbing',
            'category_id'  => $this->categoryId,
            // Required on an owner save too — see validate().
            'title'          => 'Mr',
            'contact_person' => 'Test Owner',
            'phone'        => '021 555 0100',
        ]);

        $this->assertTrue($result['ok'], $result['message']);
        $this->assertSame('021 555 0100', $this->listings->find($id)['phone']);
    }

    public function testAnOwnerBlankingTheirAddressIsNotBlocked(): void
    {
        $created = $this->svc->submitPublic($this->signup());
        $id      = (int) $created['id'];

        $result = $this->svc->updateOwn($id, [
            'display_name' => 'Flow Plumbing',
            'category_id'  => $this->categoryId,
            // Required on an owner save too — see validate().
            'title'          => 'Mr',
            'contact_person' => 'Test Owner',
            'address_line' => '',
            'city'         => '',
            'postal_code'  => '',
            'province'     => '',
        ]);

        // Deliberate: the rule is an intake rule. Policing it on edit is what
        // locks owners out, and an owner who empties their own address has
        // only made their own listing harder to find.
        $this->assertTrue($result['ok'], $result['message']);
    }

    // ------------------------------------------------------------ the forms

    public function testSignupMarksTheAddressRequiredButTheEditFormsDoNot(): void
    {
        $id = (int) $this->listings->insert([
            'type'         => 'practice',
            'display_name' => 'Legacy Plumbing',
            'email'        => 'legacy@example.test',
            'slug'         => 'legacy-plumbing',
            'status'       => 'published',
            'category_id'  => $this->categoryId,
        ], true);

        $signup = $this->get('add-listing');
        $signup->assertOK();
        $this->assertStringContainsString('<label>Address *</label>', $signup->getBody());
        $this->assertStringContainsString('<label>City / town *</label>', $signup->getBody());

        // The other half of the rule, and the half a careless "consistency"
        // edit would quietly undo. Both edit forms pass addressRequired
        // explicitly as false; if either stops doing so it inherits the
        // signup form's value through CI4's shared view data and starts
        // demanding an address from owners who have never had one.
        foreach ([
            $this->withSession([\App\Controllers\Manage::SESSION_KEY => $id])->get('manage/edit'),
            $this->withSession(['dir_admin' => true])->get('admin/edit/' . $id),
        ] as $result) {
            $result->assertOK();
            $html = $result->getBody();

            $this->assertStringContainsString('<label>Address</label>', $html);
            $this->assertStringNotContainsString('<label>Address *</label>', $html);
            $this->assertStringNotContainsString('<label>City / town *</label>', $html);
            $this->assertStringNotContainsString('<label>Province *</label>', $html);
        }
    }

    // -------------------------------------------------------------- fixtures

    /**
     * Valid public-form input.
     *
     * The coordinates are not decoration: ListingGeocoder::resolve() short-
     * circuits on plausible submitted coords, and without them every call here
     * would hit Nominatim over the network. They are Cape Town, and the address
     * below matches them.
     *
     * @param array<string,mixed> $overrides
     *
     * @return array<string,mixed>
     */
    private function signup(array $overrides = []): array
    {
        return array_merge([
            'display_name'   => 'Flow Plumbing',
            'email'          => 'addr-' . bin2hex(random_bytes(4)) . '@example.test',
            'category_id'    => $this->categoryId,
            // The private "Your details" pair — compulsory on both write
            // paths; see DirectoryListingMutationService::validate().
            'title'          => 'Mr',
            'contact_person' => 'Test Owner',
            'consent'        => 1,
            // A new signup must ANSWER the marketing question; '0' is a
            // complete answer and is what an untouched form used to mean.
            'marketing_opt_in' => '0',
            'address_line'   => '1 Adderley Street',
            'suburb'         => 'City Centre',
            'city'           => 'Cape Town',
            'postal_code'    => '8001',
            'province'       => 'Western Cape',
            'latitude'       => '-33.9249',
            'longitude'      => '18.4241',
        ], $overrides);
    }
}

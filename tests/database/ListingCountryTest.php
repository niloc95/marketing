<?php

use App\Models\DirectoryCategoryModel;
use App\Models\DirectoryListingModel;
use App\Services\DirectoryAdminService;
use App\Services\DirectoryListingMutationService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Countries;

/**
 * Which country a listing is in, and who is allowed to say so.
 *
 * For most of this app's life `country` was a column no form ever posted: it
 * took its DEFAULT 'South Africa' on insert and was rewritten to that same
 * literal on every owner save. A directory that is South African by
 * construction — nine hard-coded provinces, countrycodes=za at every geocoder
 * call, category × province landing pages — had no way to tell whether a
 * listing was in the country at all.
 *
 * Two invariants are pinned here, and both are load-bearing:
 *
 *  1. `country` is NOT owner-editable. It decides whether a listing is free or
 *     needs a paid International Listing subscription to publish, so a field
 *     that decides what somebody is charged cannot be writable by the person
 *     being charged. updateOwn() ignores it outright.
 *  2. `province` and `region` are mutually exclusive. province is whitelisted
 *     against SA_PROVINCES and slugified into canonical URLs; region is free
 *     text that nothing routes on. A row holding both would either mint a
 *     landing page for a province that does not exist or silently lose half an
 *     address.
 *
 * Requires the `tests` database group — see the Tests section of README.md.
 *
 * @internal
 */
final class ListingCountryTest extends CIUnitTestCase
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
            'name'       => 'Bakeries',
            'slug'       => 'bakeries',
            'group_name' => 'Food & Drink',
            'is_active'  => 1,
        ], true);
    }

    // ------------------------------------------------------------- the default

    public function testASignupWithNoCountryIsSouthAfrican(): void
    {
        // Every row in the table predates the country select and holds this
        // string; an incomplete write must not quietly create a listing that
        // has to be paid for.
        $input = $this->signup();
        unset($input['country']);

        $result = $this->svc->submitPublic($input);

        $this->assertTrue($result['ok'], $result['message']);
        $this->assertSame(Countries::SOUTH_AFRICA, $this->row($result)['country']);
    }

    public function testAnUnknownCountryIsRefused(): void
    {
        $result = $this->svc->submitPublic($this->signup(['country' => 'Freedonia']));

        $this->assertFalse($result['ok']);
        $this->assertArrayHasKey('country', $result['errors']);
        $this->assertSame(0, $this->listings->countAllResults());
    }

    // ------------------------------------------------- province XOR region

    public function testAForeignSignupStoresARegionAndNoProvince(): void
    {
        $result = $this->svc->submitPublic($this->foreign());

        $this->assertTrue($result['ok'], $result['message']);
        $row = $this->row($result);
        $this->assertSame('Germany', $row['country']);
        $this->assertSame('Bavaria', $row['region']);
        $this->assertSame('', (string) $row['province']);
    }

    public function testAForeignSignupWithoutARegionIsRefused(): void
    {
        $result = $this->svc->submitPublic($this->foreign(['region' => '']));

        $this->assertFalse($result['ok']);
        $this->assertArrayHasKey('region', $result['errors']);
    }

    public function testAProvinceOnAForeignAddressIsRefused(): void
    {
        // The form disables whichever half is out of play, so this is a
        // crafted POST — or a no-JS submission whose country just changed.
        $result = $this->svc->submitPublic($this->foreign(['province' => 'Gauteng']));

        $this->assertFalse($result['ok']);
        $this->assertArrayHasKey('province', $result['errors']);
    }

    public function testARegionOnASouthAfricanAddressIsRefused(): void
    {
        $result = $this->svc->submitPublic($this->signup(['region' => 'Bavaria']));

        $this->assertFalse($result['ok']);
        $this->assertArrayHasKey('region', $result['errors']);
    }

    // ------------------------------------------------------------ postal code

    public function testAForeignPostalCodeNeedNotBeFourDigits(): void
    {
        // "A South African postal code is four digits" is exactly that — the
        // rest of the world is six characters, alphanumeric, or has none.
        $result = $this->svc->submitPublic($this->foreign(['postal_code' => '80331-2']));

        $this->assertTrue($result['ok'], $result['message']);
        $this->assertSame('80331-2', $this->row($result)['postal_code']);
    }

    // -------------------------------------------------------------- the pin

    public function testASouthAfricanListingCannotBePinnedAbroad(): void
    {
        // ListingGeocoder::submittedCoords() drops this silently, which leaves
        // someone with a listing they believe they pinned. Say so instead.
        $result = $this->svc->submitPublic($this->signup([
            'latitude'  => '51.5072',   // London
            'longitude' => '-0.1276',
        ]));

        $this->assertFalse($result['ok']);
        $this->assertArrayHasKey('address_line', $result['errors']);
        $this->assertStringContainsString('not in South Africa', $result['errors']['address_line']);
    }

    public function testAForeignListingKeepsItsOwnPin(): void
    {
        // The same coordinates that are refused above are correct here. The
        // bounding box is a South African check, not a sanity check.
        $result = $this->svc->submitPublic($this->foreign([
            'latitude'  => '48.1351',   // Munich
            'longitude' => '11.5820',
        ]));

        $this->assertTrue($result['ok'], $result['message']);
        $row = $this->row($result);
        $this->assertSame(48.1351, (float) $row['latitude']);
        $this->assertSame(11.5820, (float) $row['longitude']);
    }

    // -------------------------------------------------- owners cannot re-flag

    public function testAPostedCountryIsIgnoredOnAnOwnerSave(): void
    {
        // The bypass this guards: set country to South Africa, save, and the
        // listing no longer needs a subscription to publish. The save is
        // allowed — there is nothing contradictory about it — but the country
        // it posted is simply not read.
        $created = $this->svc->submitPublic($this->foreign());
        $id      = (int) $created['id'];

        $result = $this->svc->updateOwn($id, [
            'display_name' => 'Brezel Bakery',
            'category_id'  => $this->categoryId,
            // Required on an owner save too — see validate().
            'title'          => 'Mr',
            'contact_person' => 'Test Owner',
            'country'      => Countries::SOUTH_AFRICA,
        ]);

        $this->assertTrue($result['ok'], $result['message']);
        $this->assertSame('Germany', $this->listings->find($id)['country']);
    }

    public function testAnOwnerCannotSmuggleAProvinceOntoAForeignListing(): void
    {
        // Validation judges the pair against the STORED country, not the
        // posted one, so this reads as what it is: a province on a German
        // address. Refused rather than silently stripped, so the two write
        // paths and the rules can never disagree about what the row holds.
        $created = $this->svc->submitPublic($this->foreign());
        $id      = (int) $created['id'];

        $result = $this->svc->updateOwn($id, [
            'display_name' => 'Brezel Bakery',
            'category_id'  => $this->categoryId,
            // Required on an owner save too — see validate().
            'title'          => 'Mr',
            'contact_person' => 'Test Owner',
            'country'      => Countries::SOUTH_AFRICA,
            'province'     => 'Gauteng',
        ]);

        $this->assertFalse($result['ok']);
        $this->assertArrayHasKey('province', $result['errors']);

        $row = $this->listings->find($id);
        $this->assertSame('Germany', $row['country']);
        $this->assertSame('', (string) $row['province']);
        $this->assertSame('Bavaria', $row['region']);
    }

    public function testAnOwnerSaveDoesNotResetTheCountryToSouthAfrica(): void
    {
        // The older, quieter half of the same bug: with no country on the form,
        // updateOwn() used to write 'South Africa' on every save, so an
        // admin-set country reverted the next time the owner touched anything.
        $created = $this->svc->submitPublic($this->foreign());
        $id      = (int) $created['id'];

        $result = $this->svc->updateOwn($id, [
            'display_name' => 'Brezel Bakery',
            'category_id'  => $this->categoryId,
            // Required on an owner save too — see validate().
            'title'          => 'Mr',
            'contact_person' => 'Test Owner',
            'phone'        => '+49 89 555 0100',
        ]);

        $this->assertTrue($result['ok'], $result['message']);
        $this->assertSame('Germany', $this->listings->find($id)['country']);
    }

    public function testAnOwnerMayStillCorrectTheirOwnRegion(): void
    {
        // region decides nothing, so unlike country it stays owner-editable.
        $created = $this->svc->submitPublic($this->foreign());
        $id      = (int) $created['id'];

        $result = $this->svc->updateOwn($id, [
            'display_name' => 'Brezel Bakery',
            'category_id'  => $this->categoryId,
            // Required on an owner save too — see validate().
            'title'          => 'Mr',
            'contact_person' => 'Test Owner',
            'region'       => 'Berlin',
        ]);

        $this->assertTrue($result['ok'], $result['message']);
        $this->assertSame('Berlin', $this->listings->find($id)['region']);
    }

    // ---------------------------------------------------------------- admin

    public function testAdminCanMoveAListingAbroadAndTheProvinceIsCleared(): void
    {
        $created = $this->svc->submitPublic($this->signup());
        $id      = (int) $created['id'];
        $this->assertSame('Western Cape', $this->listings->find($id)['province']);

        $result = (new DirectoryAdminService())->upsert($id, [
            'display_name' => 'Cape Bakery',
            'category_id'  => $this->categoryId,
            'email'        => 'cape@example.test',
            'status'       => 'published',
            'country'      => 'Germany',
            'region'       => 'Bavaria',
            'province'     => 'Western Cape',
        ]);

        $this->assertTrue($result['ok'], $result['message'] ?? '');
        $row = $this->listings->find($id);
        $this->assertSame('Germany', $row['country']);
        $this->assertSame('Bavaria', $row['region']);
        $this->assertSame('', (string) $row['province']);
    }

    // ---------------------------------------------------------- the forms

    public function testTheFormRendersTheHalfTheCountryCallsFor(): void
    {
        $created = $this->svc->submitPublic($this->foreign());
        $id      = (int) $created['id'];

        // Signup, which defaults to South Africa: province live, region
        // hidden AND disabled. Disabled is the load-bearing half — without it
        // the inactive field still posts, and the row ends up claiming a
        // province and a region at once.
        $signup = $this->get('add-listing');
        $signup->assertOK();
        $html = $signup->getBody();
        $this->assertMatchesRegularExpression('/data-address-province\s*>/', $html);
        $this->assertMatchesRegularExpression('/data-address-region\s+hidden\s*>/', $html);
        $this->assertStringContainsString('data-address-field="region" required disabled', $html);

        // The admin form for the German listing: the other way round, decided
        // by the server so it is correct before any JavaScript runs.
        $admin = $this->withSession(['dir_admin' => true])->get('admin/edit/' . $id);
        $admin->assertOK();
        $html = $admin->getBody();
        $this->assertMatchesRegularExpression('/data-address-province\s+hidden\s*>/', $html);
        $this->assertMatchesRegularExpression('/data-address-region\s*>/', $html);
        $this->assertStringContainsString('value="Bavaria"', $html);
    }

    public function testTheOwnerFormShowsTheCountryButOffersNoControlForIt(): void
    {
        $created = $this->svc->submitPublic($this->foreign());
        $id      = (int) $created['id'];

        $result = $this->withSession([\App\Controllers\Manage::SESSION_KEY => $id])->get('manage/edit');
        $result->assertOK();
        $html = $result->getBody();

        // Shown, because an owner should be able to see what their listing
        // claims — but not as a select, because country is not in
        // OWNER_EDITABLE and a control that silently does nothing is worse
        // than no control.
        $this->assertStringContainsString('Germany', $html);
        $this->assertStringNotContainsString('data-country-select', $html);
    }

    // ------------------------------------------------------------- fixtures

    /** @param array<string,mixed> $result */
    private function row(array $result): array
    {
        return $this->listings->find((int) $result['id']);
    }

    /**
     * A valid South African submission. Coordinates are Cape Town and match
     * the address, which also keeps ListingGeocoder off the network.
     *
     * @param array<string,mixed> $overrides
     *
     * @return array<string,mixed>
     */
    private function signup(array $overrides = []): array
    {
        return array_merge([
            'display_name' => 'Cape Bakery',
            'email'        => 'country-' . bin2hex(random_bytes(4)) . '@example.test',
            'category_id'  => $this->categoryId,
            // The private "Your details" pair — compulsory on both write
            // paths; see DirectoryListingMutationService::validate().
            'title'          => 'Mr',
            'contact_person' => 'Test Owner',
            'consent'      => 1,
            // A new signup must ANSWER the marketing question; '0' is a
            // complete answer and is what an untouched form used to mean.
            'marketing_opt_in' => '0',
            'country'      => Countries::SOUTH_AFRICA,
            'address_line' => '1 Adderley Street',
            'city'         => 'Cape Town',
            'postal_code'  => '8001',
            'province'     => 'Western Cape',
            'latitude'     => '-33.9249',
            'longitude'    => '18.4241',
        ], $overrides);
    }

    /**
     * The same submission from outside South Africa: a region instead of a
     * province, and Munich coordinates the bounding box would refuse.
     *
     * @param array<string,mixed> $overrides
     *
     * @return array<string,mixed>
     */
    private function foreign(array $overrides = []): array
    {
        return $this->signup(array_merge([
            'display_name' => 'Brezel Bakery',
            'country'      => 'Germany',
            'address_line' => 'Marienplatz 1',
            'city'         => 'Munich',
            'postal_code'  => '80331',
            'province'     => '',
            'region'       => 'Bavaria',
            'latitude'     => '48.1351',
            'longitude'    => '11.5820',
        ], $overrides));
    }
}

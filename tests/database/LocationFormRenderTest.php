<?php

use App\Services\DirectoryService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * The branch form and the branch profile panels actually render.
 *
 * Worth its own file because nothing else in the suite renders these views, and
 * they are the half of "a branch matches the primary address" that a service
 * test cannot see: _location_fields.php and _location_panel.php compose the same
 * partials the listing's own address uses, and a wrong field-name prefix or a
 * missing variable is invisible until something draws them.
 *
 * The field names are the contract. 'locations[0][city]' is what
 * PracticeLocationService::syncFromForm() reads back off the request, and
 * 'locations[0][hours][mon][open]' is what hours_encode() expects — get either
 * prefix wrong and the form silently saves nothing, with no error anywhere.
 *
 * @internal
 */
final class LocationFormRenderTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $namespace   = 'App';
    protected $migrate     = true;
    protected $migrateOnce = false;
    protected $refresh     = true;

    private function renderForm(array $rows = []): string
    {
        helper(['directory_hours', 'directory_ui', 'slug']);

        return view('directory/_location_fields', [
            'rows'      => $rows,
            'provinces' => DirectoryService::SA_PROVINCES,
            'err'       => static fn (string $field): string => '',
        ]);
    }

    // ------------------------------------------------------------- the form

    public function testABranchRowCarriesTheFullPrefixedFieldSet(): void
    {
        $html = $this->renderForm();

        foreach ([
            'locations[0][name]',
            'locations[0][contact_person]',
            'locations[0][address_line]',
            'locations[0][address_line_2]',
            'locations[0][suburb]',
            'locations[0][city]',
            'locations[0][province]',
            'locations[0][postal_code]',
            'locations[0][phone]',
            'locations[0][phone_alt]',
            'locations[0][email]',
        ] as $field) {
            $this->assertStringContainsString('name="' . $field . '"', $html, $field . ' must be on the branch form');
        }
    }

    public function testABranchRowCarriesAFullPrefixedHoursGrid(): void
    {
        $html = $this->renderForm();

        // All seven days, all four inputs — the same grid the listing gets.
        foreach (['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'] as $day) {
            foreach (['closed', 'open', 'close', 'note'] as $part) {
                $this->assertStringContainsString(
                    'name="locations[0][hours][' . $day . '][' . $part . ']"',
                    $html
                );
            }
        }
    }

    /**
     * A branch autocompletes like the listing's own address, and carries the
     * hidden coordinate inputs a picked suggestion writes into —
     * ListingGeocoder::submittedCoords() reads them straight off the posted row,
     * so a picked suggestion pins a branch without spending a lookup on save.
     */
    public function testABranchRowHasTheFullAutocomplete(): void
    {
        $html = $this->renderForm();

        $this->assertStringContainsString('data-address-autocomplete', $html);
        $this->assertStringContainsString('data-address-field="address_line"', $html);
        $this->assertStringContainsString('data-address-field="province"', $html);

        foreach (['latitude', 'longitude', 'geocode_precision'] as $coord) {
            $this->assertStringContainsString('data-address-coord="' . $coord . '"', $html);
            $this->assertStringContainsString('name="locations[0][' . $coord . ']"', $html);
        }
    }

    /**
     * The picker stays primary-only. It is a ~245-line singleton that binds one
     * element and drives the coordinate fields of the block it sits inside; a
     * branch gets its pin from the suggestion it picked instead.
     */
    public function testABranchRowStillHasNoPinPicker(): void
    {
        $this->assertStringNotContainsString('data-map-picker', $this->renderForm());
    }

    /**
     * Duplicate ids would be invalid HTML and would point every row's combobox
     * at the first row's listbox.
     */
    public function testEachRowGetsItsOwnListboxId(): void
    {
        $html = $this->renderForm([
            ['id' => 1, 'name' => 'One'],
            ['id' => 2, 'name' => 'Two'],
        ]);

        preg_match_all('/id="(address-suggest-list[^"]*)"/', $html, $m);

        $this->assertGreaterThanOrEqual(3, count($m[1]), 'two stored rows, one spare slot, one template');
        $this->assertSame($m[1], array_unique($m[1]), 'listbox ids must not repeat: ' . implode(', ', $m[1]));

        // The template's id carries the placeholder, so the repeat script's
        // global __i__ replace makes it unique along with the field names.
        $this->assertStringContainsString('id="address-suggest-list-__i__"', $html);
        // And aria-controls has to follow the id, or the combobox points at
        // another row's listbox.
        $this->assertStringContainsString('aria-controls="address-suggest-list-__i__"', $html);
    }

    /** The clone template the repeat script stamps out must be prefixed too. */
    public function testTheCloneTemplateUsesThePlaceholderIndex(): void
    {
        $html = $this->renderForm();

        $this->assertStringContainsString('name="locations[__i__][city]"', $html);
        $this->assertStringContainsString('name="locations[__i__][hours][mon][open]"', $html);
    }

    public function testAStoredBranchRepopulatesItsFields(): void
    {
        $html = $this->renderForm([[
            'id'            => 7,
            'name'          => 'Claremont office',
            'city'          => 'Cape Town',
            'trading_hours' => '{"mon":{"open":"08:00","close":"17:00"}}',
        ]]);

        // Decoded first: values go through esc($v, 'attr'), which escapes spaces
        // to &#x20; among other things. That is correct for user data and the
        // browser undoes it — asserting on the raw output would only pin the
        // escaper's choices.
        $html = html_entity_decode($html, ENT_QUOTES | ENT_HTML5);

        $this->assertStringContainsString('value="Claremont office"', $html);
        $this->assertStringContainsString('value="Cape Town"', $html);
        // Stored hours are JSON on the row and have to reach the grid decoded.
        $this->assertStringContainsString('value="08:00"', $html);
    }

    // ------------------------------------------------------------- the order

    /**
     * "Add another location" belongs below every field describing the business's
     * own location — its address, its pin and its hours — because a branch
     * repeats all three. It used to render above the address block, so the form
     * asked for a second location before it had asked for the first.
     */
    public function testTheBranchSectionComesAfterEveryPrimaryLocationField(): void
    {
        helper(['directory_hours', 'directory_ui', 'slug']);

        $html = view('directory/_form_fields', [
            'v'          => static fn (string $f, string $d = ''): string => $d,
            'err'        => static fn (string $f): string => '',
            'categories' => [],
            'provinces'  => DirectoryService::SA_PROVINCES,
            'vHours'     => [],
            'vTeam'      => [],
            'vLocations' => [],
            'showExtras' => true,
        ]);

        $branch  = strpos($html, 'name="locations[0][name]"');
        $this->assertNotFalse($branch, 'the branch section must render at all');

        foreach ([
            'name="address_line"',        // the primary's address
            'data-map-picker',            // its pin
            'name="hours[mon][open]"',    // its trading hours
        ] as $primaryField) {
            $at = strpos($html, $primaryField);
            $this->assertNotFalse($at, $primaryField . ' must render');
            $this->assertLessThan($branch, $at, $primaryField . ' must come before the branch section');
        }
    }

    // ---------------------------------------------------------- the profile

    public function testABranchPanelRendersMapDirectionsAndHours(): void
    {
        helper(['directory_hours', 'directory_ui', 'slug']);

        $html = view('directory/_location_panel', ['locations' => [[
            'name'              => 'Claremont branch',
            'address_line'      => '12 Main Road',
            'city'              => 'Cape Town',
            'contact_person'    => 'Branch Private Person',
            'phone'             => '021 555 0100',
            'latitude'          => -33.9249,
            'longitude'         => 18.4241,
            'geocode_precision' => 'exact',
            'trading_hours'     => hours_decode('{"mon":{"open":"08:00","close":"17:00"}}'),
        ]]]);

        $this->assertStringContainsString('Claremont branch', $html);
        $this->assertStringContainsString('data-map-view', $html, 'a branch gets the same mini map');
        $this->assertStringContainsString('Get directions', $html);
        $this->assertStringContainsString('Trading hours', $html);
        $this->assertStringContainsString('08:00', $html);
        // Shown openly and tappable, exactly as on the primary's contact panel.
        $this->assertStringContainsString('href="tel:0215550100"', html_entity_decode($html, ENT_QUOTES | ENT_HTML5));
        // The contact person is kept for administration, never published.
        $this->assertStringNotContainsString('Branch Private Person', $html);
    }

    /**
     * Rows predating the expanded schema hold only an address and a phone. Each
     * panel is self-suppressing, so such a branch renders what it has rather
     * than an empty map box or a seven-row grid of blanks.
     */
    public function testALegacyBranchRendersWithoutAMapOrHours(): void
    {
        helper(['directory_hours', 'directory_ui', 'slug']);

        $html = view('directory/_location_panel', ['locations' => [[
            'name'         => 'Legacy branch',
            'address_line' => '9 Old Street',
            'city'         => 'Johannesburg',
            'phone'        => '011 555 0111',
        ]]]);

        $this->assertStringContainsString('Legacy branch', $html);
        $this->assertStringContainsString('9 Old Street', $html);
        $this->assertStringNotContainsString('data-map-view', $html);
        $this->assertStringNotContainsString('Trading hours', $html);
    }

    /** A nameless branch still needs a heading. */
    public function testANamelessBranchIsNumbered(): void
    {
        helper(['directory_hours', 'directory_ui', 'slug']);

        $html = view('directory/_location_panel', ['locations' => [['city' => 'Durban']]]);

        $this->assertStringContainsString('Location 2', $html);
    }
}

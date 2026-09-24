<?php

use CodeIgniter\Test\CIUnitTestCase;

/**
 * @internal
 */
final class SchemaHelperTest extends CIUnitTestCase
{
    /**
     * @return list<array<string,mixed>>
     */
    private function graph(array $schema): array
    {
        $this->assertArrayHasKey('@graph', $schema);

        return $schema['@graph'];
    }

    private function nodeOfType(array $schema, string $type): ?array
    {
        foreach ($this->graph($schema) as $node) {
            if (in_array($type, (array) ($node['@type'] ?? ''), true)) {
                return $node;
            }
        }

        return null;
    }

    // ---- schema_business_type -------------------------------------------

    public function testEducationGroupIsBothLocalBusinessAndEducationalOrganization(): void
    {
        // The bug this guards: EducationalOrganization subclasses Organization,
        // not LocalBusiness, so emitting it alone left driving schools without a
        // local business entity for their address, geo and hours to attach to.
        $this->assertSame(
            ['LocalBusiness', 'EducationalOrganization'],
            schema_business_type('Education & Training')
        );
    }

    public function testKnownGroupMapsToLocalBusinessSubtype(): void
    {
        $this->assertSame('HairSalon', schema_business_type('Hair'));
        $this->assertSame('MedicalBusiness', schema_business_type('Health & Medical'));
    }

    public function testUnknownGroupFallsBackToLocalBusiness(): void
    {
        $this->assertSame('LocalBusiness', schema_business_type('Not A Real Group'));
        $this->assertSame('LocalBusiness', schema_business_type(''));
    }

    public function testSchoolCategoriesNarrowTheEducationPair(): void
    {
        $g = 'Education & Training';

        $this->assertSame(['LocalBusiness', 'Preschool'], schema_business_type($g, 'preschool-daycare'));
        $this->assertSame(['LocalBusiness', 'ElementarySchool'], schema_business_type($g, 'primary-school'));
        $this->assertSame(['LocalBusiness', 'HighSchool'], schema_business_type($g, 'high-school'));
        $this->assertSame(['LocalBusiness', 'CollegeOrUniversity'], schema_business_type($g, 'university'));
    }

    public function testEverySchoolTypeKeepsLocalBusinessFirst(): void
    {
        // The whole reason the education entry is a pair: the narrower school
        // types must not drop LocalBusiness, or address, geo and opening hours
        // have nothing to hang off — the exact bug the group-level entry fixed.
        foreach (['preschool-daycare', 'aftercare-holiday-care', 'primary-school', 'high-school',
            'combined-school', 'special-needs-school', 'remedial-school', 'online-school',
            'training-college', 'university'] as $slug) {
            $type = schema_business_type('Education & Training', $slug);

            $this->assertIsArray($type, $slug . ' should emit a type pair');
            $this->assertSame('LocalBusiness', $type[0], $slug . ' must lead with LocalBusiness');
            $this->assertCount(2, $type);
        }
    }

    public function testAnEducationCategoryWithoutItsOwnTypeFallsBackToTheGroup(): void
    {
        // Tutors, driving schools and music teachers are not schema.org schools.
        $this->assertSame(
            ['LocalBusiness', 'EducationalOrganization'],
            schema_business_type('Education & Training', 'tutor')
        );
        $this->assertSame(
            ['LocalBusiness', 'EducationalOrganization'],
            schema_business_type('Education & Training', 'driving-school')
        );
    }

    public function testASlugNeverOverridesAnotherGroupsType(): void
    {
        // The slug tier is checked first, so a slug that is not in it must not
        // disturb the group lookup.
        $this->assertSame('HairSalon', schema_business_type('Hair', 'hair-salon'));
    }

    public function testCategoriesWithAClearSchemaTypeGetIt(): void
    {
        $this->assertSame('NailSalon', schema_business_type('Beauty & Wellness', 'nail-bar'));
        $this->assertSame('Physician', schema_business_type('Health & Medical', 'general-practitioner'));
        $this->assertSame('Attorney', schema_business_type('Legal & Financial', 'attorney'));
        $this->assertSame('AccountingService', schema_business_type('Legal & Financial', 'accountant'));
        $this->assertSame('Plumber', schema_business_type('Home & Trades', 'plumber'));
        $this->assertSame('TravelAgency', schema_business_type('Travel & Tourism', 'travel-agency'));
    }

    public function testVeterinaryCareIsPairedWithLocalBusiness(): void
    {
        // VeterinaryCare is a MedicalOrganization only — same trap as the schools.
        $this->assertSame(['LocalBusiness', 'VeterinaryCare'], schema_business_type('Pets & Animals', 'veterinarian'));
    }

    public function testACategoryWithoutItsOwnTypeFallsBackToItsGroup(): void
    {
        $this->assertSame('HealthAndBeautyBusiness', schema_business_type('Beauty & Wellness', 'massage-therapist'));
        $this->assertSame('ProfessionalService', schema_business_type('Legal & Financial', 'not-a-seeded-slug'));
        $this->assertSame('LocalBusiness', schema_business_type('Travel & Tourism', 'tour-operator-guide'));
    }

    public function testEverySlugKeyIsASeededCategory(): void
    {
        // A typo in the table would silently cost that category its type, so
        // every key must be one the seeder can produce.
        helper('slug');
        $seeder = file_get_contents(APPPATH . 'Database/Seeds/DirectoryCategoriesSeeder.php');
        preg_match_all("/'([^'\\n]+)'/", $seeder, $m);
        $seeded = array_map('slugify', $m[1]);

        $helper = file_get_contents(APPPATH . 'Helpers/schema_helper.php');
        $start  = strpos($helper, '$bySlug = [');
        $table  = substr($helper, $start, strpos($helper, '];', $start) - $start);
        preg_match_all("/^\s*'([a-z0-9-]+)'\s*=>/m", $table, $keys);

        $this->assertNotEmpty($keys[1]);
        foreach ($keys[1] as $slug) {
            $this->assertContains($slug, $seeded, $slug . ' is not a seeded category slug');
        }
    }

    // ---- schema_opening_hours -------------------------------------------

    /**
     * @param array{closed?:bool,open?:string,close?:string,note?:string} $overrides
     */
    private function day(array $overrides = []): array
    {
        return array_merge(['closed' => false, 'open' => '', 'close' => '', 'note' => ''], $overrides);
    }

    public function testNullHoursYieldNoSpecifications(): void
    {
        $this->assertSame([], schema_opening_hours(null));
    }

    public function testDaysSharingATimeSpanCollapseIntoOneSpecification(): void
    {
        $open  = $this->day(['open' => '08:00', 'close' => '17:00']);
        $specs = schema_opening_hours([
            'mon' => $open, 'tue' => $open, 'wed' => $open, 'thu' => $open, 'fri' => $open,
            'sat' => $this->day(['open' => '09:00', 'close' => '13:00']),
            'sun' => $this->day(['closed' => true]),
        ]);

        $this->assertCount(2, $specs);
        $this->assertSame([
            'https://schema.org/Monday',
            'https://schema.org/Tuesday',
            'https://schema.org/Wednesday',
            'https://schema.org/Thursday',
            'https://schema.org/Friday',
        ], $specs[0]['dayOfWeek']);
        $this->assertSame('08:00', $specs[0]['opens']);
        $this->assertSame('17:00', $specs[0]['closes']);

        // A lone day is a bare string, not a one-element array.
        $this->assertSame('https://schema.org/Saturday', $specs[1]['dayOfWeek']);
    }

    public function testClosedAndIncompleteDaysAreSkipped(): void
    {
        $specs = schema_opening_hours([
            'mon' => $this->day(['closed' => true, 'open' => '08:00', 'close' => '17:00']),
            'tue' => $this->day(['open' => '08:00']),               // no close
            'wed' => $this->day(['close' => '17:00']),              // no open
            'thu' => $this->day(),                                   // neither
            'fri' => $this->day(['open' => '08:00', 'close' => '17:00']),
        ]);

        $this->assertCount(1, $specs);
        $this->assertSame('https://schema.org/Friday', $specs[0]['dayOfWeek']);
    }

    public function testDayOrderFollowsHoursDaysNotStorageOrder(): void
    {
        $specs = schema_opening_hours([
            'sun' => $this->day(['open' => '10:00', 'close' => '14:00']),
            'mon' => $this->day(['open' => '08:00', 'close' => '17:00']),
        ]);

        $this->assertSame('https://schema.org/Monday', $specs[0]['dayOfWeek']);
        $this->assertSame('https://schema.org/Sunday', $specs[1]['dayOfWeek']);
    }

    // ---- schema_breadcrumb ----------------------------------------------

    public function testBreadcrumbPositionsAreContiguousFromOne(): void
    {
        $node = schema_breadcrumb([
            ['name' => 'Browse', 'url' => 'https://example.test/directory'],
            ['name' => 'Hair', 'url' => 'https://example.test/directory/hair'],
            ['name' => 'Gauteng', 'url' => 'https://example.test/directory/hair/gauteng'],
        ], 'https://example.test/directory/hair/gauteng');

        $this->assertSame('https://example.test/directory/hair/gauteng#breadcrumb', $node['@id']);
        $this->assertSame([1, 2, 3], array_column($node['itemListElement'], 'position'));
        $this->assertSame('Gauteng', $node['itemListElement'][2]['name']);
    }

    public function testBreadcrumbRenumbersAfterAGappedInputArray(): void
    {
        // array_filter() upstream can leave holes; positions must not inherit them.
        $trail = array_filter([
            0 => ['name' => 'Browse', 'url' => 'https://example.test/directory'],
            1 => null,
            2 => ['name' => 'Hair', 'url' => 'https://example.test/directory/hair'],
        ]);

        $node = schema_breadcrumb($trail, 'https://example.test/directory/hair');
        $this->assertSame([1, 2], array_column($node['itemListElement'], 'position'));
    }

    // ---- schema_listing_item --------------------------------------------

    public function testListingItemOmitsFieldsMissingFromTheRow(): void
    {
        $item = schema_listing_item(['slug' => 'acme', 'display_name' => 'Acme']);

        $this->assertSame('Acme', $item['name']);
        $this->assertStringEndsWith('/directory/acme#business', $item['@id']);
        // A blank telephone or a @type-only PostalAddress is worse than none.
        $this->assertArrayNotHasKey('telephone', $item);
        $this->assertArrayNotHasKey('address', $item);
        $this->assertArrayNotHasKey('image', $item);
    }

    public function testListingItemUsesTheSameTypeAsTheProfile(): void
    {
        $item = schema_listing_item([
            'slug'           => 'acme',
            'display_name'   => 'Acme',
            'category_group' => 'Beauty & Wellness',
            'category_slug'  => 'nail-bar',
        ]);
        $this->assertSame('NailSalon', $item['@type']);

        // A row without the category columns still types as LocalBusiness.
        $this->assertSame('LocalBusiness', schema_listing_item(['slug' => 'b', 'display_name' => 'B'])['@type']);
    }

    public function testListingItemBuildsAddressWhenAnyComponentIsPresent(): void
    {
        $item = schema_listing_item([
            'slug'         => 'acme',
            'display_name' => 'Acme',
            'phone'        => '0825292242',
            'city'         => 'Johannesburg',
            'province'     => 'Gauteng',
            'address_line' => '',
        ]);

        $this->assertSame('0825292242', $item['telephone']);
        $this->assertSame('Johannesburg', $item['address']['addressLocality']);
        $this->assertSame('Gauteng', $item['address']['addressRegion']);
        $this->assertArrayNotHasKey('streetAddress', $item['address']);
    }

    // ---- schema_page -----------------------------------------------------

    public function testPageEmitsSingleContextAndNoDuplicateIds(): void
    {
        $canonical = base_url('directory/hair');
        $schema    = schema_page([
            schema_breadcrumb([['name' => 'Browse', 'url' => base_url('directory')]], $canonical),
            schema_item_list('Hair Salons', schema_listing_elements([
                ['slug' => 'a', 'display_name' => 'A'],
                ['slug' => 'b', 'display_name' => 'B'],
            ]), 2, $canonical),
        ], $canonical, 'CollectionPage', 'Hair Salons');

        $this->assertSame('https://schema.org', $schema['@context']);
        $this->assertSame(1, substr_count(json_encode($schema), '"@context"'));

        $ids = array_column($this->graph($schema), '@id');
        $this->assertSame($ids, array_unique($ids), 'graph must not contain duplicate @id values');
    }

    public function testPageLinksToTheSitewideWebsiteAndOrganization(): void
    {
        $canonical = base_url('directory/hair');
        $page      = $this->nodeOfType(schema_page([], $canonical, 'CollectionPage'), 'CollectionPage');

        $this->assertSame(schema_id('website'), $page['isPartOf']['@id']);
        $this->assertSame(schema_id('organization'), $page['about']['@id']);
        $this->assertSame($canonical, $page['@id']);
    }

    public function testSitewideSingletonsAreReferencesUnlessFullIsRequested(): void
    {
        $graph = $this->graph(schema_page([], base_url('directory/hair')));

        // References: @id plus @type and name, and nothing else, so the
        // homepage stays the one canonical definition of each while a validator
        // no longer reports "Unspecified Type".
        $this->assertSame(['@type', '@id', 'name'], array_keys($graph[0]));
        $this->assertSame('Organization', $graph[0]['@type']);
        $this->assertSame(schema_id('organization'), $graph[0]['@id']);
        $this->assertSame(['@type', '@id', 'name'], array_keys($graph[1]));
        $this->assertSame('WebSite', $graph[1]['@type']);
        $this->assertSame(schema_id('website'), $graph[1]['@id']);

        $full = schema_page([], base_url('/'), 'WebPage', 'Home', true);
        $this->assertSame('Organization', $this->nodeOfType($full, 'Organization')['@type']);
        $this->assertArrayHasKey('potentialAction', $this->nodeOfType($full, 'WebSite'));
    }

    public function testBreadcrumbNodeIsWiredToThePage(): void
    {
        $canonical = base_url('directory/hair');
        $schema    = schema_page(
            [schema_breadcrumb([['name' => 'Browse', 'url' => base_url('directory')]], $canonical)],
            $canonical,
            'CollectionPage'
        );

        $this->assertSame($canonical . '#breadcrumb', $this->nodeOfType($schema, 'CollectionPage')['breadcrumb']['@id']);
    }

    public function testBusinessNodeBecomesThePageMainEntity(): void
    {
        $canonical = base_url('directory/acme');
        $business  = schema_local_business(
            ['display_name' => 'Acme', 'category' => ['group_name' => 'Education & Training']],
            $canonical
        );
        $page = $this->nodeOfType(schema_page([$business], $canonical, 'ProfilePage', 'Acme'), 'ProfilePage');

        $this->assertSame($canonical . '#business', $page['mainEntity']['@id']);
        // A profile page is about the business, not about the publisher.
        $this->assertSame($canonical . '#business', $page['about']['@id']);
    }

    // ---- schema_local_business ------------------------------------------

    public function testLocalBusinessCarriesOpeningHoursAndMultiType(): void
    {
        $business = schema_local_business([
            'display_name'  => 'Drive By Success',
            'phone'         => '0825292242',
            'city'          => 'Johannesburg',
            'province'      => 'Gauteng',
            'category'      => ['group_name' => 'Education & Training', 'name' => 'Driving School'],
            'trading_hours' => [
                'mon' => $this->day(['open' => '08:00', 'close' => '17:00']),
                'sun' => $this->day(['closed' => true]),
            ],
        ], 'https://example.test/directory/drive-by-success');

        $this->assertSame(['LocalBusiness', 'EducationalOrganization'], $business['@type']);
        $this->assertCount(1, $business['openingHoursSpecification']);
        $this->assertSame('Driving School', $business['knowsAbout']);
    }

    public function testLocalBusinessNeverExposesTheOwnerEmail(): void
    {
        // The email is the sole credential for the passwordless /manage flow, so
        // it must never reach a page the sitemap enumerates.
        $business = schema_local_business(
            ['display_name' => 'Acme', 'email' => 'owner@example.test', 'phone' => '0115551234'],
            'https://example.test/directory/acme'
        );

        $this->assertStringNotContainsString('owner@example.test', json_encode($business));
    }

    public function testLocalBusinessSkipsZeroAndPartialCoordinates(): void
    {
        $base = ['display_name' => 'Acme'];
        $url  = 'https://example.test/directory/acme';

        $this->assertArrayNotHasKey('geo', schema_local_business($base + ['latitude' => 0.0, 'longitude' => 0.0], $url));
        $this->assertArrayNotHasKey('geo', schema_local_business($base + ['latitude' => -26.3, 'longitude' => null], $url));
        $this->assertArrayHasKey('geo', schema_local_business($base + ['latitude' => -26.3, 'longitude' => 27.8], $url));
    }

    public function testLocalBusinessOffersAReserveActionOnlyForASafeBookingUrl(): void
    {
        $url = 'https://example.test/directory/acme';

        $business = schema_local_business(['display_name' => 'Acme', 'booking_url' => 'https://book.example.test/acme'], $url);
        $this->assertSame(['@type' => 'ReserveAction', 'target' => 'https://book.example.test/acme'], $business['potentialAction']);

        $this->assertArrayNotHasKey('potentialAction', schema_local_business(['display_name' => 'Acme'], $url));
        $this->assertArrayNotHasKey('potentialAction', schema_local_business(['display_name' => 'Acme', 'booking_url' => 'javascript:alert(1)'], $url));
    }

    public function testLocalBusinessOmitsHoursKeyEntirelyWhenThereAreNone(): void
    {
        $business = schema_local_business(['display_name' => 'Acme', 'trading_hours' => null], 'https://example.test/directory/acme');
        $this->assertArrayNotHasKey('openingHoursSpecification', $business);
    }

    public function testLocalBusinessPublishesGalleryTagsCredentialsAndFacets(): void
    {
        $business = schema_local_business([
            'display_name' => 'Smile Co',
            'logo_path'    => 'uploads/logo.jpg',
            'photos'       => [['path' => 'uploads/a.jpg'], ['path' => 'uploads/b.jpg']],
            'category'     => ['group_name' => 'Health & Medical', 'slug' => 'dentist', 'name' => 'Dentist'],
            'tags'         => ['Implants', 'Dentist', ' Whitening '],
            'credentials'  => "BDS (Wits)\r\n\nHPCSA registered",
            'facets'       => [
                ['label' => 'Languages', 'type' => 'multi', 'values' => ['English', 'Zulu']],
                ['label' => 'Empty', 'type' => 'multi', 'values' => []],
            ],
        ], 'https://example.test/directory/smile-co');

        $this->assertSame('Dentist', $business['@type']);
        $this->assertSame([base_url('uploads/logo.jpg'), base_url('uploads/a.jpg'), base_url('uploads/b.jpg')], $business['image']);
        // Category first, duplicates and whitespace dropped.
        $this->assertSame(['Dentist', 'Implants', 'Whitening'], $business['knowsAbout']);
        $this->assertSame(['BDS (Wits)', 'HPCSA registered'], array_column($business['hasCredential'], 'name'));
        $this->assertSame([['@type' => 'PropertyValue', 'name' => 'Languages', 'value' => 'English, Zulu']], $business['additionalProperty']);
    }

    public function testLocalBusinessOmitsTheNewFieldsWhenEmpty(): void
    {
        $business = schema_local_business(
            ['display_name' => 'Acme', 'logo_path' => 'uploads/logo.jpg', 'credentials' => "  \n ", 'facets' => [], 'locations' => []],
            'https://example.test/directory/acme'
        );

        // A logo-only listing keeps the bare-string form.
        $this->assertSame(base_url('uploads/logo.jpg'), $business['image']);
        foreach (['hasCredential', 'additionalProperty', 'department', 'knowsAbout'] as $key) {
            $this->assertArrayNotHasKey($key, $business);
        }
    }

    public function testBranchesBecomeDepartmentsOfTheSameTypeWithoutEmail(): void
    {
        $business = schema_local_business([
            'display_name' => 'Smile Co',
            'category'     => ['group_name' => 'Health & Medical', 'slug' => 'dentist'],
            'locations'    => [
                ['name' => 'Rosebank rooms', 'city' => 'Johannesburg', 'phone' => '0115550000', 'email' => 'branch@example.test', 'latitude' => -26.14, 'longitude' => 28.04],
                ['name' => '', 'city' => 'Pretoria'],
            ],
        ], 'https://example.test/directory/smile-co');

        $this->assertCount(2, $business['department']);
        [$first, $second] = $business['department'];
        $this->assertSame('Dentist', $first['@type']);
        $this->assertSame('https://example.test/directory/smile-co#branch-2', $first['@id']);
        $this->assertSame('Rosebank rooms', $first['name']);
        $this->assertSame('0115550000', $first['telephone']);
        $this->assertArrayHasKey('geo', $first);
        // An unnamed branch borrows the business name and its town.
        $this->assertSame('Smile Co — Pretoria', $second['name']);
        $this->assertStringNotContainsString('branch@example.test', json_encode($business));
    }

    // ---- schema_faq_page -------------------------------------------------

    public function testFaqAnswersAreStrippedToPlainText(): void
    {
        $node = schema_faq_page([
            ['q' => 'Fees &amp; charges?', 'a' => '<p>No. See <a href="/terms">terms</a> &amp; conditions.</p>'],
        ], 'https://example.test/faq');

        $this->assertSame('Fees & charges?', $node['mainEntity'][0]['name']);
        $this->assertSame('No. See terms & conditions.', $node['mainEntity'][0]['acceptedAnswer']['text']);
    }
}

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

        // Reference-only: @id and nothing else, so the homepage stays the one
        // canonical definition of each.
        $this->assertSame(['@id' => schema_id('organization')], $graph[0]);
        $this->assertSame(['@id' => schema_id('website')], $graph[1]);

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

<?php

use App\Libraries\RichText;

/**
 * schema.org JSON-LD node builders.
 *
 * Every public page hands seo_meta() a `schema` array; this helper is where
 * those arrays are assembled. Before it existed the same four blocks — the
 * ListItem loop, the "Browse" root crumb, the FAQPage map, the WebSite/
 * Organization pair — were hand-copied across seven views, so adding a page
 * type meant duplicating a block and every fix had to be applied N times.
 *
 * The one entry point views call is schema_page(). It wraps the nodes in
 * @context + @graph, adds the WebPage node, and wires the cross-references,
 * so no view assembles a graph by hand.
 *
 * On what this markup can and cannot earn: BreadcrumbList is the only type
 * here that Google renders as a rich result for a site like this. FAQPage rich
 * results were deprecated in August 2023 (authoritative government and health
 * sites only), and ItemList only becomes a carousel for a handful of verticals
 * that do not include business directories. The rest is here because it is
 * correct, because non-Google consumers do read it, and because a connected
 * entity graph is how the brand becomes a thing search engines recognise —
 * not because it will grow a rich snippet.
 */

if (! function_exists('schema_id')) {
    /**
     * Stable @id for a sitewide singleton node.
     *
     * Fragment @ids on the site root, not bare URLs: an @id is the identity of
     * a *node*, and the Organization is not the same entity as the homepage.
     * Reusing base_url('/') for both would silently merge them.
     */
    function schema_id(string $fragment): string
    {
        return rtrim(base_url('/'), '/') . '/#' . $fragment;
    }
}

if (! function_exists('schema_organization')) {
    /**
     * The publisher entity. Emitted in full on the homepage only; every other
     * page references it by @id (see schema_page()), which is what lets search
     * engines merge them into one entity instead of many look-alikes.
     *
     * @return array<string,mixed>
     */
    function schema_organization(): array
    {
        $siteName = config('Directory')->siteName();

        return [
            '@type' => 'Organization',
            '@id'   => schema_id('organization'),
            'name'  => $siteName,
            'url'   => base_url('/'),
            'logo'  => [
                '@type' => 'ImageObject',
                'url'   => base_url(config('Directory')->ogImage()),
            ],
        ];
    }
}

if (! function_exists('schema_website')) {
    /**
     * WebSite + SearchAction — the sitelinks searchbox. Emitted in full on the
     * homepage only, for the same reason as schema_organization().
     *
     * @return array<string,mixed>
     */
    function schema_website(): array
    {
        return [
            '@type'           => 'WebSite',
            '@id'             => schema_id('website'),
            'name'            => config('Directory')->siteName(),
            'url'             => base_url('/'),
            'publisher'       => ['@id' => schema_id('organization')],
            'potentialAction' => [
                '@type'       => 'SearchAction',
                'target'      => base_url('directory') . '?q={search_term_string}',
                'query-input' => 'required name=search_term_string',
            ],
        ];
    }
}

if (! function_exists('schema_breadcrumb')) {
    /**
     * @param list<array{name:string,url:string}> $trail Ordered root-first; positions are assigned here
     *
     * @return array<string,mixed>
     */
    function schema_breadcrumb(array $trail, string $canonical): array
    {
        $items = [];
        foreach (array_values($trail) as $i => $crumb) {
            $items[] = [
                '@type'    => 'ListItem',
                'position' => $i + 1,
                'name'     => $crumb['name'],
                'item'     => $crumb['url'],
            ];
        }

        return [
            '@type'           => 'BreadcrumbList',
            '@id'             => $canonical . '#breadcrumb',
            'itemListElement' => $items,
        ];
    }
}

if (! function_exists('schema_listing_item')) {
    /**
     * One LocalBusiness node built from a *list* row (DirectoryService::search()),
     * not a full listing record.
     *
     * The list query already selects slug, display_name, logo_path, phone, the
     * address columns and category_name, so this costs no extra queries — but
     * it also means anything outside that select simply is not available here.
     * Absent keys are dropped rather than emitted empty: a blank telephone is
     * worse than no telephone.
     *
     * @param array<string,mixed> $row
     *
     * @return array<string,mixed>
     */
    function schema_listing_item(array $row): array
    {
        $url = base_url('directory/' . ($row['slug'] ?? ''));

        $address = array_filter([
            '@type'           => 'PostalAddress',
            'streetAddress'   => trim((string) ($row['address_line'] ?? '')),
            'addressLocality' => trim((string) ($row['city'] ?? '')),
            'addressRegion'   => trim((string) ($row['province'] ?? '')),
            'postalCode'      => trim((string) ($row['postal_code'] ?? '')),
        ], static fn ($v) => $v !== '');

        return array_filter([
            // The same type the profile page publishes for this business —
            // the search query already selects both category columns.
            '@type'     => schema_business_type((string) ($row['category_group'] ?? ''), (string) ($row['category_slug'] ?? '')),
            '@id'       => $url . '#business',
            'name'      => (string) ($row['display_name'] ?? ''),
            'url'       => $url,
            'telephone' => trim((string) ($row['phone'] ?? '')),
            'image'     => ($row['logo_path'] ?? null) !== null ? listing_image_url($row['logo_path']) : '',
            // count > 1 because @type is always present — a bare @type
            // PostalAddress with no actual address in it is noise.
            'address'   => count($address) > 1 ? $address : null,
        ], static fn ($v) => $v !== '' && $v !== null && $v !== []);
    }
}

if (! function_exists('schema_item_list')) {
    /**
     * @param list<array<string,mixed>> $elements Already-built ListItem members
     *
     * @return array<string,mixed>
     */
    function schema_item_list(string $name, array $elements, ?int $total = null, string $canonical = ''): array
    {
        $node = ['@type' => 'ItemList'];
        if ($canonical !== '') {
            $node['@id'] = $canonical . '#list';
        }
        $node['name']            = $name;
        $node['numberOfItems']   = $total ?? count($elements);
        $node['itemListElement'] = $elements;

        return $node;
    }
}

if (! function_exists('schema_listing_elements')) {
    /**
     * ListItems wrapping full LocalBusiness nodes, from a page of search rows.
     * Used by every view that renders a grid of listing cards.
     *
     * @param list<array<string,mixed>> $rows
     *
     * @return list<array<string,mixed>>
     */
    function schema_listing_elements(array $rows): array
    {
        $out = [];
        foreach (array_values($rows) as $i => $row) {
            $out[] = [
                '@type'    => 'ListItem',
                'position' => $i + 1,
                'item'     => schema_listing_item($row),
            ];
        }

        return $out;
    }
}

if (! function_exists('schema_link_elements')) {
    /**
     * Plain url+name ListItems, for lists whose members are not businesses —
     * the category index, where each entry is a landing page rather than a
     * LocalBusiness.
     *
     * @param list<array{url:string,name:string}> $links
     *
     * @return list<array<string,mixed>>
     */
    function schema_link_elements(array $links): array
    {
        $out = [];
        foreach (array_values($links) as $i => $link) {
            $out[] = [
                '@type'    => 'ListItem',
                'position' => $i + 1,
                'url'      => $link['url'],
                'name'     => $link['name'],
            ];
        }

        return $out;
    }
}

if (! function_exists('schema_faq_page')) {
    /**
     * Only for hand-written FAQ content (/faq, /verified). The templated
     * two-question FAQ that landing and province pages used to generate is
     * gone on purpose: near-identical boilerplate across every category ×
     * province page is a thin-content signal, and since the 2023 deprecation
     * it could not even earn a rich result in exchange.
     *
     * Answers are stripped and entity-decoded because a rich result is plain
     * text — "&amp;" would otherwise render literally.
     *
     * @param list<array{q:string,a:string}> $faqs
     *
     * @return array<string,mixed>
     */
    function schema_faq_page(array $faqs, string $canonical = ''): array
    {
        $node = ['@type' => 'FAQPage'];
        if ($canonical !== '') {
            $node['@id'] = $canonical . '#faq';
        }
        $node['mainEntity'] = array_map(static fn (array $f) => [
            '@type'          => 'Question',
            'name'           => html_entity_decode(strip_tags($f['q']), ENT_QUOTES, 'UTF-8'),
            'acceptedAnswer' => [
                '@type' => 'Answer',
                'text'  => html_entity_decode(strip_tags($f['a']), ENT_QUOTES, 'UTF-8'),
            ],
        ], array_values($faqs));

        return $node;
    }
}

if (! function_exists('schema_business_type')) {
    /**
     * The directory covers every kind of service business, so LocalBusiness is
     * the base type. Where a category group maps cleanly onto a schema.org
     * subtype we emit that instead — richer, and safe to fall back from.
     *
     * "Education & Training" is the one entry that has to be a *pair*.
     * EducationalOrganization subclasses Organization, not LocalBusiness, so
     * emitting it alone (as this map used to) meant a driving school did not
     * qualify as a local business entity at all and its address, geo and
     * opening hours had no LocalBusiness to hang off. JSON-LD allows an array
     * of types, which keeps both meanings.
     *
     * $slug is a second, narrower tier consulted first. The group alone cannot
     * tell a nail bar from a spa, a GP from a pharmacy or an attorney from an
     * accountant, and every one of them used to publish as its group's generic
     * type. A category belongs in the slug tier only when schema.org has a
     * type that is clearly what it is. A type that sits outside LocalBusiness
     * (the schools, VeterinaryCare) goes in as a pair with LocalBusiness
     * first, for the reason above. A slug with no entry falls through to its
     * group, so a slug that does not match — a renamed legacy row, say — costs
     * precision, never correctness.
     *
     * Keys are slugify() of the names in DirectoryCategoriesSeeder.
     *
     * @return string|list<string>
     */
    function schema_business_type(string $group, string $slug = '')
    {
        $bySlug = [
            // Health & Medical
            'general-practitioner'   => 'Physician',
            'specialist-physician'   => 'Physician',
            'paediatrician'          => 'Physician',
            'cardiologist'           => 'Physician',
            'dermatologist'          => 'Physician',
            'gynaecologist'          => 'Physician',
            'neurologist'            => 'Physician',
            'oncologist'             => 'Physician',
            'urologist'              => 'Physician',
            'ent-specialist'         => 'Physician',
            'ophthalmologist'        => 'Physician',
            'general-surgeon'        => 'Physician',
            'orthopaedic-surgeon'    => 'Physician',
            'anaesthetist'           => 'Physician',
            'radiologist'            => 'Physician',
            'psychiatrist'           => 'Physician',
            'dentist'                => 'Dentist',
            'orthodontist'           => 'Dentist',
            'optometrist'            => 'Optician',
            'physiotherapist'        => 'Physiotherapy',
            'pharmacy'               => 'Pharmacy',
            'medical-clinic'         => 'MedicalClinic',
            'hospital'               => 'Hospital',
            // Beauty & Wellness, Hair
            'nail-bar'               => 'NailSalon',
            'beauty-salon'           => 'BeautySalon',
            'spa'                    => 'DaySpa',
            'tattoo-piercing'        => 'TattooParlor',
            'barber'                 => 'HairSalon',
            // Motoring
            'auto-repair'            => 'AutoRepair',
            'auto-electrician'       => 'AutoRepair',
            'motorcycle-service'     => 'MotorcycleRepair',
            'panel-beater'           => 'AutoBodyShop',
            'auto-body-paint'        => 'AutoBodyShop',
            'car-wash-valet'         => 'AutoWash',
            'tyres-exhaust'          => 'TireShop',
            'vehicle-dealership'     => 'AutoDealer',
            // Legal & Financial
            'attorney'               => 'Attorney',
            'notary'                 => 'Notary',
            'conveyancer'            => 'LegalService',
            'accountant'             => 'AccountingService',
            'bookkeeper'             => 'AccountingService',
            'tax-practitioner'       => 'AccountingService',
            'auditor'                => 'AccountingService',
            'financial-adviser'      => 'FinancialService',
            'debt-counsellor'        => 'FinancialService',
            'insurance-broker'       => 'InsuranceAgency',
            // Home & Trades
            'plumber'                => 'Plumber',
            'electrician'            => 'Electrician',
            'builder'                => 'GeneralContractor',
            'painter-decorator'      => 'HousePainter',
            'roofing'                => 'RoofingContractor',
            'locksmith'              => 'Locksmith',
            'air-conditioning-refrigeration' => 'HVACBusiness',
            'removals-storage'       => 'MovingCompany',
            // Professional Services
            'estate-agent'           => 'RealEstateAgent',
            'recruitment-agency'     => 'EmploymentAgency',
            // Fitness & Sport
            'gym-fitness-centre'     => 'ExerciseGym',
            // Education & Training
            'preschool-daycare'      => ['LocalBusiness', 'Preschool'],
            'aftercare-holiday-care' => ['LocalBusiness', 'Preschool'],
            'primary-school'         => ['LocalBusiness', 'ElementarySchool'],
            'high-school'            => ['LocalBusiness', 'HighSchool'],
            'combined-school'        => ['LocalBusiness', 'School'],
            'special-needs-school'   => ['LocalBusiness', 'School'],
            'remedial-school'        => ['LocalBusiness', 'School'],
            'online-school'          => ['LocalBusiness', 'School'],
            'training-college'       => ['LocalBusiness', 'CollegeOrUniversity'],
            'university'             => ['LocalBusiness', 'CollegeOrUniversity'],
            // Events & Hospitality
            'florist'                => 'Florist',
            // Restaurants & Food. Every one of these is already a LocalBusiness
            // subtype (via FoodEstablishment), so unlike the schools no pair is
            // needed. A cuisine is a Restaurant; the cuisine itself would be
            // servesCuisine, not a type.
            'restaurant'             => 'Restaurant',
            'coffee-shop'            => 'CafeOrCoffeeShop',
            'bakery'                 => 'Bakery',
            'pizza'                  => 'Restaurant',
            'italian-restaurant'     => 'Restaurant',
            'chinese-restaurant'     => 'Restaurant',
            'mexican-restaurant'     => 'Restaurant',
            'indian-restaurant'      => 'Restaurant',
            'sushi-asian'            => 'Restaurant',
            'steakhouse-grill'       => 'Restaurant',
            'fast-food-takeaway'     => 'FastFoodRestaurant',
            'sports-bar-pub'         => 'BarOrPub',
            // Travel & Tourism
            'guest-house-accommodation' => 'LodgingBusiness',
            'game-lodge-safari'      => 'LodgingBusiness',
            'travel-agency'          => 'TravelAgency',
            'car-rental'             => 'AutoRental',
            // Pets & Animals — VeterinaryCare is a MedicalOrganization only.
            'veterinarian'           => ['LocalBusiness', 'VeterinaryCare'],
            'pet-shop'               => 'PetStore',
            // Everyday Services
            'laundry-dry-cleaning'   => 'DryCleaningOrLaundry',
            // Retail & Other
            'clothing-apparel'       => 'ClothingStore',
            'jewellery'              => 'JewelryStore',
            'furniture'              => 'FurnitureStore',
            'hardware-store'         => 'HardwareStore',
            'nursery-garden-centre'  => 'GardenStore',
        ];

        if (isset($bySlug[$slug])) {
            return $bySlug[$slug];
        }

        $map = [
            'Health & Medical'      => 'MedicalBusiness',
            'Beauty & Wellness'     => 'HealthAndBeautyBusiness',
            'Hair'                  => 'HairSalon',
            'Motoring'              => 'AutomotiveBusiness',
            'Legal & Financial'     => 'ProfessionalService',
            'Home & Trades'         => 'HomeAndConstructionBusiness',
            'Professional Services' => 'ProfessionalService',
            'Fitness & Sport'       => 'SportsActivityLocation',
            'Education & Training'  => ['LocalBusiness', 'EducationalOrganization'],
            'Restaurants & Food'    => 'FoodEstablishment',
            'Retail & Other'        => 'Store',
        ];

        // Deliberately absent: Travel & Tourism, Everyday Services, Events &
        // Hospitality, Pets & Animals and Home Industry & Handmade. Each holds
        // categories whose schema.org types diverge — a game lodge is a
        // LodgingBusiness and the travel agent selling the stay is a TravelAgency
        // — and this map only sees the group. LocalBusiness is true of all of
        // them; a wrong subtype would not be. The categories in them that do
        // have a clear type get it from the slug tier above.
        //
        // Everyday Services exists partly for this: laundry, tailoring, couriers
        // and funeral parlours used to sit in Retail & Other and were published
        // as schema.org/Store, which none of them are.


        return $map[$group] ?? 'LocalBusiness';
    }
}

if (! function_exists('schema_opening_hours')) {
    /**
     * OpeningHoursSpecification[] from decoded trading hours (the shape
     * hours_decode() returns).
     *
     * Days sharing an open/close pair collapse into one spec with a dayOfWeek
     * array — the canonical compact form, and it keeps a Mon-Fri business to
     * two nodes rather than seven. Closed days and days missing either time are
     * skipped entirely: "opens": "" is invalid, and an absent day already means
     * closed. Returns [] so the caller's array_filter drops the key.
     *
     * The per-day `note` is deliberately not carried across — there is no
     * OpeningHoursSpecification property for free text, and the human-facing
     * hours card on the profile already shows it.
     *
     * @param array<string,array{closed:bool,open:string,close:string,note:string}>|null $hours
     *
     * @return list<array<string,mixed>>
     */
    function schema_opening_hours(?array $hours): array
    {
        if ($hours === null) {
            return [];
        }

        helper('directory_hours');

        // Keyed by "open-close" so identical days group; hours_days() drives
        // both the iteration order (mon..sun, not storage order) and the day
        // names — its labels are already "Monday".."Sunday", which is exactly
        // the schema.org DayOfWeek vocabulary.
        $groups = [];
        foreach (hours_days() as $key => $label) {
            $row = $hours[$key] ?? null;
            if (! is_array($row) || ! empty($row['closed'])) {
                continue;
            }
            $open  = trim((string) ($row['open'] ?? ''));
            $close = trim((string) ($row['close'] ?? ''));
            if ($open === '' || $close === '') {
                continue;
            }
            $groups[$open . '-' . $close][] = 'https://schema.org/' . $label;
        }

        $out = [];
        foreach ($groups as $span => $days) {
            [$open, $close] = explode('-', $span, 2);
            $out[] = [
                '@type'     => 'OpeningHoursSpecification',
                'dayOfWeek' => count($days) === 1 ? $days[0] : $days,
                'opens'     => $open,
                'closes'    => $close,
            ];
        }

        return $out;
    }
}

if (! function_exists('schema_plain_description')) {
    /**
     * A listing's description as plain text, for structured data.
     *
     * Reads the derived description_text column, which the three write paths
     * keep in step with the HTML. The fallback matters for rows loaded through
     * a query that did not select it, and for the window between deploying the
     * code and running the migration — deriving it on the spot is cheap and
     * beats emitting markup into JSON-LD.
     *
     * @param array<string,mixed> $l
     */
    function schema_plain_description(array $l): string
    {
        $text = trim((string) ($l['description_text'] ?? ''));

        return $text !== ''
            ? $text
            : RichText::toPlainText(trim((string) ($l['description'] ?? '')));
    }
}

if (! function_exists('schema_postal_address')) {
    /**
     * PostalAddress from any row carrying the address columns — the listing
     * itself or one of its branches. Null when nothing but the @type would be
     * left: a bare PostalAddress with no address in it is noise.
     *
     * @param array<string,mixed> $row
     *
     * @return array<string,mixed>|null
     */
    function schema_postal_address(array $row): ?array
    {
        $address = array_filter([
            '@type'           => 'PostalAddress',
            'streetAddress'   => trim((string) ($row['address_line'] ?? '')),
            'addressLocality' => trim((string) ($row['city'] ?? '')),
            // addressRegion is schema.org's one slot for "the bit between city
            // and country", which province and region are the local and
            // foreign halves of. Exactly one is ever populated.
            'addressRegion'   => trim((string) ($row['province'] ?? '')) ?: trim((string) ($row['region'] ?? '')),
            'postalCode'      => trim((string) ($row['postal_code'] ?? '')),
            'addressCountry'  => trim((string) ($row['country'] ?? '')),
        ], static fn ($v) => $v !== '');

        return count($address) > 1 ? $address : null;
    }
}

if (! function_exists('schema_geo')) {
    /**
     * GeoCoordinates only when both coordinates are actually set — a
     * half-populated or 0,0 GeoCoordinates is worse than none.
     *
     * @param array<string,mixed> $row
     *
     * @return array<string,mixed>|null
     */
    function schema_geo(array $row): ?array
    {
        if (($row['latitude'] ?? null) === null || ($row['longitude'] ?? null) === null
            || (float) $row['latitude'] === 0.0 || (float) $row['longitude'] === 0.0) {
            return null;
        }

        return [
            '@type'     => 'GeoCoordinates',
            'latitude'  => (float) $row['latitude'],
            'longitude' => (float) $row['longitude'],
        ];
    }
}

if (! function_exists('schema_telephone')) {
    /**
     * Both contact numbers, in the order the profile shows them, with blanks
     * dropped — a row with only an alternative number still gets one. A bare
     * string for the common single-number case, since a one-element array
     * would be noise in every profile's markup; schema.org allows either.
     *
     * @param array<string,mixed> $row
     *
     * @return string|list<string>|null
     */
    function schema_telephone(array $row)
    {
        $phones = array_values(array_filter([
            trim((string) ($row['phone'] ?? '')),
            trim((string) ($row['phone_alt'] ?? '')),
        ], static fn (string $p) => $p !== ''));

        return $phones === [] ? null : (count($phones) === 1 ? $phones[0] : $phones);
    }
}

if (! function_exists('schema_local_business')) {
    /**
     * The full business node for a profile page, from a complete listing record.
     *
     * Two omissions here are deliberate and must stay:
     *
     * - No aggregateRating. There are no reviews, and inventing rating markup is
     *   fabricated structured data that earns a manual action.
     * - No email. Phone is fine to publish — it is contact data and nothing more
     *   — but the email address is the sole credential for the passwordless
     *   /manage flow, so a machine-readable copy of it on a page the sitemap
     *   enumerates is a ready-made target list for magic-link abuse. The reveal
     *   button on the profile still shows it to a human who asks.
     *
     * @param array<string,mixed> $l
     *
     * @return array<string,mixed>
     */
    function schema_local_business(array $l, string $canonical): array
    {
        helper('directory_ui');

        // sameAs carries only the socials actually filled in, and only the ones
        // that are real http(s) URLs — sameAs is a link target like any other.
        $sameAs = array_values(array_filter([
            safe_external_url($l['social_facebook'] ?? ''),
            safe_external_url($l['social_instagram'] ?? ''),
            safe_external_url($l['social_linkedin'] ?? ''),
            safe_external_url($l['website'] ?? ''),
        ]));

        $hours = schema_opening_hours($l['trading_hours'] ?? null);

        // The owner's own booking page, as the action a search result can offer.
        $bookingUrl = safe_external_url($l['booking_url'] ?? '');
        $reserve    = $bookingUrl !== '' ? ['@type' => 'ReserveAction', 'target' => $bookingUrl] : null;

        // The named people inside the business. Present only for a listing with
        // a live badge, because that is the only case getProfile() populates.
        //
        // No email or telephone on these nodes, for the reason spelled out
        // above about the listing's own address — and there is a second one
        // here: these are named private individuals, and a machine-readable
        // contact card for each of them on a page the sitemap enumerates is a
        // scrape target we would be building on their behalf.
        $employees = [];
        foreach ($l['team'] ?? [] as $member) {
            $knowsAbout = \App\Services\TeamMemberService::splitSpecializations($member['specializations'] ?? null);

            $employees[] = array_filter([
                '@type'       => 'Person',
                'name'        => trim((string) ($member['name'] ?? '')),
                'jobTitle'    => trim((string) ($member['role'] ?? '')),
                'image'       => listing_image_url($member['photo_path'] ?? null),
                'description' => trim((string) ($member['bio'] ?? '')),
                'knowsAbout'  => $knowsAbout !== [] ? $knowsAbout : null,
            ], static fn ($v) => $v !== '' && $v !== null && $v !== []);
        }

        // "Services & prices" as an offer catalogue. The price label is free
        // text ("from R150", "POA"), which schema.org's numeric `price` cannot
        // hold honestly, so it goes in the offer's description instead.
        $offers = [];
        foreach ($l['services'] ?? [] as $service) {
            $name = trim((string) ($service['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $offers[] = array_filter([
                '@type'       => 'Offer',
                'itemOffered' => ['@type' => 'Service', 'name' => $name],
                'description' => trim((string) ($service['price_label'] ?? '')),
            ], static fn ($v) => $v !== '');
        }
        $catalog = $offers === [] ? null : [
            '@type'           => 'OfferCatalog',
            'name'            => 'Services',
            'itemListElement' => $offers,
        ];

        // The same labels the profile's panel shows.
        $amenities = [];
        foreach (listing_feature_labels($l) as $label) {
            $amenities[] = ['@type' => 'LocationFeatureSpecification', 'name' => $label, 'value' => true];
        }

        // The "At a glance" facts (grades, curriculum, fees band…), worded as
        // the panel words them. additionalProperty rather than amenityFeature:
        // "Curriculum: IEB" is a fact about the business, not a feature of the
        // premises.
        $properties = [];
        foreach ($l['facets'] ?? [] as $facet) {
            $value = ($facet['type'] ?? '') === 'range'
                ? facet_range_label($facet['num_low'] ?? null, $facet['num_high'] ?? null, $facet['unit'] ?? null)
                : implode(', ', (array) ($facet['values'] ?? []));
            $label = trim((string) ($facet['label'] ?? ''));
            if ($label === '' || trim($value) === '') {
                continue;
            }
            $properties[] = ['@type' => 'PropertyValue', 'name' => $label, 'value' => $value];
        }

        // Logo first, then the gallery in the order the profile shows it.
        $images = array_values(array_filter(array_merge(
            [listing_image_url($l['logo_path'] ?? null)],
            array_map(static fn (array $p): string => listing_image_url($p['path'] ?? null), $l['photos'] ?? [])
        )));

        // The category, then the owner's own "areas of focus" — the tags panel.
        $topics = array_values(array_unique(array_filter(array_map(
            static fn ($t): string => trim((string) $t),
            array_merge([$l['category']['name'] ?? ($l['category_name'] ?? '')], $l['tags'] ?? [])
        ))));

        // One credential per line of the free-text box, which is how owners
        // list them ("BDS (Wits)" / "HPCSA registered"). Named only: the text
        // is the owner's claim and there is nothing verified to add to it.
        $credentials = [];
        foreach (preg_split('/\R/', trim((string) ($l['credentials'] ?? ''))) ?: [] as $line) {
            if (trim($line) !== '') {
                $credentials[] = ['@type' => 'EducationalOccupationalCredential', 'name' => trim($line)];
            }
        }

        $type = schema_business_type((string) ($l['category']['group_name'] ?? ''), (string) ($l['category']['slug'] ?? ''));

        // Other branches, each a business of the same type in its own right —
        // what a search for that suburb should find. Everything the branch
        // panel shows except email, which is withheld for the reason above.
        $branches = [];
        foreach (array_values($l['locations'] ?? []) as $i => $loc) {
            $branchName = trim((string) ($loc['name'] ?? ''));
            $city       = trim((string) ($loc['city'] ?? ''));
            $hoursSpec  = schema_opening_hours($loc['trading_hours'] ?? null);

            $branches[] = array_filter([
                '@type'                     => $type,
                '@id'                       => $canonical . '#branch-' . ($i + 2),
                'name'                      => $branchName !== ''
                    ? $branchName
                    : trim((string) ($l['display_name'] ?? '')) . ($city !== '' ? ' — ' . $city : ''),
                'telephone'                 => schema_telephone($loc),
                'address'                   => schema_postal_address($loc),
                'geo'                       => schema_geo($loc),
                'openingHoursSpecification' => $hoursSpec !== [] ? $hoursSpec : null,
            ], static fn ($v) => $v !== '' && $v !== null && $v !== []);
        }

        // hasMenu is a FoodEstablishment property, so it is emitted only for the
        // food group — a caterer is typed LocalBusiness, where it would be a
        // validator warning. The PDF has a stable URL; a photographed menu is
        // pointed at by its first page.
        $hasMenu = null;
        if (($l['category']['group_name'] ?? null) === 'Restaurants & Food' && ! empty($l['menu'])) {
            $first   = $l['menu'][0];
            $hasMenu = $first['kind'] === 'pdf'
                ? base_url('directory/' . $l['slug'] . '/menu')
                : base_url((string) $first['path']);
        }

        return array_filter([
            '@type'                    => $type,
            '@id'                      => $canonical . '#business',
            'name'                     => (string) ($l['display_name'] ?? ''),
            'url'                      => $canonical,
            'telephone'                => schema_telephone($l),
            // A bare string for a logo-only listing, like telephone.
            'image'                    => count($images) === 1 ? $images[0] : $images,
            // The plain-text twin, never the HTML column: Google's parser wants
            // text here, and markup in it is a structured-data warning.
            'description'              => schema_plain_description($l),
            'knowsAbout'               => count($topics) === 1 ? $topics[0] : $topics,
            'areaServed'               => trim((string) ($l['province'] ?? '')),
            'address'                  => schema_postal_address($l),
            'geo'                      => schema_geo($l),
            'openingHoursSpecification' => $hours !== [] ? $hours : null,
            'sameAs'                   => $sameAs !== [] ? $sameAs : null,
            'employee'                 => $employees !== [] ? $employees : null,
            'department'               => $branches !== [] ? $branches : null,
            'hasCredential'            => $credentials !== [] ? $credentials : null,
            'potentialAction'          => $reserve,
            'hasOfferCatalog'          => $catalog,
            'hasMenu'                  => $hasMenu,
            'amenityFeature'           => $amenities !== [] ? $amenities : null,
            'additionalProperty'       => $properties !== [] ? $properties : null,
        ], static fn ($v) => $v !== '' && $v !== null && $v !== []);
    }
}

if (! function_exists('schema_page')) {
    /**
     * The single entry point. Wraps page nodes in @context + @graph, prepends
     * the WebPage node, and links everything up.
     *
     * Sitewide singletons are referenced by @id rather than repeated: only the
     * homepage carries the full Organization and WebSite definitions (pass
     * $full = true), and every other page emits a pointer: @id plus just
     * enough (@type, name) that a validator does not flag it.
     * That is what merges them into one entity — repeating full definitions on
     * every page invites search engines to treat them as separate things.
     *
     * A BreadcrumbList in $nodes is auto-wired to WebPage.breadcrumb, and a
     * business node to WebPage.mainEntity, so callers never repeat that.
     *
     * @param list<array<string,mixed>> $nodes
     * @param 'WebPage'|'CollectionPage'|'ProfilePage'|'ItemPage' $type
     *
     * @return array<string,mixed>
     */
    function schema_page(array $nodes, string $canonical, string $type = 'WebPage', string $name = '', bool $full = false): array
    {
        $nodes = array_values(array_filter($nodes));

        $page = [
            '@type'    => $type,
            '@id'      => $canonical,
            'url'      => $canonical,
            'isPartOf' => ['@id' => schema_id('website')],
            'about'    => ['@id' => schema_id('organization')],
        ];
        if ($name !== '') {
            $page['name'] = $name;
        }

        // A node's @type can be a string or an array (see schema_business_type),
        // so normalise before testing membership.
        foreach ($nodes as $node) {
            $types = (array) ($node['@type'] ?? '');
            if (in_array('BreadcrumbList', $types, true) && isset($node['@id'])) {
                $page['breadcrumb'] = ['@id' => $node['@id']];
            } elseif (isset($node['@id']) && str_ends_with((string) $node['@id'], '#business')) {
                $page['mainEntity'] = ['@id' => $node['@id']];
                // A page whose subject is one business is about that business,
                // not about the publisher.
                $page['about'] = ['@id' => $node['@id']];
            }
        }

        // The references carry @type and name as well as @id. A bare {"@id"}
        // is valid JSON-LD, but validators report it as "Unspecified Type",
        // which reads as broken markup to anyone checking a page. Type and name
        // agree with the full definitions, so the nodes still merge.
        $siteName   = config('Directory')->siteName();
        $singletons = $full
            ? [schema_organization(), schema_website()]
            : [
                ['@type' => 'Organization', '@id' => schema_id('organization'), 'name' => $siteName],
                ['@type' => 'WebSite', '@id' => schema_id('website'), 'name' => $siteName],
            ];

        return [
            '@context' => 'https://schema.org',
            '@graph'   => array_merge($singletons, [$page], $nodes),
        ];
    }
}

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
        $config   = config('Directory');
        $siteName = $config->siteName();

        $org = [
            '@type'       => 'Organization',
            '@id'         => schema_id('organization'),
            'name'        => $siteName,
            'url'         => base_url('/'),
            'description' => site_copy('siteDescription', $config->siteDescription),
            'slogan'      => $config->slogan,
            'logo'        => [
                '@type' => 'ImageObject',
                'url'   => base_url($config->ogImage()),
            ],
            // co.za is built for South Africa; local.webscheduler.app for the
            // countries with a profile in Config\Countries.
            'areaServed'  => site_area_served(),
        ];
        $sameAs = array_values(config('Directory')->socialLinks);
        if ($sameAs !== []) {
            $org['sameAs'] = $sameAs;
        }

        return $org;
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
            'description'     => site_copy('siteDescription', config('Directory')->siteDescription),
            'inLanguage'      => (string) site()->profile()['locale'],
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
     * Absent keys are dropped rather than emitted empty.
     *
     * No telephone on purpose: a results page would otherwise hand a scraper
     * twelve phone numbers per request without a single profile visit. The
     * profile's own LocalBusiness node still carries it for Google.
     *
     * @param array<string,mixed> $row
     *
     * @return array<string,mixed>
     */
    function schema_listing_item(array $row): array
    {
        $row = listing_public_view($row);
        $url = base_url('directory/' . ($row['slug'] ?? ''));

        return array_filter([
            // The same type the profile page publishes for this business —
            // the search query already selects both category columns.
            '@type'     => schema_business_type((string) ($row['category_group'] ?? ''), (string) ($row['category_slug'] ?? ''), (string) ($row['type'] ?? ''), $row['category_schema_type'] ?? null),
            '@id'       => $url . '#business',
            'name'      => (string) ($row['display_name'] ?? ''),
            'url'       => $url,
            // The same builders the profile page uses, so a business reads
            // identically in a list and on its own page.
            'image'   => ($row['logo_path'] ?? null) !== null ? listing_image_url($row['logo_path']) : '',
            'address' => schema_postal_address($row),
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
     * The schema.org type a profile publishes, from its category.
     *
     * The tables and their order live in Config\SchemaTypes, whose docblock is
     * the rule: the admin override, the worship and civic places, the nonprofit
     * rule, the slug, the group, then LocalBusiness. A slug with no entry falls
     * through to its group, so a renamed legacy row costs precision, never
     * correctness, and a new category inherits its group's type until someone
     * sets one on /admin/categories.
     *
     * A type that is not a LocalBusiness subtype (the schools, VeterinaryCare,
     * NGO) comes back as ["LocalBusiness", Type]: emitted alone it left a
     * driving school without a local business entity for its address, geo and
     * hours to attach to. JSON-LD allows an array of types, which keeps both.
     *
     * $profileType is the listing's own type (DirectoryListingModel::TYPES).
     * $override is the category's schema_type column; one outside the allowed
     * list is ignored, so a bad row can never publish an invented type.
     *
     * @return string|list<string>
     */
    function schema_business_type(string $group, string $slug = '', string $profileType = '', ?string $override = null)
    {
        $types = config('SchemaTypes');

        $override = trim((string) $override);
        if ($override !== '' && in_array($override, $types->allowed(), true)) {
            return $types->publish($override);
        }
        if (isset($types->places[$slug])) {
            return $types->publish($types->places[$slug]);
        }
        if (in_array($profileType, $types->nonprofitTypes, true) || $group === $types->nonprofitGroup) {
            return $types->publish('NGO');
        }
        if (isset($types->bySlug[$slug])) {
            return $types->publish($types->bySlug[$slug]);
        }

        return $types->publish($types->byGroup[$group] ?? 'LocalBusiness');
    }
}

if (! function_exists('schema_medical_specialty')) {
    /**
     * medicalSpecialty for a node of $type in category $slug: schema.org
     * MedicalSpecialty URLs, or null when the type does not take the property
     * or the category has no fitting member (Config\SchemaTypes).
     *
     * @param string|list<string> $type
     *
     * @return string|list<string>|null
     */
    function schema_medical_specialty($type, string $slug)
    {
        $types = config('SchemaTypes');
        if (array_intersect((array) $type, $types->medicalSpecialtyTypes) === []) {
            return null;
        }
        $out = array_map(static fn (string $s): string => 'https://schema.org/' . $s, $types->medicalSpecialty[$slug] ?? []);

        return match (count($out)) {
            0       => null,
            1       => $out[0],
            default => $out,
        };
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

if (! function_exists('schema_holiday_hours')) {
    /**
     * Special hours for the public holidays in the next $days days, as Google
     * reads them: an OpeningHoursSpecification with validFrom and
     * validThrough on the one date, and 00:00 to 00:00 for closed all day.
     * Only holidays the owner has answered; an unanswered one is left out
     * rather than guessed, and Google then falls back to the weekday.
     *
     * @param array<string,mixed>|null $hours decoded days (hours_decode())
     * @param array<string,mixed>|null $rule  hours_holiday_rule()
     *
     * @return list<array<string,mixed>>
     */
    function schema_holiday_hours(?array $hours, ?array $rule, int $days = 60, ?string $from = null, ?\App\Libraries\PublicHolidays $calendar = null): array
    {
        if ($rule === null) {
            return [];
        }
        helper('directory_hours');

        $from ??= hours_now()->format('Y-m-d');
        $out    = [];
        foreach (($calendar ?? hours_calendar())->between($from, date('Y-m-d', strtotime($from . ' +' . $days . ' days'))) as $day) {
            $answer = hours_holiday_answer($rule, $day['key'], hours_weekday_row($hours, $day['date']));
            if ($answer === null || ($answer['state'] === 'open' && ($answer['open'] === '' || $answer['close'] === ''))) {
                continue;
            }
            $out[] = [
                '@type'        => 'OpeningHoursSpecification',
                'opens'        => $answer['state'] === 'closed' ? '00:00' : $answer['open'],
                'closes'       => $answer['state'] === 'closed' ? '00:00' : $answer['close'],
                'validFrom'    => $day['date'],
                'validThrough' => $day['date'],
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
            'streetAddress'   => schema_street_address($row),
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

if (! function_exists('schema_street_address')) {
    /**
     * The street line, then line 2 and the suburb. Owners often type the whole
     * address into it ("40 Packwood Road, Gresswold, Johannesburg, 2090"), which
     * then repeats the city and postcode that have their own properties.
     * Trailing comma-separated parts equal to the city, province, region,
     * postcode or country are dropped; the suburb and anything mid-line stay.
     *
     * @param array<string,mixed> $row
     */
    function schema_street_address(array $row): string
    {
        $street = trim((string) ($row['address_line'] ?? ''));
        $known  = array_filter(array_map(
            static fn ($v) => mb_strtolower(trim((string) $v)),
            [$row['city'] ?? '', $row['province'] ?? '', $row['region'] ?? '', $row['postal_code'] ?? '', $row['country'] ?? '', 'South Africa'],
        ), static fn (string $v) => $v !== '');

        $parts = array_map('trim', explode(',', $street));
        while (count($parts) > 1 && in_array(mb_strtolower(end($parts)), $known, true)) {
            array_pop($parts);
        }

        // Line 2 and the suburb follow, in map_address_text()'s order. The
        // suburb has no PostalAddress property of its own, and without it a
        // Midrand street filed under Johannesburg reads as somewhere else.
        // Either is skipped when the street line already has it, and so is a
        // suburb that only repeats the city.
        foreach (['address_line_2', 'suburb'] as $field) {
            $extra = trim((string) ($row[$field] ?? ''));
            $lower = array_map('mb_strtolower', $parts);
            if ($extra !== '' && ! in_array(mb_strtolower($extra), array_merge($known, $lower), true)) {
                $parts[] = $extra;
            }
        }

        return implode(', ', array_filter($parts, static fn (string $p) => $p !== ''));
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

        // The middle of a suburb or city is not where the business is, and
        // GeoCoordinates on a LocalBusiness says it is. The address says
        // enough on its own.
        if (in_array($row['geocode_precision'] ?? null, ['suburb', 'city'], true)) {
            return null;
        }

        return [
            '@type'     => 'GeoCoordinates',
            'latitude'  => (float) $row['latitude'],
            'longitude' => (float) $row['longitude'],
        ];
    }
}

if (! function_exists('schema_phone')) {
    /**
     * One number in international format ("011 728 5501" -> "+27117285501"),
     * by the same rule as the WhatsApp button: a leading 0 is the business's
     * own country (South African unless $dialCode says otherwise).
     * A number that does not parse is published as typed rather than dropped.
     */
    function schema_phone(string $phone, ?string $dialCode = '27'): string
    {
        $phone  = trim($phone);
        $digits = whatsapp_digits($phone, $dialCode);

        return $digits !== null && $digits !== '' ? '+' . $digits : $phone;
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
        $dial   = country_dial_code((string) ($row['country_code'] ?? $row['country'] ?? ''));
        $phones = array_values(array_filter([
            schema_phone((string) ($row['phone'] ?? ''), $dial),
            schema_phone((string) ($row['phone_alt'] ?? ''), $dial),
        ], static fn (string $p) => $p !== ''));

        return $phones === [] ? null : (count($phones) === 1 ? $phones[0] : $phones);
    }
}

if (! function_exists('schema_local_business')) {
    /**
     * The full business node for a profile page, from a complete listing record.
     *
     * Ratings follow the page exactly. aggregateRating and review are emitted
     * only when $l['reviews'] (from getProfile()) holds published reviews, and
     * from that same copy the reviews panel renders, so the markup never claims
     * a rating or a review the visitor cannot see. Customer reviews collected
     * by this site about a business it does not own are third-party reviews,
     * which Google's review-snippet policy allows; inventing or importing
     * ratings would not be, and earns a manual action.
     *
     * One omission is deliberate and must stay:
     *
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
        // A hidden address stays hidden here too, whatever the caller passed:
        // PostalAddress falls back to town and province, and there is no geo.
        $l = listing_public_view($l);

        // An individual's profile publishes the person separately
        // (schema_person()); what identifies them rather than the practice,
        // the credentials, registration number and research profiles, goes on
        // that node instead of this one.
        $isPerson = schema_is_person_profile($l);

        // areaServed: the places a mobile business travels to, else the
        // province as before.
        $areas      = listing_service_areas($l);
        $areaServed = $areas !== []
            ? array_map(static fn (string $a): array => ['@type' => 'Place', 'name' => $a], $areas)
            : trim((string) ($l['province'] ?? ''));

        // sameAs carries only the socials actually filled in, and only the ones
        // that are real http(s) links to the network they are stored under —
        // sameAs is a link target like any other. The same check the contact
        // panel renders through, so the two can never disagree.
        $sameAs = [];
        foreach (array_keys(listing_social_networks()) as $field) {
            $url = (string) social_profile_url($field, $l[$field] ?? '');
            // A personal LinkedIn (/in/) is the person, not the practice.
            if ($isPerson && $field === 'social_linkedin' && schema_is_personal_linkedin($url)) {
                continue;
            }
            $sameAs[] = $url;
        }
        $sameAs[] = listing_website_url($l['website'] ?? '');

        // The registration number and research profiles, only when the owner
        // has switched them on for a regulated category — the same reading the
        // credentials panel renders, so the page and the markup never disagree.
        // The number is a PropertyValue named by its regulator, not a
        // credential: it identifies the professional, it does not certify them.
        $identifier = null;
        if (! $isPerson && ($reg = \App\Libraries\ProfessionalRegistration::forDisplay($l)) !== null) {
            if ($reg['number'] !== '') {
                $identifier = ['@type' => 'PropertyValue', 'propertyID' => $reg['short'], 'value' => $reg['number']];
            }
            $sameAs[] = (string) $reg['orcid'];
            $sameAs[] = (string) $reg['researchgate'];
        }
        $sameAs = array_values(array_filter($sameAs));

        $hours = array_merge(
            schema_opening_hours($l['trading_hours'] ?? null),
            schema_holiday_hours($l['trading_hours'] ?? null, $l['holiday_rule'] ?? null)
        );

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
        //
        // Each Person points back at the business with worksFor, by @id rather
        // than a copy, and carries an @id of its own that is the card's anchor
        // on this page — so "who works where" survives a parser that reads the
        // Person node on its own.
        //
        // On an individual's profile, the team card that is the person
        // themselves takes the person's @id, so the two merge into one entity
        // instead of a Person and their look-alike.
        $employees  = [];
        $personId   = $canonical . '#person';
        $personSeen = false;
        foreach ($l['team'] ?? [] as $member) {
            $knowsAbout = \App\Services\TeamMemberService::splitSpecializations($member['specializations'] ?? null);
            $anchor     = team_member_anchor($member);
            $isSelf     = $isPerson && ! $personSeen && schema_same_person((string) ($member['name'] ?? ''), $l);
            $personSeen = $personSeen || $isSelf;

            $employees[] = array_filter([
                '@type'         => 'Person',
                '@id'           => $isSelf ? $personId : ($anchor !== '' ? $canonical . '#' . $anchor : ''),
                'name'          => trim((string) ($member['name'] ?? '')),
                'jobTitle'      => trim((string) ($member['role'] ?? '')),
                'image'         => listing_image_url($member['photo_path'] ?? null),
                'description'   => trim((string) ($member['bio'] ?? '')),
                'knowsAbout'    => $knowsAbout !== [] ? $knowsAbout : null,
                'hasCredential' => schema_member_credentials($member['credentials'] ?? null) ?: null,
                'worksFor'      => ['@id' => $canonical . '#business'],
            ], static fn ($v) => $v !== '' && $v !== null && $v !== []);
        }
        if ($isPerson && ! $personSeen) {
            array_unshift($employees, ['@id' => $personId]);
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
                'url'         => safe_external_url($service['link_url'] ?? ''),
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

        // An individual's credentials are theirs, on the Person node.
        $credentials = $isPerson ? [] : schema_listing_credentials($l);

        $type = schema_business_type((string) ($l['category']['group_name'] ?? ''), (string) ($l['category']['slug'] ?? ''), (string) ($l['type'] ?? ''), $l['category']['schema_type'] ?? null);

        // Other branches, each a business of the same type in its own right —
        // what a search for that suburb should find. Everything the branch
        // panel shows except email, which is withheld for the reason above.
        $branches = [];
        foreach (array_values($l['locations'] ?? []) as $i => $loc) {
            $branchName = trim((string) ($loc['name'] ?? ''));
            $city       = trim((string) ($loc['city'] ?? ''));
            $hoursSpec  = array_merge(
                schema_opening_hours($loc['trading_hours'] ?? null),
                schema_holiday_hours($loc['trading_hours'] ?? null, $loc['holiday_rule'] ?? null)
            );

            $branches[] = array_filter([
                '@type'                     => $type,
                '@id'                       => $canonical . '#branch-' . ($i + 2),
                'name'                      => $branchName !== ''
                    ? $branchName
                    : trim((string) ($l['display_name'] ?? '')) . ($city !== '' ? ', ' . $city : ''),
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

        // Published reviews only; see the docblock. The author is the same
        // "Thandi M." the panel shows, never the full name.
        $rating     = null;
        $reviewList = [];
        $reviews    = $l['reviews'] ?? null;
        if (is_array($reviews) && (int) ($reviews['count'] ?? 0) > 0 && (float) ($reviews['avg'] ?? 0) >= 1) {
            $rating = [
                '@type'       => 'AggregateRating',
                'ratingValue' => number_format((float) $reviews['avg'], 1, '.', ''),
                'reviewCount' => (int) $reviews['count'],
                'bestRating'  => 5,
                'worstRating' => 1,
            ];
            foreach ($reviews['items'] ?? [] as $rev) {
                $published    = strtotime((string) ($rev['published_at'] ?? ''));
                $reviewList[] = array_filter([
                    '@type'         => 'Review',
                    'author'        => ['@type' => 'Person', 'name' => (string) ($rev['author'] ?? '')],
                    'datePublished' => $published !== false ? date('Y-m-d', $published) : '',
                    'reviewBody'    => (string) ($rev['body'] ?? ''),
                    'reviewRating'  => ['@type' => 'Rating', 'ratingValue' => (int) $rev['rating'], 'bestRating' => 5, 'worstRating' => 1],
                ], static fn ($v) => $v !== '');
            }
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
            'areaServed'               => $areaServed,
            'address'                  => schema_postal_address($l),
            'geo'                      => schema_geo($l),
            'openingHoursSpecification' => $hours !== [] ? $hours : null,
            'medicalSpecialty'         => schema_medical_specialty($type, (string) ($l['category']['slug'] ?? '')),
            'identifier'               => $identifier,
            'sameAs'                   => $sameAs !== [] ? $sameAs : null,
            'employee'                 => $employees !== [] ? $employees : null,
            'department'               => $branches !== [] ? $branches : null,
            'hasCredential'            => $credentials !== [] ? $credentials : null,
            'potentialAction'          => $reserve,
            'hasOfferCatalog'          => $catalog,
            'hasMenu'                  => $hasMenu,
            'amenityFeature'           => $amenities !== [] ? $amenities : null,
            'additionalProperty'       => $properties !== [] ? $properties : null,
            'aggregateRating'          => $rating,
            'review'                   => $reviewList !== [] ? $reviewList : null,
        ], static fn ($v) => $v !== '' && $v !== null && $v !== []);
    }
}

if (! function_exists('schema_listing_credentials')) {
    /**
     * One credential per line of the listing's free-text box, which is how
     * owners list them ("BDS (Wits)" / "HPCSA registered"). Named only: the
     * text is the owner's claim and there is nothing verified to add to it.
     *
     * @param array<string,mixed> $l
     *
     * @return list<array{'@type':string,name:string}>
     */
    function schema_listing_credentials(array $l): array
    {
        $out = [];
        foreach (preg_split('/\R/', trim((string) ($l['credentials'] ?? ''))) ?: [] as $line) {
            if (trim($line) !== '') {
                $out[] = ['@type' => 'EducationalOccupationalCredential', 'name' => trim($line)];
            }
        }

        return $out;
    }
}

if (! function_exists('schema_is_person_profile')) {
    /**
     * Whether the profile is one person ("Individual or Sole Trader"), which
     * publishes a Person beside the practice node. Only the owner's or admin's
     * choice of type decides it, never a guess from the name.
     *
     * @param array<string,mixed> $l
     */
    function schema_is_person_profile(array $l): bool
    {
        return ($l['type'] ?? '') === 'person';
    }
}

if (! function_exists('schema_same_person')) {
    /**
     * Whether a team member's $name is the profile's own person: the display
     * name or the contact person, ignoring a leading title, case, spacing and
     * the curly apostrophe ("Dr Gavin O’Brien" = "Gavin O'Brien").
     *
     * @param array<string,mixed> $l
     */
    function schema_same_person(string $name, array $l): bool
    {
        $norm = static function (string $n): string {
            $n = mb_strtolower(str_replace(['’', '‘'], "'", trim($n)));
            $n = preg_replace('/\s+/u', ' ', $n) ?? $n;

            return preg_replace('/^(dr|prof|adv|mr|mrs|ms|miss)\.?\s+/u', '', $n) ?? $n;
        };

        $name = $norm($name);
        if ($name === '') {
            return false;
        }
        foreach ([(string) ($l['display_name'] ?? ''), (string) ($l['contact_person'] ?? '')] as $candidate) {
            if ($candidate !== '' && $norm($candidate) === $name) {
                return true;
            }
        }

        return false;
    }
}

if (! function_exists('schema_is_personal_linkedin')) {
    /** A LinkedIn member profile (/in/…), as opposed to a company page. */
    function schema_is_personal_linkedin(string $url): bool
    {
        return $url !== '' && preg_match('#^/in/[^/]+#i', (string) parse_url($url, PHP_URL_PATH)) === 1;
    }
}

if (! function_exists('schema_person')) {
    /**
     * The Person an individual's profile is about, or [] for any other profile.
     *
     * The practice stays a business node (#business) with the address, hours,
     * phone and geo that only LocalBusiness types carry; this node is the
     * person, linked to it with worksFor, and becomes the page's mainEntity
     * (schema_page()). Google's profile page guidance asks for exactly that:
     * a ProfilePage whose mainEntity is the Person it describes.
     *
     * What it says is what the page shows: the name, the title from the
     * Title dropdown, the category as job title, the description, the areas
     * of focus, the credentials box, the registration number and research
     * profiles when the owner switched them on, a personal LinkedIn. No email
     * or telephone, for the reasons on schema_local_business(), and nothing
     * about our Verified badge: no schema.org property says "documents checked
     * by a listing platform" without reading as a professional accreditation.
     *
     * @param array<string,mixed> $l
     *
     * @return array<string,mixed>
     */
    function schema_person(array $l, string $canonical): array
    {
        if (! schema_is_person_profile($l)) {
            return [];
        }
        $l = listing_public_view($l);

        $name    = trim((string) ($l['display_name'] ?? ''));
        $contact = trim((string) ($l['contact_person'] ?? ''));
        $title   = (string) ($l['title'] ?? '');
        $job     = trim((string) ($l['category']['name'] ?? ($l['category_name'] ?? '')));

        $sameAs     = [];
        $identifier = null;
        if (($reg = \App\Libraries\ProfessionalRegistration::forDisplay($l)) !== null) {
            if ($reg['number'] !== '') {
                $identifier = ['@type' => 'PropertyValue', 'propertyID' => $reg['short'], 'value' => $reg['number']];
            }
            $sameAs[] = (string) $reg['orcid'];
            $sameAs[] = (string) $reg['researchgate'];
        }
        $linkedin = (string) social_profile_url('social_linkedin', $l['social_linkedin'] ?? '');
        if (schema_is_personal_linkedin($linkedin)) {
            $sameAs[] = $linkedin;
        }
        $sameAs = array_values(array_filter($sameAs));

        $topics = array_values(array_unique(array_filter(array_map(
            static fn ($t): string => trim((string) $t),
            $l['tags'] ?? []
        ))));

        // Where they work: the practice, then each branch by the @id
        // schema_local_business() gives it.
        $workLocation = [['@id' => $canonical . '#business']];
        foreach (array_keys(array_values($l['locations'] ?? [])) as $i) {
            $workLocation[] = ['@id' => $canonical . '#branch-' . ($i + 2)];
        }

        $credentials = schema_listing_credentials($l);
        $image       = listing_image_url($l['logo_path'] ?? null) ?: listing_image_url($l['photos'][0]['path'] ?? null);

        return array_filter([
            '@type'           => 'Person',
            '@id'             => $canonical . '#person',
            'name'            => $name,
            // "Blaine Bloy" beside "Dr Blaine Bloy": both are how people search.
            'alternateName'   => $contact !== '' && $contact !== $name ? $contact : '',
            'honorificPrefix' => in_array($title, \App\Models\DirectoryListingModel::TITLES, true) ? $title : '',
            'url'             => $canonical,
            'image'           => $image,
            'description'     => schema_plain_description($l),
            'jobTitle'        => $job,
            'hasOccupation'   => $job !== '' ? ['@type' => 'Occupation', 'name' => $job] : null,
            'knowsAbout'      => count($topics) === 1 ? $topics[0] : $topics,
            'hasCredential'   => $credentials !== [] ? $credentials : null,
            'identifier'      => $identifier,
            'sameAs'          => $sameAs !== [] ? $sameAs : null,
            'worksFor'        => ['@id' => $canonical . '#business'],
            'workLocation'    => count($workLocation) === 1 ? $workLocation[0] : $workLocation,
        ], static fn ($v) => $v !== '' && $v !== null && $v !== []);
    }
}

if (! function_exists('schema_member_credentials')) {
    /**
     * A team member's qualifications as credential nodes.
     *
     * The member field is one line ("BDS (Wits), MSc Dent (UP)"), unlike the
     * listing's own box, which takes one per line, so this splits on commas and
     * semicolons as well. A comma inside brackets is part of a credential
     * ("MBChB (UCT, 2010)") and does not split it. Named only, for the same
     * reason as the listing's credentials: it is the owner's claim, and there
     * is nothing verified to add to it.
     *
     * @return list<array{'@type':string,name:string}>
     */
    function schema_member_credentials(?string $credentials): array
    {
        $parts = preg_split('/[;\r\n]+|,(?![^(]*\))/', trim((string) $credentials)) ?: [];

        $out = [];
        foreach ($parts as $part) {
            $part = trim($part);
            if ($part !== '') {
                $out[] = ['@type' => 'EducationalOccupationalCredential', 'name' => $part];
            }
        }

        return $out;
    }
}

if (! function_exists('schema_datetime')) {
    /**
     * A stored DATETIME (UTC, Config\App::$appTimezone) as ISO 8601 with its
     * offset, or '' when empty or unreadable.
     */
    function schema_datetime(?string $value): string
    {
        $value = trim((string) $value);
        if ($value === '' || str_starts_with($value, '0000')) {
            return '';
        }
        try {
            return (new DateTimeImmutable($value, new DateTimeZone('UTC')))->format(DATE_ATOM);
        } catch (Exception) {
            return '';
        }
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
     * Person node (#person), else a business node, to WebPage.mainEntity, so
     * callers never repeat that.
     *
     * $props are extra WebPage properties (description, mainEntity, hasPart…),
     * merged last so a caller can set what the auto-wiring cannot guess.
     *
     * @param list<array<string,mixed>> $nodes
     * @param 'WebPage'|'CollectionPage'|'ProfilePage'|'ItemPage' $type
     * @param array<string,mixed> $props
     *
     * @return array<string,mixed>
     */
    function schema_page(array $nodes, string $canonical, string $type = 'WebPage', string $name = '', bool $full = false, array $props = []): array
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
            } elseif (isset($node['@id']) && str_ends_with((string) $node['@id'], '#person')) {
                $person = ['@id' => $node['@id']];
            } elseif (isset($node['@id']) && str_ends_with((string) $node['@id'], '#business')) {
                $page['mainEntity'] = ['@id' => $node['@id']];
                // A page whose subject is one business is about that business,
                // not about the publisher.
                $page['about'] = ['@id' => $node['@id']];
            }
        }
        // An individual's page is about the person (schema_person()); the
        // practice is still in the graph, linked by worksFor.
        if (isset($person)) {
            $page['mainEntity'] = $person;
            $page['about']      = $person;
        }

        $page = array_merge($page, $props);

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

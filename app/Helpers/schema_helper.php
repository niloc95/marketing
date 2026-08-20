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
            '@type'     => 'LocalBusiness',
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
     * @return string|list<string>
     */
    function schema_business_type(string $group)
    {
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
            'Retail & Other'        => 'Store',
        ];

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

        $address = array_filter([
            '@type'           => 'PostalAddress',
            'streetAddress'   => trim((string) ($l['address_line'] ?? '')),
            'addressLocality' => trim((string) ($l['city'] ?? '')),
            'addressRegion'   => trim((string) ($l['province'] ?? '')),
            'postalCode'      => trim((string) ($l['postal_code'] ?? '')),
            'addressCountry'  => trim((string) ($l['country'] ?? '')),
        ], static fn ($v) => $v !== '');

        // Only emit geo when both coordinates are actually set — a
        // half-populated or 0,0 GeoCoordinates is worse than none.
        $geo = null;
        if (($l['latitude'] ?? null) !== null && ($l['longitude'] ?? null) !== null
            && (float) $l['latitude'] !== 0.0 && (float) $l['longitude'] !== 0.0) {
            $geo = [
                '@type'     => 'GeoCoordinates',
                'latitude'  => (float) $l['latitude'],
                'longitude' => (float) $l['longitude'],
            ];
        }

        // sameAs carries only the socials actually filled in, and only the ones
        // that are real http(s) URLs — sameAs is a link target like any other.
        $sameAs = array_values(array_filter([
            safe_external_url($l['social_facebook'] ?? ''),
            safe_external_url($l['social_instagram'] ?? ''),
            safe_external_url($l['social_linkedin'] ?? ''),
            safe_external_url($l['website'] ?? ''),
        ]));

        $hours = schema_opening_hours($l['trading_hours'] ?? null);

        return array_filter([
            '@type'                    => schema_business_type((string) ($l['category']['group_name'] ?? '')),
            '@id'                      => $canonical . '#business',
            'name'                     => (string) ($l['display_name'] ?? ''),
            'url'                      => $canonical,
            'telephone'                => trim((string) ($l['phone'] ?? '')),
            'image'                    => listing_image_url($l['logo_path'] ?? null),
            // The plain-text twin, never the HTML column: Google's parser wants
            // text here, and markup in it is a structured-data warning.
            'description'              => schema_plain_description($l),
            'knowsAbout'               => (string) ($l['category']['name'] ?? ($l['category_name'] ?? '')),
            'areaServed'               => trim((string) ($l['province'] ?? '')),
            'address'                  => count($address) > 1 ? $address : null,
            'geo'                      => $geo,
            'openingHoursSpecification' => $hours !== [] ? $hours : null,
            'sameAs'                   => $sameAs !== [] ? $sameAs : null,
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
     * $full = true), and every other page emits a bare {"@id": ...} pointer.
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

        $singletons = $full
            ? [schema_organization(), schema_website()]
            : [['@id' => schema_id('organization')], ['@id' => schema_id('website')]];

        return [
            '@context' => 'https://schema.org',
            '@graph'   => array_merge($singletons, [$page], $nodes),
        ];
    }
}

<?php

if (! function_exists('lucide')) {
    /**
     * One Lucide icon, inlined.
     *
     * The icons are vendored into resources/icons/ by scripts/sync-icons.js and
     * committed, so this never reaches node_modules. They are inlined rather
     * than served as files because both properties are built to make zero
     * external requests, and because an <img> cannot inherit currentColor —
     * which is the whole reason every one of these icons sits inside a
     * text-emerald-600 or dark:text-slate-300 cascade and needs no variant.
     *
     * $class is the caller's, and it is the only place a size comes from: the
     * vendored files have their width/height stripped, so an icon rendered
     * without a height/width class fills its container rather than quietly
     * defaulting to 24px. That is deliberate — it fails visibly.
     *
     * aria-hidden is on by default because an icon beside its own label is
     * decoration. Pass ['aria-hidden' => null] to drop it for the rare icon
     * that is the only content of its control, and give that one a label.
     *
     * @param array<string,string|null> $attrs Extra root attributes; null removes one.
     */
    function lucide(string $name, string $class = '', array $attrs = []): string
    {
        static $cache = [];

        if (! isset($cache[$name])) {
            $path = ROOTPATH . 'resources/icons/' . $name . '.svg';

            // Loud on purpose. A missing icon that renders as nothing is the
            // failure that survives review and ships — every call site here is
            // a fixed literal, so this can only fire on a typo or an icon
            // dropped from the ICONS manifest without its callers.
            if (! is_file($path)) {
                throw new InvalidArgumentException(
                    'Unknown Lucide icon "' . $name . '". Add it to ICONS in scripts/sync-icons.js.'
                );
            }

            $cache[$name] = trim(file_get_contents($path));
        }

        // += and not array_merge: the caller's value wins for a key it set,
        // including an explicit null to drop the attribute entirely.
        $attrs += ['aria-hidden' => 'true'];
        if ($class !== '') {
            $attrs['class'] = $class;
        }

        $rendered = '';
        foreach ($attrs as $key => $value) {
            if ($value !== null) {
                // html, not 'attr': the attr escaper percent-encodes every
                // non-alphanumeric, which turns a class list into
                // "h-6&#x20;w-6". That parses, but it is unreadable in view
                // source and bloats every page. The value sits inside double
                // quotes we control, so escaping & < > " ' is the right set.
                $rendered .= ' ' . $key . '="' . esc((string) $value) . '"';
            }
        }

        return substr_replace($cache[$name], $rendered, 4, 0);
    }
}

if (! function_exists('verified_seal')) {
    /**
     * The Verified Business seal, inlined.
     *
     * The seal and the badge-verified pill are the same claim at two sizes. The
     * pill carries it inline at 14px; the seal is the mark itself and needs
     * roughly 56px before its ring text and scallops stop being mush, so it is
     * only used where there is room — see verified.php, _verification_panel.php
     * and _plan_cards.php. Below that size, use the pill.
     *
     * Read from FCPATH and not ROOTPATH . 'resources/', which is where lucide()
     * looks, for two reasons: scripts/sync-icons.js deletes anything in
     * resources/icons/ that is not in its manifest, and build-listing-app.js
     * never copies resources/ into the deploy bundle but does copy public/. One
     * file, and the same file is servable if an embeddable badge is ever built.
     *
     * Inlined rather than an <img> so the page's Inter reaches the two text runs
     * — the file pins both with textLength, so a fallback font shifts the
     * letterforms but never the layout.
     *
     * $class is the caller's and is the only source of size, like lucide().
     * aria-hidden is on by default because every call site sits beside its own
     * "Verified Business" wording; pass ['aria-hidden' => null] and give it a
     * label for one that does not.
     *
     * @param array<string,string|null> $attrs Extra root attributes; null removes one.
     */
    function verified_seal(string $class = '', array $attrs = []): string
    {
        static $svg = null;

        if ($svg === null) {
            $path = FCPATH . 'assets/verified-seal.svg';

            // Loud on purpose, for the same reason lucide() is: a trust mark
            // that silently renders as nothing is worse than a page that fails.
            if (! is_file($path)) {
                throw new RuntimeException('Missing public/assets/verified-seal.svg.');
            }

            $svg = trim(file_get_contents($path));
        }

        $attrs += ['aria-hidden' => 'true'];
        if ($class !== '') {
            $attrs['class'] = $class;
        }

        $rendered = '';
        foreach ($attrs as $key => $value) {
            if ($value !== null) {
                $rendered .= ' ' . $key . '="' . esc((string) $value) . '"';
            }
        }

        return substr_replace($svg, $rendered, 4, 0);
    }
}

if (! function_exists('header_quick_categories')) {
    /**
     * The categories on the header's quick-access strip.
     *
     * This exists so layouts/public.php can fetch its own chrome data. The
     * alternative — threading a 'quickCats' key through every controller method
     * that renders the layout — is eight call sites that all have to remember,
     * and one that forgets renders a header with a row missing.
     *
     * Delegates to DirectoryService::topCategories(), which already filters to
     * categories with listings. That filter is load-bearing, not cosmetic:
     * renderLanding() 404s on an empty category, so an unfiltered list would put
     * dead links in the header of every page on the site.
     *
     * Every failure path returns [] and the strip simply does not render. A
     * helper called from the layout runs on /manage, /contact and the error
     * pages too, so a database that has gone away must cost the strip and
     * nothing else — throwing here would turn one dead table into a site-wide
     * 500. Same read-through shape as HeroImageService::slides().
     *
     * @return array<int,array<string,mixed>>
     */
    function header_quick_categories(int $limit = 6): array
    {
        // Keyed by limit: the memo is per-request, and a caller asking for a
        // different count must not be served the first caller's slice.
        static $memo = [];

        if (isset($memo[$limit])) {
            return $memo[$limit];
        }

        $key = 'header_quick_cats_' . $limit;

        try {
            $cached = cache()->get($key);
            if (is_array($cached)) {
                return $memo[$limit] = $cached;
            }
        } catch (\Throwable $e) {
            log_message('warning', 'Header category cache unavailable, reading through: ' . $e->getMessage());
        }

        try {
            $cats = (new \App\Services\DirectoryService())->topCategories($limit);
        } catch (\Throwable $e) {
            log_message('error', 'Could not read header categories, dropping the strip: ' . $e->getMessage());

            return $memo[$limit] = [];
        }

        try {
            // Ten minutes. The strip is the busiest categories on the site and
            // that ordering moves over weeks, not minutes — but it is also two
            // queries on every page render, which is what the TTL is really for.
            cache()->save($key, $cats, 600);
        } catch (\Throwable $e) {
            log_message('warning', 'Could not cache header categories: ' . $e->getMessage());
        }

        return $memo[$limit] = $cats;
    }
}

if (! function_exists('category_group_style')) {
    /**
     * How a category group is drawn: its icon and its colour.
     *
     * Categories carry neither an icon nor a colour column, so this map is the
     * whole system. It must cover every group DirectoryCategoriesSeeder creates
     * — anything missing falls through to a generic grey folder and looks like
     * an oversight on the homepage tiles.
     *
     * Both halves are names, never markup, and each has a counterpart that has
     * to exist elsewhere or it fails quietly:
     *
     *   icon  a Lucide name, which must also be in ICONS in
     *         scripts/sync-icons.js — lucide() throws if it is not.
     *   tint  a class defined in resources/directory.css. A tint with no
     *         matching .cat-tint-* rule is NOT an error: the consumers all
     *         fall back to navy, so the tile renders looking merely unstyled.
     *         That is the failure worth checking for by eye when adding a group.
     *
     * Kept as one map rather than two because a group's icon and its colour are
     * one design decision, and splitting them is how they drift apart.
     *
     * @return array{icon: string, tint: string}
     */
    function category_group_style(?string $group): array
    {
        static $map = [
            'Health & Medical'      => ['stethoscope',     'cat-tint-red'],
            'Beauty & Wellness'     => ['sparkles',        'cat-tint-pink'],
            'Hair'                  => ['scissors',        'cat-tint-fuchsia'],
            'Motoring'              => ['car',             'cat-tint-blue'],
            'Legal & Financial'     => ['scale',           'cat-tint-indigo'],
            'Home & Trades'         => ['wrench',          'cat-tint-amber'],
            'Professional Services' => ['briefcase',       'cat-tint-purple'],
            'Fitness & Sport'       => ['dumbbell',        'cat-tint-orange'],
            'Education & Training'  => ['graduation-cap',  'cat-tint-violet'],
            'Events & Hospitality'  => ['party-popper',    'cat-tint-yellow'],
            'Restaurants & Food'    => ['utensils',        'cat-tint-rose'],
            'Travel & Tourism'      => ['plane',           'cat-tint-sky'],
            'Pets & Animals'        => ['paw-print',       'cat-tint-teal'],
            'Everyday Services'     => ['washing-machine', 'cat-tint-cyan'],
            'Retail & Other'        => ['shopping-bag',    'cat-tint-emerald'],
            // Was missing since the group was added, so every home-page tile for
            // a home baker rendered the generic folder — exactly what the note
            // above warns about.
            'Home Industry & Handmade' => ['cake-slice',   'cat-tint-lime'],
        ];

        // Grey for an unmapped group, deliberately: it should look like nothing
        // rather than borrow a real group's colour and read as a miscategorised
        // listing. slate is reserved for exactly this and given to no group —
        // Professional Services had it and looked like a styling bug rather
        // than a decision, which is the same reason nothing else gets it.
        [$icon, $tint] = $map[(string) $group] ?? ['folder', 'cat-tint-slate'];

        return ['icon' => $icon, 'tint' => $tint];
    }
}

if (! function_exists('category_photo')) {
    /**
     * The photograph behind a category's homepage tile, or null for none.
     *
     * The homepage shows whichever eight categories are busiest, so which ones
     * appear is data, not a design decision — a photo per category would be ~180
     * of them. Instead every group has one, which guarantees a tile always has a
     * picture, and the categories most likely to be on the homepage get their own
     * so that two tiles from one group (a dentist beside a GP) do not repeat.
     * Lookup is slug first, then group; null only for a group not in the map,
     * which the tile draws the old icon way.
     *
     * The biggest groups have more than one photo, because two of their generic
     * categories on the homepage at once is the ordinary case (Health & Medical
     * alone has 41). $taken is the list of srcs already on the page: a group
     * photo in it is skipped for the group's next one, and only when every one is
     * taken does a photo repeat. Use category_photos() for a whole grid rather
     * than threading $taken by hand.
     *
     * The files are committed under public/assets/categories/ as <name>-800.webp
     * and <name>-400.webp: Pexels photographs, re-encoded, exactly like the hero
     * photos in DirectoryHeroImagesSeeder. The licence needs no attribution; the
     * credit is kept here so a photo can always be traced back. A name with no
     * file renders a broken image over the tile — CategoryPhotoTest checks every
     * entry against the disk, and that every seeded group has one.
     *
     * @return array{src: string, src_sm: string, credit: string, credit_url: string}|null
     */
    function category_photo(array $category, array $taken = []): ?array
    {
        // slug => [photographer, Pexels id]; the file is named after the slug.
        static $bySlug = [
            'dentist'            => ['Arda Kaykısız', 19976604],
            'physiotherapist'    => ['Ryutaro Tsukata', 5473182],
            'nail-bar'           => ['RDNE Stock project', 7755236],
            'beauty-salon'       => ['Fall Fall', 19242406],
            'barber'             => ['RDNE Stock project', 7697364],
            'financial-adviser'  => ['Kindel Media', 7979438],
            'accountant'         => ['RDNE Stock project', 7491011],
            'electrician'        => ['ranjeet .', 27928760],
            'photographer'       => ['Shantanu Kumar', 16597255],
            'estate-agent'       => ['Alena Darmel', 7641899],
            'training-provider'  => ['Matheus Bertelli', 18999540],
            'dj-entertainment'   => ['Erik Mclean', 9271241],
            'coffee-shop'        => ['Chevanon Photography', 302896],
            'restaurant'         => ['Anna Tarazevich', 6937464],
            'tailor-alterations' => ['Tima Miroshnichenko', 6765514],
        ];

        // group => list of [file name, photographer, Pexels id], in order of
        // preference. Same keys as category_group_style(), and for the same
        // reason: it must cover every group the seeder creates.
        static $byGroup = [
            'Health & Medical' => [
                ['group-health-medical', 'Tessy Agbonome', 18828741],
                ['group-health-medical-2', 'Laura James', 6097750],
            ],
            'Beauty & Wellness' => [
                ['group-beauty-wellness', 'Jonathan Borba', 19641835],
                ['group-beauty-wellness-2', 'Ron Lach', 9146364],
            ],
            'Hair'              => [['group-hair', 'cottonbro studio', 3993312]],
            'Motoring'          => [['group-motoring', 'Artem Podrez', 8985455]],
            'Legal & Financial' => [['group-legal-financial', 'Pavel Danilyuk', 8112166]],
            'Home & Trades'     => [
                ['group-home-trades', 'Kindel Media', 8486975],
                ['group-home-trades-2', 'Anıl Karakaya', 6419128],
            ],
            'Professional Services' => [
                ['group-professional-services', 'Ninthgrid', 30688596],
                ['group-professional-services-2', 'Mikhail Nilov', 9301291],
            ],
            'Fitness & Sport'          => [['group-fitness-sport', 'Julia Larson', 6455963]],
            'Education & Training'     => [['group-education-training', 'Tosin Olowoleni', 34162714]],
            'Events & Hospitality'     => [['group-events-hospitality', 'Matheus Bertelli', 16935994]],
            // The restaurant category's own photo, reused rather than a second
            // file: a pizzeria or a pub tile gets a dining room, which is right.
            // Swap in a dedicated group-restaurants-food photo when there is one.
            'Restaurants & Food'       => [['restaurant', 'Anna Tarazevich', 6937464]],
            'Travel & Tourism'         => [['group-travel-tourism', 'Kureng Workx', 13242022]],
            'Pets & Animals'           => [['group-pets-animals', 'Tima Miroshnichenko', 6235244]],
            'Everyday Services'        => [['group-everyday-services', 'Tima Miroshnichenko', 8774376]],
            'Retail & Other'           => [['group-retail-other', 'Sam Lion', 5709656]],
            'Home Industry & Handmade' => [['group-home-industry-handmade', 'Gustavo Fring', 7447297]],
        ];

        $photo = static fn (string $name, string $credit, int $id): array => [
            'src'        => 'assets/categories/' . $name . '-800.webp',
            'src_sm'     => 'assets/categories/' . $name . '-400.webp',
            'credit'     => $credit,
            'credit_url' => 'https://www.pexels.com/photo/' . $id . '/',
        ];

        $slug  = (string) ($category['slug'] ?? '');
        $group = (string) ($category['group_name'] ?? '');

        if (isset($bySlug[$slug])) {
            return $photo($slug, ...$bySlug[$slug]);
        }
        if (! isset($byGroup[$group])) {
            return null;
        }

        $candidates = array_map(static fn (array $entry): array => $photo(...$entry), $byGroup[$group]);
        foreach ($candidates as $candidate) {
            if (! in_array($candidate['src'], $taken, true)) {
                return $candidate;
            }
        }

        return $candidates[0];
    }
}

if (! function_exists('category_photos')) {
    /**
     * category_photo() for every tile of one grid, in order, so that no two tiles
     * share a group photo while the group has another to give.
     *
     * @param list<array> $categories
     *
     * @return list<array{src: string, src_sm: string, credit: string, credit_url: string}|null>
     */
    function category_photos(array $categories): array
    {
        $taken  = [];
        $photos = [];
        foreach ($categories as $category) {
            $photo    = category_photo($category, $taken);
            $photos[] = $photo;
            if ($photo !== null) {
                $taken[] = $photo['src'];
            }
        }

        return $photos;
    }
}

if (! function_exists('category_group_icon')) {
    /** The Lucide icon name for a category group. See category_group_style(). */
    function category_group_icon(?string $group): string
    {
        return category_group_style($group)['icon'];
    }
}

if (! function_exists('category_group_tint')) {
    /**
     * The colour class for a category group, e.g. 'cat-tint-red'.
     *
     * Returned as a whole literal class name and never concatenated, for the
     * same reason _location_card.php spells out its four gradients: Tailwind
     * scans these files as plain text, so a name assembled at runtime is
     * unmatched and tree-shaken out of the built stylesheet.
     */
    function category_group_tint(?string $group): string
    {
        return category_group_style($group)['tint'];
    }
}

if (! function_exists('vertical_profile')) {
    /**
     * What this category calls things: headings, nouns, CTA and panel order.
     *
     * A thin wrapper over Config\Verticals, for the same reason
     * category_group_tint() wraps category_group_style() — the views ask one
     * function rather than reaching into a config object, and the fallback for a
     * category with no group (category_id is nullable) lives in one place.
     *
     * Always returns a complete key set, so a view can index straight in:
     * $v['headings']['services'] is safe for every one of the 160 categories,
     * mapped or not.
     *
     * @return array<string,mixed>
     */
    function vertical_profile(?string $group, ?string $categorySlug = null): array
    {
        return config('Verticals')->forCategory($group, $categorySlug);
    }
}

if (! function_exists('safe_external_url')) {
    /**
     * A stored URL that is safe to put in an href, or '' if it isn't.
     *
     * esc($url, 'attr') escapes the HTML but not the scheme, so a stored
     * "javascript:…" survives it and runs on click. Writes are normalised by
     * DirectoryListingMutationService::normaliseUrl(), but rows predating that
     * — or written by any future path that forgets — still reach the template,
     * so the check is repeated here where the value actually becomes a link.
     */
    function safe_external_url(?string $url): string
    {
        $url = trim((string) $url);
        if ($url === '' || ! preg_match('#^https?://#i', $url)) {
            return '';
        }

        return filter_var($url, FILTER_VALIDATE_URL) === false ? '' : $url;
    }
}

if (! function_exists('listing_image_url')) {
    /**
     * Resolve a stored image path to something an <img src> can use.
     *
     * Uploaded images are stored FCPATH-relative ("assets/listings/x.webp");
     * imported ones can carry an absolute URL. Returns '' for no image so
     * callers can branch on a falsy value.
     */
    function listing_image_url(?string $path): string
    {
        $path = trim((string) $path);
        if ($path === '') {
            return '';
        }

        return preg_match('#^https?://#i', $path) === 1 ? $path : base_url($path);
    }
}

if (! function_exists('listing_is_verified_business')) {
    /**
     * Does this listing currently carry the paid Verified Business badge?
     *
     * The whole test, deliberately: a date comparison against a column already
     * present on every listing row the public queries select. No join, no extra
     * query, and it works identically on a search card, a profile page and the
     * admin table.
     *
     * Evaluating it at render time rather than trusting a stored boolean is what
     * makes the badge honest. If the nightly sweep is wedged, or a subscription
     * cancels between two page loads, the badge stops appearing on the day it
     * was paid to — we are never displaying a claim we are no longer being paid
     * to make. The sweep's job is to catch the database up with what visitors
     * can already see, not the other way round.
     *
     * String comparison is safe here because the column is a DATE: MySQL hands
     * back 'YYYY-MM-DD', which sorts lexicographically exactly as it sorts
     * chronologically.
     *
     * @param array<string,mixed> $listing
     */
    function listing_is_verified_business(array $listing): bool
    {
        $until = trim((string) ($listing['verified_until'] ?? ''));

        return $until !== '' && $until >= date('Y-m-d');
    }
}

if (! function_exists('listing_is_international')) {
    /**
     * Is this listing's address outside South Africa?
     *
     * The render-time half of the International Listing plan. Asks only about
     * geography, not about money — VerificationService::requiresSubscription()
     * adds the "and the plan is switched on" clause, and that is the one a
     * publish decision must use. This is for views that want to say where a
     * business is.
     *
     * @param array<string,mixed> $listing
     */
    function listing_is_international(array $listing): bool
    {
        return ! config('Countries')->isLocal((string) ($listing['country'] ?? ''));
    }
}

if (! function_exists('listing_subscription_active')) {
    /**
     * Is this listing's International Listing subscription paid up?
     *
     * The same shape as listing_is_verified_business() and for the same
     * reasons — one date compare against a column every public query already
     * selects, evaluated at render time so a wedged sweep cannot leave the
     * page claiming something that is no longer true.
     *
     * What differs is the consequence. An expired badge hides a badge; an
     * expired subscription means the listing should not be public at all, and
     * that is enforced by VerificationService::lapseExpired() flipping status
     * to 'unpublished' rather than by every read query learning about
     * countries. So this is for the owner's own dashboard, which has to show
     * someone their subscription state while their listing is down.
     *
     * @param array<string,mixed> $listing
     */
    function listing_subscription_active(array $listing): bool
    {
        $until = trim((string) ($listing['hosting_paid_until'] ?? ''));

        return $until !== '' && $until >= date('Y-m-d');
    }
}

if (! function_exists('form_old_value')) {
    /**
     * Flatten one flashed-input value into a string the form can redisplay.
     *
     * Every listing form's $v() closure used to do a bare `(string) $old[$f]`,
     * which is fine until a field arrives as an array. `specializations` is
     * exactly that field: it is a comma-separated text input in the markup, but
     * the mutation service accepts `specializations[]` too, so a POST that
     * sends the array form and then fails validation hit
     * "Array to string conversion" while re-rendering the page — an
     * unauthenticated 500 on the public signup form, reachable with one
     * crafted request.
     *
     * Arrays join on ', ' rather than being dropped, so the visitor gets their
     * input back in the shape the input expects — and it matches how
     * manage_edit.php and admin/edit.php already present stored tags.
     */
    function form_old_value(mixed $value): string
    {
        if (is_array($value)) {
            return implode(', ', array_filter(array_map(
                static fn ($v): string => is_scalar($v) ? trim((string) $v) : '',
                $value
            )));
        }

        return is_scalar($value) ? (string) $value : '';
    }
}

if (! function_exists('listing_rich_text')) {
    /**
     * Render an owner-authored description as HTML.
     *
     * This is the one place in the app that prints listing content without
     * esc(), so it re-runs App\Libraries\RichText::sanitise() at render time
     * rather than trusting what is in the column. The write paths already
     * sanitise, and this is the belt to that pair of braces: a row that got
     * there another way — a hand-run UPDATE, an import, a restored backup
     * predating the sanitiser — must not be able to put markup on the page.
     *
     * Yes, that is a DOM parse per profile view. It is bounded (RichText caps
     * nodes and depth), it happens on one page, and the alternative is a
     * guarantee that holds only as long as nothing ever writes to the database
     * except the three services.
     */
    function listing_rich_text(?string $html): string
    {
        return \App\Libraries\RichText::sanitise((string) $html);
    }
}

if (! function_exists('listing_feature_labels')) {
    /**
     * Everything a profile's "Features & amenities" panel lists, as labels.
     *
     * The three capability columns first, then the ticked features that
     * DirectoryService::getProfile() resolved into `attributes`. One list for
     * the panel in show.php and for amenityFeature in the JSON-LD, so the two
     * never describe a business differently.
     *
     * @param array<string,mixed> $l
     * @return array<int,string>
     */
    function listing_feature_labels(array $l): array
    {
        $labels = array_filter([
            ! empty($l['accepts_card_payments']) ? 'Accepts card payments' : null,
            ! empty($l['offers_delivery']) ? 'Delivery / mobile service' : null,
            ! empty($l['offers_online_booking']) ? 'Online booking' : null,
        ]);

        return array_values(array_merge($labels, array_values($l['attributes'] ?? [])));
    }
}

if (! function_exists('facet_range_label')) {
    /**
     * A stored range rendered the way a person says it.
     *
     * Ages are stored in months throughout (see Config\ListingFacets) because
     * one unit is the only way the two ends of a range cannot drift apart — but
     * "72 months to 216 months" is not how anyone describes a high school. This
     * is the single place that conversion happens, so the card, the profile
     * panel and the filter rail can never disagree about what 18 means.
     *
     * A null high bound is a one-sided facet ("fees from R2 500"), not a gap.
     */
    function facet_range_label(?int $low, ?int $high, ?string $unit): string
    {
        if ($low === null && $high === null) {
            return '';
        }

        if ($unit === 'months') {
            $part = static function (?int $m): string {
                if ($m === null) {
                    return '';
                }
                if ($m < 24) {
                    return $m === 1 ? '1 month' : $m . ' months';
                }
                // Whole years read better and are what the form asks for above
                // toddler age; a stray 30 months stays "2 yrs 6 mths" rather
                // than being rounded into a claim the school did not make.
                $years  = intdiv($m, 12);
                $months = $m % 12;

                return $months === 0
                    ? $years . ' years'
                    : $years . ' yrs ' . $months . ' mths';
            };

            if ($low !== null && $high !== null) {
                return $part($low) . ' – ' . $part($high);
            }

            return $low !== null ? 'From ' . $part($low) : 'Up to ' . $part($high);
        }

        $money = static fn (int $n): string => 'R' . number_format($n, 0, '.', ' ');
        $per   = $unit === 'rand-hour' ? ' an hour' : ' a month';

        if ($low !== null && $high !== null) {
            return $money($low) . ' – ' . $money($high) . $per;
        }

        return $low !== null
            ? 'From ' . $money($low) . $per
            : 'Up to ' . $money($high) . $per;
    }
}

if (! function_exists('listing_card_facets')) {
    /**
     * The short facet line a search-result card prints under the category —
     * "Ages 18 months – 6 years · Montessori".
     *
     * Only facets flagged card => true, in config order, capped at three so a
     * school with every field filled in does not outgrow a card next to one
     * with none. Takes the raw stored shape from
     * DirectoryListingFacetModel::forListings() rather than a resolved profile,
     * because a card never loads a profile.
     *
     * @param array<string,list<array{value:string,num_low:?int,num_high:?int}>> $stored
     * @return array<int,string>
     */
    function listing_card_facets(array $stored, ?string $group, ?string $categorySlug): array
    {
        if ($stored === []) {
            return [];
        }

        $out = [];
        foreach (config('ListingFacets')->forCategory($group, $categorySlug) as $key => $facet) {
            if (($facet['card'] ?? false) !== true || ! isset($stored[$key]) || count($out) >= 3) {
                continue;
            }

            if (($facet['type'] ?? '') === 'range') {
                $label = facet_range_label($stored[$key][0]['num_low'] ?? null, $stored[$key][0]['num_high'] ?? null, $facet['unit'] ?? null);
                if ($label !== '') {
                    $out[] = ($facet['unit'] === 'months' ? 'Ages ' : '') . $label;
                }
                continue;
            }

            $chosen = array_flip(array_column($stored[$key], 'value'));
            $labels = array_values(array_intersect_key($facet['options'] ?? [], $chosen));
            if ($labels === []) {
                continue;
            }
            // Two named, then a count: "CAPS, IEB +1" beats a card-wide wrap.
            $out[] = count($labels) > 2
                ? implode(', ', array_slice($labels, 0, 2)) . ' +' . (count($labels) - 2)
                : implode(', ', $labels);
        }

        return $out;
    }
}

if (! function_exists('facet_set_for')) {
    /**
     * Which named set in Config\ListingFacets a category draws its questions
     * from, or '' when it has none.
     *
     * The listing form renders one fieldset per *set* rather than per category —
     * fourteen education categories share three sets — so the category picker
     * needs to name the set, not the category. Kept here rather than in the view
     * so the form and the client-side swap agree on one answer.
     */
    function facet_set_for(?string $group, ?string $categorySlug): string
    {
        $config = config('ListingFacets');

        $refs = $config->byCategory[(string) $categorySlug]['use']
            ?? $config->byGroup[(string) $group]['use']
            ?? [];

        return (string) ($refs[0] ?? '');
    }
}

if (! function_exists('listing_has_facet_rail')) {
    /**
     * Whether this category has anything to put in a filter sidebar.
     *
     * Most categories have no facets at all, and _facet_filters.php returns
     * early for them — so a results page that laid itself out in two columns
     * regardless would show an empty 20rem gutter on the great majority of
     * landing pages. The views ask this before wrapping, and the partial's own
     * early return is the same test, so the layout and the panel cannot
     * disagree about whether there is a sidebar.
     *
     * Null (an unresolved or absent category) is a no: "IEB" is not a question
     * you can ask of every business in the country.
     *
     * @param array<string,mixed>|null $category a resolved directory_categories row
     */
    function listing_has_facet_rail(?array $category): bool
    {
        if ($category === null) {
            return false;
        }

        return config('ListingFacets')->filterableFor(
            $category['group_name'] ?? null,
            $category['slug'] ?? null
        ) !== [];
    }
}

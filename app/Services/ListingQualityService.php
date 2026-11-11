<?php

namespace App\Services;

use App\Libraries\ListingText;
use App\Libraries\RichText;
use App\Models\DirectoryListingModel;

/**
 * How complete a listing's profile is, as a number out of 100.
 *
 * The ONE source of truth for both halves of the feature: the number that
 * orders search results (DirectoryService::browse()) and the checklist the
 * owner sees on /business (directory/_strength_panel.php). evaluate()
 * returns both from the same array, so the meter cannot promise points the
 * ranking does not pay, and adding a rubric line makes it appear in the UI
 * with no view change.
 *
 * ── The rule that binds this file ──────────────────────────────────────────
 *
 * NOTHING behind the paid Verified Business badge may score. Not team members,
 * not extra branches, not verified_until, not hosting_paid_until.
 * _plan_cards.php, _verification_pitch.php and faq.php all tell the public that
 * paying never moves a business up the results, and the only way that stays
 * true is if this class cannot see anything money buys. That is why it opens
 * the free child tables — photos, services, attributes, tags, facets and the
 * uploaded menu — and not team or branches. Adding one that
 * TeamMemberService::canManage() gates would make the ranking purchasable,
 * which is the one thing the directory has promised it is not.
 *
 * ListingQualityTest::testPayingForTheBadgeDoesNotMoveTheScore() pins this.
 *
 * ── What scores zero, on purpose ───────────────────────────────────────────
 *
 *  - Everything badge-gated, per the rule above. is_verified joins them for a
 *    different reason: it is email confirmation, true for every published row,
 *    so scoring it would be a constant.
 *  - Fields required at signup anyway — display_name, email, country, type.
 *    A point everybody has is not a point. postal_code was one of these until
 *    it became optional; it still scores zero, since a customer finds the door
 *    by street and suburb, not by code.
 *  - title and position: "for our records", never shown, and compulsory on
 *    both write paths. contact_person is the exception since 11 Nov 2026: it
 *    is compulsory too, but "Unlimited Carpets & Flooring" (#3164) showed
 *    that compulsory is not the same as answered, so section E pays only for
 *    a real person's name (ListingText::personNameProblem()).
 *  - accepts_card_payments, offers_delivery, offers_online_booking: one click,
 *    unverifiable. If they scored, every listing would tick all three within a
 *    month and the rubric would carry three dead points.
 *  - credentials: a physiotherapist has some, a plumber has none. Scoring it
 *    would depress whole categories for something they cannot fix.
 *  - phone_alt, address_line_2, suburb: marginal.
 *  - social_* and whatsapp on their own. Since 2026-10-08 they share the
 *    website line instead (see contactItems()): a business with no website is
 *    told its profile IS its website, so the website points go to having any second
 *    place to reach you — a website, WhatsApp or a social page — and never
 *    more than once.
 *
 * ── Calibration ────────────────────────────────────────────────────────────
 *
 * Reweighted 11 Nov 2026. Since 7b07acd signup also forces hours, a service
 * and a picture, so the address, hours and first service lines had become
 * points nearly everybody has, and #3164 reached 86 (Excellent) with no
 * features, no specialisations and a booking "link" that was its own
 * website's #contact anchor. Section A dropped from 25 to 14, hours from 7
 * to 5, and the points moved to what owners skip: specialisations (12),
 * features (8), a sixth photo and the contact person (5). #3164 now scores 62.
 *
 * A bare signup (place, contact person, hours, one service, one photo) lands
 * near 30; a phone and a 120 character paragraph take it to about 44. Good
 * and Excellent also need BREADTH_MIN. Three bars read the number (all in
 * Config\Directory, all tunable per server):
 *  - $indexMinQuality: offered to Google;
 *  - $recentMinQuality: the home page's "Recently added" strip;
 *  - $qualityTarget: what owners are told to aim for, and the line the
 *    one-off nudge email (ProfileNudgeService) is sent under.
 * None of them gates publishing.
 */
class ListingQualityService
{
    public const MAX_SCORE = 100;

    // ── A. Can I place you? — 14 ───────────────────────────────────────────
    public const PTS_CATEGORY = 4;
    public const PTS_ADDRESS  = 4;
    public const PTS_CITY     = 2;
    public const PTS_PROVINCE = 2;
    public const PTS_MAP_PIN  = 2;

    // ── B. Can I reach you? — 16 ───────────────────────────────────────────
    public const PTS_PHONE   = 8;
    public const PTS_WEBSITE = 5;
    public const PTS_BOOKING = 3;

    // ── C. Can I size you up? — 28 ─────────────────────────────────────────
    public const PTS_DESCRIPTION_SHORT   = 6;
    public const PTS_DESCRIPTION_FULL    = 12;
    public const DESCRIPTION_SHORT_CHARS = 120;
    public const DESCRIPTION_FULL_CHARS  = 300;
    public const PTS_LOGO                = 4;
    public const PTS_PHOTO               = 2;
    public const CAP_PHOTOS              = 6;

    // ── D. Do you answer the question? — 37 ────────────────────────────────
    public const PTS_SERVICE    = 3;
    public const CAP_SERVICES   = 4;
    public const PTS_HOURS      = 5;
    public const HOURS_MIN_DAYS = 3;
    public const PTS_ATTRIBUTE  = 2;
    public const CAP_ATTRIBUTES = 4;
    public const PTS_TAG        = 3;
    public const CAP_TAGS       = 4;

    // ── E. Is there someone to speak to? — 5 ───────────────────────────────
    public const PTS_CONTACT_PERSON = 5;

    /**
     * What Good and Excellent ask for on top of the number: every part of
     * section D touched. A profile can reach 80 on the parts signup nearly
     * forces and a long description, and still say nothing about what it
     * specialises in (#3164, 11 Nov 2026). The label is capped, the score
     * and so the ranking are not.
     */
    public const BREADTH_MIN = ['services' => 3, 'features' => 1, 'tags' => 1];

    /** The band a profile without that breadth stops at. */
    private const BREADTH_CAP_BAND = 'Getting there';

    /** Band thresholds, lowest first. The view reads the label, never the maths. */
    private const BANDS = [
        [80, 'Excellent'],
        [60, 'Good'],
        [40, 'Getting there'],
        [0, 'Needs work'],
    ];

    private DirectoryListingModel $listings;

    public function __construct()
    {
        $this->listings = new DirectoryListingModel();
        helper('directory_hours');
    }

    /**
     * The rubric applied to one listing.
     *
     * Pure — runs no queries at all — when $counts is supplied, which is how
     * Manage::edit() renders the meter for free off rows it has already loaded.
     *
     * @param array<string,mixed>                                          $listing a listings row
     * @param array{photos:int,services:int,attributes:int,tags:int,facets?:int,menu?:int}|null  $counts
     *
     * @return array{score:int,max:int,band:string,percent:int,items:list<array{
     *     key:string,label:string,points:int,earned:int,done:bool,hint:string,anchor:string}>}
     */
    public function evaluate(array $listing, ?array $counts = null): array
    {
        $counts ??= $this->countsFor((int) ($listing['id'] ?? 0));

        $items = array_merge(
            $this->placeItems($listing),
            $this->contactItems($listing),
            $this->substanceItems($listing, $counts),
            $this->specificsItems($listing, $counts),
            $this->recordsItems($listing),
        );

        $score = 0;
        foreach ($items as $item) {
            $score += $item['earned'];
        }

        return [
            'score'   => $score,
            'max'     => self::MAX_SCORE,
            'percent' => (int) round($score / self::MAX_SCORE * 100),
            'band'    => $this->band($score, $items),
            'items'   => $items,
        ];
    }

    /**
     * Just the number.
     *
     * @param array<string,mixed>                                         $listing
     * @param array{photos:int,services:int,attributes:int,tags:int,facets?:int,menu?:int}|null $counts
     */
    public function score(array $listing, ?array $counts = null): int
    {
        return $this->evaluate($listing, $counts)['score'];
    }

    /**
     * Everything evaluate() returns, plus the highest-value unfinished steps.
     *
     * The view renders `next` and contains no field names, point values or
     * thresholds of its own — that is mechanism 1 of the three that stop the
     * rubric and the UI drifting apart.
     *
     * @param array<string,mixed>                                         $listing
     * @param array{photos:int,services:int,attributes:int,tags:int,facets?:int,menu?:int}|null $counts
     *
     * @return array{score:int,max:int,band:string,percent:int,items:list<array<string,mixed>>,next:list<array<string,mixed>>}
     */
    public function strength(array $listing, ?array $counts = null, int $steps = 4): array
    {
        $result = $this->evaluate($listing, $counts);

        $todo = array_values(array_filter(
            $result['items'],
            static fn (array $i): bool => ! $i['done']
        ));

        // Biggest win first. usort is not stable across the outstanding items
        // in a way worth relying on, so ties fall back to the rubric's own
        // order, which is the order a searcher cares about them in.
        usort($todo, static fn (array $a, array $b): int => ($b['points'] - $b['earned']) <=> ($a['points'] - $a['earned']));

        $result['next'] = array_slice($todo, 0, max(0, $steps));

        return $result;
    }

    /**
     * strength() for a profile that does not exist yet: the live score on
     * /add-profile, recomputed from the unsaved form as the visitor types.
     *
     * The columns come from DirectoryListingMutationService::draftColumns(),
     * the code the real signup writes the row with, and the counts from the
     * same services that write the child rows. So the number shown while
     * typing is the number the saved profile gets, and nothing here is a
     * second copy of the rubric. Files cannot sensibly ride along on every
     * keystroke pause, so the browser reports how many it has picked instead.
     *
     * The map pin counts only once the form has coordinates (an address
     * picked from the suggestions, or the pin dragged). The save may still
     * geocode a typed address, but promising that here would be a guess.
     *
     * @param array<string,mixed>                       $input the posted form
     * @param array{logo?:mixed,photos?:mixed,menu?:mixed} $files counts from the browser
     *
     * @return array{score:int,max:int,band:string,percent:int,items:list<array<string,mixed>>,next:list<array<string,mixed>>}
     */
    public function strengthForDraft(array $input, array $files = [], int $steps = PHP_INT_MAX): array
    {
        $mutation   = new DirectoryListingMutationService();
        $listing    = $mutation->draftColumns($input);
        $categoryId = $listing['category_id'] ?? null;

        $coord = static fn (mixed $v): ?string => is_scalar($v) && is_numeric(trim((string) $v)) ? trim((string) $v) : null;
        $listing['latitude']  = $coord($input['latitude'] ?? null);
        $listing['longitude'] = $coord($input['longitude'] ?? null);

        // Any non-empty marker will do: the rubric asks only whether there is one.
        if ($this->fileCount($files['logo'] ?? 0) > 0) {
            $listing['logo_path'] = 'draft-logo';
        }

        $menuService = new ServiceMenuService();
        $facets      = (new ListingFacetService())->groupedFromInput($categoryId, $input['facets'] ?? null);

        return $this->strength($listing, [
            'photos'     => $this->fileCount($files['photos'] ?? 0),
            'services'   => $menuService->countNamedServices($input['services'] ?? null),
            'attributes' => count($menuService->allowedKeys($categoryId, $input['attributes'] ?? null)),
            'tags'       => $mutation->draftTagCount($input),
            'facets'     => array_sum(array_map('count', $facets)),
            'menu'       => $this->fileCount($files['menu'] ?? 0),
        ], $steps);
    }

    /** A file count the browser reported, clamped to something sane. */
    private function fileCount(mixed $raw): int
    {
        return is_scalar($raw) ? max(0, min(50, (int) $raw)) : 0;
    }

    /**
     * Recompute one listing from the database and persist the result.
     *
     * Returns the new score, or 0 for a listing that no longer exists.
     */
    public function recalculate(int $listingId): int
    {
        if ($listingId <= 0) {
            return 0;
        }

        $listing = $this->listings->find($listingId);
        if (! is_array($listing)) {
            return 0;
        }

        $score = $this->score($listing, $this->countsFor($listingId));
        $this->persist([$listingId => $score]);

        return $score;
    }

    /**
     * Recompute a batch, using four grouped COUNT queries per chunk rather
     * than four per listing.
     *
     * Deliberately NOT one set-based `UPDATE ... JOIN (SELECT ... COUNT(*))`,
     * which is the obvious optimisation and the wrong one: it would be a
     * second copy of the rubric written in SQL, and there would then be three
     * copies — this class, that statement, and the owner-facing meter — to
     * keep in step. Slow at 3am is cheaper than a rubric that disagrees with
     * itself.
     *
     * @param list<int> $listingIds
     *
     * @return array<int,int> id => score
     */
    public function recalculateMany(array $listingIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $listingIds))));
        if ($ids === []) {
            return [];
        }

        $scores = [];

        foreach (array_chunk($ids, 500) as $chunk) {
            $rows   = $this->listings->whereIn('id', $chunk)->findAll();
            $counts = $this->countsForMany($chunk);

            foreach ($rows as $row) {
                $id          = (int) $row['id'];
                $scores[$id] = $this->score($row, $counts[$id] ?? $this->emptyCounts());
            }

            $this->persist($scores);
        }

        return $scores;
    }

    /**
     * Child-row counts for one listing. The tables here are the whole
     * list, and it is short for the reason the class docblock gives.
     *
     * @return array{photos:int,services:int,attributes:int,tags:int,facets:int,menu:int}
     */
    public function countsFor(int $listingId): array
    {
        if ($listingId <= 0) {
            return $this->emptyCounts();
        }

        return $this->countsForMany([$listingId])[$listingId] ?? $this->emptyCounts();
    }

    /**
     * @param list<int> $ids
     *
     * @return array<int,array{photos:int,services:int,attributes:int,tags:int,facets:int,menu:int}>
     */
    public function countsForMany(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if ($ids === []) {
            return [];
        }

        $out = [];
        foreach ($ids as $id) {
            $out[$id] = $this->emptyCounts();
        }

        $tables = [
            'photos'     => 'xs_directory_listing_photos',
            'services'   => 'xs_directory_listing_services',
            'attributes' => 'xs_directory_listing_attributes',
            'tags'       => 'xs_directory_listing_tags',
            'facets'     => 'xs_directory_listing_facets',
            'menu'       => 'xs_directory_listing_menu_files',
        ];

        $db = $this->listings->db;

        foreach ($tables as $key => $table) {
            $rows = $db->table($table)
                ->select('listing_id, COUNT(*) AS c')
                ->whereIn('listing_id', $ids)
                ->groupBy('listing_id')
                ->get()
                ->getResultArray();

            foreach ($rows as $row) {
                $out[(int) $row['listing_id']][$key] = (int) $row['c'];
            }
        }

        return $out;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // The rubric
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * A. Can I place you? Without these a listing is absent from the category
     * pages, the province filter, the map and half of search.
     *
     * @param array<string,mixed> $listing
     *
     * @return list<array<string,mixed>>
     */
    private function placeItems(array $listing): array
    {
        // province OR region — never both. They are mutually exclusive by
        // construction (see AddRegionToListings): a South African listing has
        // one, everything else has the other. Awarding both would hand a
        // listing with stale data in the unused column three free points.
        $hasArea = $this->filled($listing, 'province') || $this->filled($listing, 'region');

        return [
            $this->flag(
                'category',
                'A category',
                self::PTS_CATEGORY,
                (int) ($listing['category_id'] ?? 0) > 0,
                'Without one you are missing from every category page and filter.',
                'field-category'
            ),
            $this->flag(
                'address',
                'A street address',
                self::PTS_ADDRESS,
                $this->filled($listing, 'address_line'),
                'A profile without an address is hard to find on a map.',
                'field-address'
            ),
            $this->flag(
                'city',
                'Your city or town',
                self::PTS_CITY,
                $this->filled($listing, 'city'),
                'Drives the city filter and the place line on your card.',
                'field-address'
            ),
            $this->flag(
                'province',
                'Your province or region',
                self::PTS_PROVINCE,
                $hasArea,
                'Puts you on the right province page.',
                'field-address'
            ),
            $this->flag(
                'map_pin',
                'A pin on the map',
                self::PTS_MAP_PIN,
                $listing['latitude'] !== null && $listing['longitude'] !== null
                    && $listing['latitude'] !== '' && $listing['longitude'] !== '',
                'Usually fills itself in from your address; you can also drag it.',
                'field-map'
            ),
        ];
    }

    /**
     * B. Can I reach you? The whole point of looking someone up.
     *
     * @param array<string,mixed> $listing
     *
     * @return list<array<string,mixed>>
     */
    private function contactItems(array $listing): array
    {
        return [
            $this->flag(
                'phone',
                'A phone number',
                self::PTS_PHONE,
                $this->filled($listing, 'phone'),
                'One line, and it is the thing most people click.',
                'field-phone'
            ),
            // A website is not required: the profile is meant to stand in for
            // one, and the signup form says so. So this line pays for any one
            // second way to reach you, and a business without a website earns
            // it with WhatsApp or a social page. Key and anchor stay 'website'
            // so stored checklists and #field-website links keep working.
            $this->flag(
                'website',
                'A website, WhatsApp or social page',
                self::PTS_WEBSITE,
                $this->hasOnlinePresence($listing),
                'No website needed, a WhatsApp number or a Facebook, Instagram, LinkedIn or TikTok page earns these points too.',
                'field-website'
            ),
            // The offers_online_booking checkbox scores nothing on its own —
            // anyone can tick it. The URL is the artefact, and it has to be a
            // page of its own: see hasRealBooking().
            $this->flag(
                'booking',
                'An online booking link',
                self::PTS_BOOKING,
                $this->hasRealBooking($listing),
                'A page where people can book you, not your home page.',
                'booking_url'
            ),
        ];
    }

    /**
     * C. Can I size you up? What someone reads before deciding to call.
     *
     * @param array<string,mixed>                                    $listing
     * @param array{photos:int,services:int,attributes:int,tags:int,facets?:int,menu?:int} $counts
     *
     * @return list<array<string,mixed>>
     */
    private function substanceItems(array $listing, array $counts): array
    {
        $length = mb_strlen($this->plainDescription($listing));

        // Two tiers rather than a curve, and the full tier sits at 300 — 30% of
        // RichText::MAX_PLAIN_LENGTH — so there is nothing to gain from padding
        // to the cap.
        $description = $length >= self::DESCRIPTION_FULL_CHARS
            ? self::PTS_DESCRIPTION_FULL
            : ($length >= self::DESCRIPTION_SHORT_CHARS ? self::PTS_DESCRIPTION_SHORT : 0);

        $descriptionHint = $length >= self::DESCRIPTION_SHORT_CHARS
            ? 'Another sentence or two earns the rest of the points.'
            : 'A short paragraph about what you do and who you do it for.';

        return [
            [
                'key'    => 'description',
                'label'  => 'A description',
                'points' => self::PTS_DESCRIPTION_FULL,
                'earned' => $description,
                'done'   => $description === self::PTS_DESCRIPTION_FULL,
                'hint'   => $descriptionHint,
                'anchor' => 'description',
            ],
            $this->flag(
                'logo',
                'A logo',
                self::PTS_LOGO,
                $this->filled($listing, 'logo_path'),
                'Your card shows initials without one.',
                'logo-input'
            ),
            // Caps at 6 of the 8 the gallery allows, so the last two are for
            // your own profile rather than for rank.
            $this->countable(
                'photos',
                'Photos',
                $counts['photos'],
                self::CAP_PHOTOS,
                self::PTS_PHOTO,
                'Photos do more for a profile than anything else on the form.',
                'gallery-input'
            ),
        ];
    }

    /**
     * D. Do you answer the question the searcher actually typed?
     *
     * @param array<string,mixed>                                    $listing
     * @param array{photos:int,services:int,attributes:int,tags:int,facets?:int,menu?:int} $counts
     *
     * @return list<array<string,mixed>>
     */
    private function specificsItems(array $listing, array $counts): array
    {
        return [
            // Four named services answers "do you do X, and roughly what does
            // it cost". Thirty is a menu dump, so the cap is well under
            // ServiceMenuService::MAX_SERVICES.
            //
            // An uploaded menu (ListingMenuService — food listings only) answers
            // the same question, so each file shares this cap rather than
            // getting a bucket of its own, for the reason the features line
            // below gives: sharing a cap can only move a score up.
            $this->countable(
                'services',
                'Services and prices',
                $counts['services'] + ($counts['menu'] ?? 0),
                self::CAP_SERVICES,
                self::PTS_SERVICE,
                'Name the handful of things people actually ask you for.',
                'field-services'
            ),
            $this->flag(
                'hours',
                'Opening hours',
                self::PTS_HOURS,
                // "By appointment only" is a complete answer to the question,
                // and the signup form accepts it in place of times.
                ! empty($listing['by_appointment']) || $this->hoursUsable($listing['trading_hours'] ?? null),
                '"Are they open?" is the second thing anyone wants to know.',
                'field-hours'
            ),
            // Features and tags are one-click and unverifiable, so each has a
            // hard ceiling of four. They were a point each until 11 Nov 2026,
            // which let a profile reach Excellent without either; now they
            // are the points owners most often leave on the table (see the
            // Calibration note), and BREADTH_MIN asks for one of each.
            //
            // Facets (ages, curriculum, fees — Config\ListingFacets) share this
            // allowance rather than getting one of their own, and the choice is
            // deliberate: the rubric has to total exactly MAX_SCORE, the FAQ
            // publishes it, and the home page gates on 40. A new bucket would
            // have to take its points from somewhere, which lowers scores that
            // are already earned and can drop a listing off the home page it
            // was on yesterday. Sharing a *cap* cannot: a + b >= a, so this
            // only ever moves a score up.
            //
            // Sharing is also honest about what these are. Both answer "what is
            // true about you", both are owner-asserted and unverifiable, and a
            // preschool that has stated its ages and curriculum has told a
            // visitor at least as much as one that ticked three tick-boxes.
            //
            // A public holiday answer (directory_hours_helper) counts as one
            // more detail, for the same reason: a line of its own would take
            // points from somewhere and lower scores already earned.
            $this->countable(
                'features',
                'Features and details',
                $counts['attributes'] + ($counts['facets'] ?? 0) + ($this->holidaysAnswered($listing) ? 1 : 0),
                self::CAP_ATTRIBUTES,
                self::PTS_ATTRIBUTE,
                'Parking, wheelchair access, public holiday hours, ages taken, whatever applies.',
                'field-features'
            ),
            $this->countable(
                'tags',
                'Specialisations',
                $counts['tags'],
                self::CAP_TAGS,
                self::PTS_TAG,
                'The specific things you do, in the words a customer would use.',
                'field-tags'
            ),
        ];
    }

    /**
     * E. Is there someone to speak to? The contact person is private, but a
     * profile whose contact is the business itself ("Unlimited Carpets &
     * Flooring", #3164) is one nobody at this end can address, so it pays
     * only for a real person's name, judged by the same rule the form uses.
     *
     * @param array<string,mixed> $listing
     *
     * @return list<array<string,mixed>>
     */
    private function recordsItems(array $listing): array
    {
        $contact = trim((string) ($listing['contact_person'] ?? ''));

        return [
            $this->flag(
                'contact_person',
                'Who we speak to',
                self::PTS_CONTACT_PERSON,
                $contact !== '' && ListingText::personNameProblem(
                    $contact,
                    (string) ($listing['display_name'] ?? ''),
                    (string) ($listing['type'] ?? '')
                ) === null,
                'Your own name, not the name of the business.',
                'field-contact-person'
            ),
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * An all-or-nothing rubric line.
     *
     * @return array<string,mixed>
     */
    private function flag(string $key, string $label, int $points, bool $done, string $hint, string $anchor): array
    {
        return [
            'key'    => $key,
            'label'  => $label,
            'points' => $points,
            'earned' => $done ? $points : 0,
            'done'   => $done,
            'hint'   => $hint,
            'anchor' => $anchor,
        ];
    }

    /**
     * A rubric line that pays per row up to a cap, and reports partial credit
     * so the meter can be honest about half-finished work.
     *
     * @return array<string,mixed>
     */
    private function countable(
        string $key,
        string $label,
        int $have,
        int $cap,
        int $each,
        string $hint,
        string $anchor
    ): array {
        $counted = min(max(0, $have), $cap);
        $short   = $cap - $counted;

        return [
            'key'    => $key,
            'label'  => $label,
            'points' => $cap * $each,
            'earned' => $counted * $each,
            'done'   => $short === 0,
            'hint'   => $short > 0 && $counted > 0
                ? sprintf('%d more %s %d point%s each.', $short, $short === 1 ? 'earns' : 'earn', $each, $each === 1 ? '' : 's')
                : $hint,
            'anchor' => $anchor,
        ];
    }

    private function filled(array $listing, string $field): bool
    {
        return trim((string) ($listing[$field] ?? '')) !== '';
    }

    /**
     * A booking link that is a page of its own. The website itself, or the
     * website plus an anchor ("/#contact", #3164), is a contact form, not a
     * booking page, and earned the points until 11 Nov 2026. Another page
     * on the same site ("/book") still counts.
     */
    private function hasRealBooking(array $listing): bool
    {
        $booking = $this->comparableUrl(listing_website_url($listing['booking_url'] ?? ''));
        if ($booking === '') {
            return false;
        }

        return $booking !== $this->comparableUrl(listing_website_url($listing['website'] ?? ''));
    }

    /** A URL without its scheme, www., #fragment and trailing slash, lower case. */
    private function comparableUrl(string $url): string
    {
        $url = mb_strtolower(trim($url));
        $url = (string) preg_replace('~#.*$~', '', $url);
        $url = (string) preg_replace('~^[a-z]+://(www\.)?~', '', $url);

        return rtrim($url, '/');
    }

    /**
     * A website, a WhatsApp number or any social page — whichever is filled.
     * The social columns come from listing_social_networks(), so a network
     * added there counts here with no change.
     */
    private function hasOnlinePresence(array $listing): bool
    {
        // The website through listing_website_url(), so a link back to this
        // site does not count as one.
        if (listing_website_url($listing['website'] ?? '') !== '') {
            return true;
        }
        foreach (['whatsapp', ...array_keys(listing_social_networks())] as $field) {
            if ($this->filled($listing, $field)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The description as plain text.
     *
     * description_text is the derived column and the fast path, but listings
     * saved before AddDescriptionTextToListings have HTML in `description` and
     * nothing in it. Falling back matters more than it looks: without this the
     * backfill under-scores exactly the oldest, most-established listings,
     * which is the opposite of what the feature is for.
     *
     * @param array<string,mixed> $listing
     */
    private function plainDescription(array $listing): string
    {
        $text = trim((string) ($listing['description_text'] ?? ''));
        if ($text !== '') {
            return $text;
        }

        $html = trim((string) ($listing['description'] ?? ''));

        return $html === '' ? '' : trim(RichText::toPlainText($html));
    }

    /**
     * Whether stored trading hours actually tell a visitor anything.
     *
     * hours_encode() always writes all seven days, so a form that was rendered
     * and never filled in stores seven blank rows — "not empty" is therefore
     * not the same as "configured". A day counts when it is explicitly closed
     * or has both times, and at least one day has to be genuinely open, so a
     * business marked shut all week does not score for it.
     */
    /** Whether the owner has said what they do on public holidays. */
    private function holidaysAnswered(array $listing): bool
    {
        $stored = $listing['trading_hours'] ?? null;

        return is_string($stored) && hours_holiday_rule($stored) !== null;
    }

    private function hoursUsable(mixed $stored): bool
    {
        $hours = hours_decode(is_string($stored) ? $stored : null);
        if ($hours === null) {
            return false;
        }

        $configured = 0;
        $openDays   = 0;

        foreach ($hours as $day) {
            $open  = trim((string) ($day['open'] ?? ''));
            $close = trim((string) ($day['close'] ?? ''));

            if (! empty($day['closed'])) {
                $configured++;

                continue;
            }
            if ($open !== '' && $close !== '') {
                $configured++;
                $openDays++;
            }
        }

        return $configured >= self::HOURS_MIN_DAYS && $openDays >= 1;
    }

    /**
     * The band label for a stored score, for screens that list many rows and
     * only have quality_score to hand (the admin profile list). It cannot see
     * the breadth rule (BREADTH_MIN needs the child counts), so a list row can
     * read Good where the profile's own panel reads Getting there.
     */
    public function bandFor(int $score): string
    {
        return $this->band($score);
    }

    /** @param list<array<string,mixed>>|null $items evaluate()'s items, for the breadth rule */
    private function band(int $score, ?array $items = null): string
    {
        $label = 'Needs work';
        foreach (self::BANDS as [$floor, $name]) {
            if ($score >= $floor) {
                $label = $name;
                break;
            }
        }

        if ($items === null || ! in_array($label, ['Excellent', 'Good'], true)) {
            return $label;
        }

        $earned = array_column($items, 'earned', 'key');
        $each   = ['services' => self::PTS_SERVICE, 'features' => self::PTS_ATTRIBUTE, 'tags' => self::PTS_TAG];
        foreach (self::BREADTH_MIN as $key => $min) {
            if (intdiv((int) ($earned[$key] ?? 0), $each[$key]) < $min) {
                return self::BREADTH_CAP_BAND;
            }
        }

        return $label;
    }

    /**
     * Write scores without touching updated_at.
     *
     * A bare builder update rather than the model, deliberately: the model has
     * useTimestamps, and business/profile.php keys its draft versioning off
     * updated_at. A rescore is not an edit, and bumping it would make an
     * owner's open tab think someone else had saved over them.
     *
     * @param array<int,int> $scores id => score
     */
    private function persist(array $scores): void
    {
        if ($scores === []) {
            return;
        }

        $now   = date('Y-m-d H:i:s');
        $table = $this->listings->db->table('xs_directory_listings');

        foreach ($scores as $id => $score) {
            $table->where('id', $id)->update([
                'quality_score'     => max(0, min(self::MAX_SCORE, $score)),
                'quality_scored_at' => $now,
            ]);
        }
    }

    /** @return array{photos:int,services:int,attributes:int,tags:int,facets:int,menu:int} */
    private function emptyCounts(): array
    {
        return ['photos' => 0, 'services' => 0, 'attributes' => 0, 'tags' => 0, 'facets' => 0, 'menu' => 0];
    }
}

<?php

namespace App\Services;

use App\Libraries\RichText;
use App\Models\DirectoryListingModel;

/**
 * How complete a listing's profile is, as a number out of 100.
 *
 * The ONE source of truth for both halves of the feature: the number that
 * orders search results (DirectoryService::browse()) and the checklist the
 * owner sees on /manage/edit (directory/_strength_panel.php). evaluate()
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
 *  - Fields required at signup anyway — display_name, email, postal_code,
 *    country, type. A point everybody has is not a point.
 *  - contact_person and title: they sit in the "for our records" fieldset and
 *    are never shown publicly, so they do nothing for a searcher. They are also
 *    compulsory on both write paths now, which puts them in the bracket above
 *    as well — a point everybody has is not a point.
 *  - accepts_card_payments, offers_delivery, offers_online_booking: one click,
 *    unverifiable. If they scored, every listing would tick all three within a
 *    month and the rubric would carry three dead points.
 *  - credentials: a physiotherapist has some, a plumber has none. Scoring it
 *    would depress whole categories for something they cannot fix.
 *  - phone_alt, address_line_2, suburb: marginal.
 *  - social_facebook/instagram/linkedin: no form writes them today (see the
 *    OWNER_EDITABLE docblock). Revisit if they ever gain one.
 *
 * ── Calibration ────────────────────────────────────────────────────────────
 *
 * A listing forced through signup's REQUIRED_ADDRESS_FIELDS lands at 25.
 * Add a phone and it is 35; add a paragraph and it is 43. So the homepage
 * floor of 40 (Config\Directory::$recentMinQuality) means "a phone number and
 * a paragraph", not "a full afternoon".
 */
class ListingQualityService
{
    public const MAX_SCORE = 100;

    // ── A. Can I place you? — 25 ───────────────────────────────────────────
    public const PTS_CATEGORY = 8;
    public const PTS_ADDRESS  = 6;
    public const PTS_CITY     = 5;
    public const PTS_PROVINCE = 3;
    public const PTS_MAP_PIN  = 3;

    // ── B. Can I reach you? — 20 ───────────────────────────────────────────
    public const PTS_PHONE   = 10;
    public const PTS_WEBSITE = 6;
    public const PTS_BOOKING = 4;

    // ── C. Can I size you up? — 30 ─────────────────────────────────────────
    public const PTS_DESCRIPTION_SHORT   = 8;
    public const PTS_DESCRIPTION_FULL    = 14;
    public const DESCRIPTION_SHORT_CHARS = 120;
    public const DESCRIPTION_FULL_CHARS  = 300;
    public const PTS_LOGO                = 6;
    public const PTS_PHOTO               = 2;
    public const CAP_PHOTOS              = 5;

    // ── D. Do you answer the question? — 25 ────────────────────────────────
    public const PTS_SERVICE    = 3;
    public const CAP_SERVICES   = 4;
    public const PTS_HOURS      = 7;
    public const HOURS_MIN_DAYS = 3;
    public const PTS_ATTRIBUTE  = 1;
    public const CAP_ATTRIBUTES = 3;
    public const PTS_TAG        = 1;
    public const CAP_TAGS       = 3;

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
        );

        $score = 0;
        foreach ($items as $item) {
            $score += $item['earned'];
        }

        return [
            'score'   => $score,
            'max'     => self::MAX_SCORE,
            'percent' => (int) round($score / self::MAX_SCORE * 100),
            'band'    => $this->band($score),
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
                'A directory without addresses is a phone book.',
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
            $this->flag(
                'website',
                'A website link',
                self::PTS_WEBSITE,
                $this->filled($listing, 'website'),
                'Where someone goes for everything we do not store.',
                'field-website'
            ),
            // The offers_online_booking checkbox scores nothing on its own —
            // anyone can tick it. The URL is the artefact.
            $this->flag(
                'booking',
                'An online booking link',
                self::PTS_BOOKING,
                $this->filled($listing, 'booking_url'),
                'Lets someone book you without picking up the phone.',
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
            // Caps at 5 of the 8 the gallery allows, so the last three are for
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
                $this->hoursUsable($listing['trading_hours'] ?? null),
                '"Are they open?" is the second thing anyone wants to know.',
                'field-hours'
            ),
            // Features and tags are one-click and unverifiable, so they are
            // worth a point each with a hard ceiling of three. Tags also
            // already earn a discoverability reward in applySearch(); they do
            // not need a large ranking one on top.
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
            $this->countable(
                'features',
                'Features and details',
                $counts['attributes'] + ($counts['facets'] ?? 0),
                self::CAP_ATTRIBUTES,
                self::PTS_ATTRIBUTE,
                'Parking, wheelchair access, ages taken, curriculum — whatever applies.',
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

    private function band(int $score): string
    {
        foreach (self::BANDS as [$floor, $label]) {
            if ($score >= $floor) {
                return $label;
            }
        }

        return 'Needs work';
    }

    /**
     * Write scores without touching updated_at.
     *
     * A bare builder update rather than the model, deliberately: the model has
     * useTimestamps, and manage_edit.php keys its draft versioning off
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

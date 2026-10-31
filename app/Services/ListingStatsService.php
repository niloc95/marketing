<?php

namespace App\Services;

use App\Controllers\Manage;
use App\Services\Search\DiscoveryEventService;
use CodeIgniter\HTTP\IncomingRequest;

/**
 * How often each profile is seen and contacted: the owner's half of "is my
 * profile working?". The only writer of xs_directory_listing_stats.
 *
 * Daily totals per profile and nothing else. No IP, cookie or session is
 * stored; the one per visitor rule (VIEW_WINDOW) is a throttler bucket keyed
 * on a hash of the IP, which lives in the cache and expires there, as
 * ScrapeGuard's does. Rows go after KEEP_MONTHS (Privacy policy, "Profile
 * statistics").
 *
 * Not counted: crawlers (DiscoveryEventService::isBot()), previews, the admin,
 * and the owner looking at their own profile. Those checks read the session
 * only when the visitor already has a session cookie, so counting never starts
 * a session (or sets a cookie) for an anonymous visitor or a crawler.
 *
 * Writes never throw: a broken table must not cost anyone a profile page.
 * Switch: directory.profileStats = false.
 */
class ListingStatsService
{
    public const KEEP_MONTHS = 25;

    /** A second view from the same visitor inside this window is not counted. */
    public const VIEW_WINDOW = 30 * MINUTE;

    /** What a tap can be, as sent by directory.js (data-stat), to its column. */
    public const TAPS = [
        'call'       => 'taps_call',
        'whatsapp'   => 'taps_whatsapp',
        'website'    => 'taps_website',
        'directions' => 'taps_directions',
        'booking'    => 'taps_booking',
        'email'      => 'taps_email',
    ];

    /** The owner panel's period, and the one before it that it compares with. */
    public const PERIOD_DAYS = 30;

    /** Search Console days shown, ending at the last day Google has finished. */
    public const GOOGLE_DAYS = 28;

    /**
     * Tip thresholds, over PERIOD_DAYS. No tips until counting has run this
     * many days, so a profile is not told it is rarely shown on day two.
     */
    public const TIPS_AFTER_DAYS      = 14;
    public const FEW_APPEARANCES      = 30;
    public const OPEN_RATE_PERCENT    = 3;
    public const ENOUGH_VIEWS         = 15;
    public const CONTACT_RATE_PERCENT = 5;

    private const TABLE = 'directory_listing_stats';

    /**
     * Should this request count for this profile? False for crawlers, the
     * admin and the profile's own owner.
     */
    public static function counts(IncomingRequest $request, int $listingId): bool
    {
        if (! config('Directory')->profileStats || DiscoveryEventService::isBot($request)) {
            return false;
        }

        // Only a session that already exists is read (a cookie came with the
        // request, or something earlier started it): session() would
        // otherwise start one, and set a cookie, for every visitor and crawler.
        if ($request->getCookie(config('Session')->cookieName) !== null || isset($_SESSION)) {
            $session = session();
            if ($session->get('dir_admin') || (int) $session->get(Manage::SESSION_KEY) === $listingId) {
                return false;
            }
        }

        return true;
    }

    /** A profile page opened. Once per visitor per VIEW_WINDOW. */
    public function countView(IncomingRequest $request, int $listingId): void
    {
        if ($request->getMethod() !== 'GET' || ! self::counts($request, $listingId)) {
            return;
        }
        $key = 'stat-view-' . md5($request->getIPAddress() . '|' . $listingId);
        if (service('throttler')->check($key, 1, self::VIEW_WINDOW) === false) {
            return;
        }

        $this->add([$listingId], 'views');
    }

    /**
     * Profiles shown on a page of discovery results or a landing page: one
     * appearance each, however far down the page.
     *
     * @param list<array<string,mixed>> $items browse()['items']
     */
    public function countAppearances(IncomingRequest $request, array $items): void
    {
        if ($items === [] || $request->getMethod() !== 'GET' || ! config('Directory')->profileStats || DiscoveryEventService::isBot($request)) {
            return;
        }

        $ids = array_values(array_unique(array_filter(array_map(static fn (array $row): int => (int) ($row['id'] ?? 0), $items))));
        $this->add($ids, 'appearances');
    }

    /** A tap on call, WhatsApp, website, directions, booking or email. */
    public function countTap(IncomingRequest $request, int $listingId, string $kind): void
    {
        if (! isset(self::TAPS[$kind]) || ! self::counts($request, $listingId)) {
            return;
        }

        $this->add([$listingId], self::TAPS[$kind]);
    }

    /**
     * Totals for one profile from $from to $to (Y-m-d, both included).
     *
     * @return array<string,int> views, appearances, every taps_* column, and taps (their sum)
     */
    public function totals(int $listingId, string $from, string $to): array
    {
        $columns = array_merge(['views', 'appearances'], array_values(self::TAPS));
        $row     = db_connect()->table(self::TABLE)
            ->select(implode(', ', array_map(static fn (string $c): string => 'COALESCE(SUM(' . $c . '), 0) AS ' . $c, $columns)), false)
            ->where('listing_id', $listingId)
            ->where('day >=', $from)
            ->where('day <=', $to)
            ->get()->getRowArray() ?? [];

        $totals = [];
        foreach ($columns as $c) {
            $totals[$c] = (int) ($row[$c] ?? 0);
        }
        $totals['taps'] = array_sum(array_intersect_key($totals, array_flip(self::TAPS)));

        return $totals;
    }

    /**
     * One profile's views and taps per month, oldest first, from the first
     * month anything was counted, at most $months of them.
     *
     * @return list<array{month:string,views:int,taps:int,appearances:int}>
     */
    public function monthly(int $listingId, int $months = 12): array
    {
        $taps = implode(' + ', array_map(static fn (string $c): string => 'SUM(' . $c . ')', self::TAPS));
        $rows = db_connect()->table(self::TABLE)
            ->select("DATE_FORMAT(day, '%Y-%m-01') AS month, SUM(views) AS views, SUM(appearances) AS appearances, " . $taps . ' AS taps', false)
            ->where('listing_id', $listingId)
            ->where('day >=', date('Y-m-01', strtotime('first day of -' . ($months - 1) . ' months')))
            ->groupBy('month')
            ->orderBy('month', 'ASC')
            ->get()->getResultArray();

        return array_map(static fn (array $r): array => [
            'month' => (string) $r['month'], 'views' => (int) $r['views'], 'taps' => (int) $r['taps'], 'appearances' => (int) $r['appearances'],
        ], $rows);
    }

    /** The first day anything was counted, for any profile: when counting started. */
    public function firstDay(): ?string
    {
        $row = db_connect()->table(self::TABLE)->selectMin('day')->get()->getRowArray();

        return $row['day'] ?? null;
    }

    /**
     * Everything the owner's stats panel shows, for /manage/edit and the
     * admin's view of a profile.
     *
     * Every owner gets the last 30 days against the 30 before, and the tips.
     * $full (Verified on co.za, Plus on .app, and always for the admin) adds
     * the monthly trend, taps by kind, the words people discovered the
     * profile with, and its Google search numbers. The tips never depend on
     * $full and never suggest anything paid.
     *
     * @param array<string,mixed>             $listing
     * @param list<array<string,mixed>>       $items   ListingQualityService::evaluate()['items']
     *
     * @return array<string,mixed>
     */
    public function forOwner(array $listing, array $items, bool $full): array
    {
        $id    = (int) $listing['id'];
        $today = date('Y-m-d');
        $from  = date('Y-m-d', strtotime('-' . (self::PERIOD_DAYS - 1) . ' days'));
        $since = $this->firstDay();

        // Days actually counted in the window: right after release it is a few,
        // and the panel says so rather than show a month that looks empty.
        $counted = $since === null ? 0 : (int) round((strtotime($today) - strtotime(max($since, $from))) / DAY) + 1;

        $current  = $this->totals($id, $from, $today);
        $previous = null;
        $prevFrom = date('Y-m-d', strtotime('-' . (2 * self::PERIOD_DAYS - 1) . ' days'));
        if ($since !== null && $since <= $prevFrom) {
            $previous = $this->totals($id, $prevFrom, date('Y-m-d', strtotime('-' . self::PERIOD_DAYS . ' days')));
        }

        $stats = [
            'since'    => $since,
            'counted'  => $counted,
            'current'  => $current,
            'previous' => $previous,
            'tips'     => $counted >= self::TIPS_AFTER_DAYS ? $this->tips($listing, $current, $items) : [],
            'full'     => $full,
        ];
        if (! $full) {
            return $stats;
        }

        $site   = service('site')->forRow($listing);
        $google = new GoogleDataService();
        $last   = $google->lastPulled('gsc', $site);
        $search = null;
        if ($last !== null) {
            $searchFrom = date('Y-m-d', strtotime($last . ' -' . (self::GOOGLE_DAYS - 1) . ' days'));
            $search     = $google->listingSearch($id, $searchFrom, $last) + [
                'from'    => $searchFrom,
                'to'      => $last,
                'queries' => $google->listingQueries($id, date('Y-m-01', strtotime($last . ' first day of -2 months'))),
            ];
        }

        return $stats + [
            'months' => $this->monthly($id),
            'byKind' => array_map(static fn (string $c): int => $current[$c], self::TAPS),
            'words'  => (new DiscoveryEventService())->wordsFor($id, $site, 90),
            'google' => $search,
        ];
    }

    /**
     * What the numbers say is holding the profile back, each with the empty
     * parts of the profile that would help. A tip with nothing left to fill
     * in is left out: it would only nag. Never anything paid (rule 1 in
     * _strength_panel.php).
     *
     * @param array<string,mixed>       $listing
     * @param array<string,int>         $current totals() for the period
     * @param list<array<string,mixed>> $items
     *
     * @return list<array{key:string,title:string,text:string,steps:list<array{label:string,anchor:string}>}>
     */
    public function tips(array $listing, array $current, array $items): array
    {
        $missing = [];
        foreach ($items as $item) {
            if (empty($item['done'])) {
                $missing[(string) $item['key']] = ['label' => (string) $item['label'], 'anchor' => (string) $item['anchor']];
            }
        }
        // Two that the rubric does not score but a visitor reaches for.
        if (trim((string) ($listing['whatsapp'] ?? '')) === '') {
            $missing['whatsapp'] = ['label' => 'A WhatsApp number', 'anchor' => 'field-whatsapp'];
        }
        if (($listing['customer_location'] ?? '') === 'travel' && trim((string) ($listing['service_areas'] ?? '')) === '') {
            $missing['service_areas'] = ['label' => 'The areas you travel to', 'anchor' => 'f-service-areas'];
        }
        $pick = static fn (array $keys): array => array_values(array_intersect_key($missing, array_flip($keys)));

        $shown = $current['appearances'];
        $views = $current['views'];
        $taps  = $current['taps'];
        $tips  = [];

        if ($shown < self::FEW_APPEARANCES) {
            $tips[] = [
                'key'   => 'rarely_shown',
                'title' => 'Rarely shown in discovery results',
                'text'  => 'People discover profiles by the words in them: your category, areas of focus, services and description. The more of these you fill in, the more searches you appear for.',
                'steps' => $pick(['tags', 'services', 'description', 'features', 'service_areas', 'city']),
            ];
        } elseif ($views * 100 < $shown * self::OPEN_RATE_PERCENT) {
            $tips[] = [
                'key'   => 'rarely_opened',
                'title' => 'Shown often, opened rarely',
                'text'  => 'Your profile appeared ' . number_format($shown) . ' times but was opened ' . number_format($views) . ' times. A logo, photos and a clear first sentence make your card stand out in the results.',
                'steps' => $pick(['logo', 'photos', 'description']),
            ];
        }

        if ($views >= self::ENOUGH_VIEWS && $taps * 100 < $views * self::CONTACT_RATE_PERCENT) {
            $tips[] = [
                'key'   => 'few_contacts',
                'title' => 'Opened, but few people get in touch',
                'text'  => number_format($views) . ' people opened your profile and ' . number_format($taps) . ' tapped to contact you. Give them an easy way to reach you, and tell them when you are open.',
                'steps' => $pick(['phone', 'whatsapp', 'hours', 'website', 'booking']),
            ];
        }

        return array_values(array_filter($tips, static fn (array $t): bool => $t['steps'] !== []));
    }

    // -------------------------------------------------------- admin, per site

    /**
     * Every profile on one site added up, per day from $from to $to, oldest
     * first. Days with nothing counted are left out.
     *
     * @return list<array<string,int|string>> day, views, appearances, every taps_* column, taps
     */
    public function siteDays(string $site, string $from, string $to): array
    {
        $columns = array_merge(['views', 'appearances'], array_values(self::TAPS));
        $rows    = $this->siteBuilder($site, $from, $to)
            ->select('s.day, ' . implode(', ', array_map(static fn (string $c): string => 'SUM(s.' . $c . ') AS ' . $c, $columns)), false)
            ->groupBy('s.day')
            ->orderBy('s.day', 'ASC')
            ->get()->getResultArray();

        return array_map(static function (array $r) use ($columns): array {
            $day = ['day' => (string) $r['day']];
            foreach ($columns as $c) {
                $day[$c] = (int) $r[$c];
            }
            $day['taps'] = array_sum(array_intersect_key($day, array_flip(self::TAPS)));

            return $day;
        }, $rows);
    }

    /**
     * The most viewed profiles on one site.
     *
     * @return list<array{id:int,display_name:string,slug:string,views:int,appearances:int,taps:int}>
     */
    public function topProfiles(string $site, string $from, string $to, int $limit = 25): array
    {
        $taps = implode(' + ', array_map(static fn (string $c): string => 'SUM(s.' . $c . ')', self::TAPS));
        $rows = $this->siteBuilder($site, $from, $to)
            ->select('l.id, l.display_name, l.slug, SUM(s.views) AS views, SUM(s.appearances) AS appearances, ' . $taps . ' AS taps', false)
            ->groupBy('l.id')
            ->orderBy('views', 'DESC')
            ->orderBy('taps', 'DESC')
            ->limit($limit)
            ->get()->getResultArray();

        return array_map(static fn (array $r): array => [
            'id' => (int) $r['id'], 'display_name' => (string) $r['display_name'], 'slug' => (string) $r['slug'],
            'views' => (int) $r['views'], 'appearances' => (int) $r['appearances'], 'taps' => (int) $r['taps'],
        ], $rows);
    }

    /**
     * Published profiles on one site nobody opened from $from on: who to help.
     * Newest first, since an old profile with no views is an import nobody
     * claimed, and a new one is an owner still waiting for a first visitor.
     *
     * @return array{count:int,rows:list<array{id:int,display_name:string,slug:string,published_at:?string}>}
     */
    public function unseen(string $site, string $from, int $limit = 25): array
    {
        $db      = db_connect();
        $builder = $db->table('directory_listings l')
            ->where('l.status', 'published')
            ->where('l.deleted_at', null)
            ->where('NOT EXISTS (SELECT 1 FROM ' . $db->prefixTable(self::TABLE) . ' s WHERE s.listing_id = l.id AND s.views > 0 AND s.day >= ' . $db->escape($from) . ')', null, false);
        $scope = (new SiteContext(config(\Config\Sites::class)->host($site)))->scopeSql('l.country_code');
        if ($scope !== '') {
            $builder->where($scope, null, false);
        }

        $count = $builder->countAllResults(false);
        $rows  = $builder->select('l.id, l.display_name, l.slug, l.published_at')
            ->orderBy('l.published_at', 'DESC')
            ->limit($limit)
            ->get()->getResultArray();

        return ['count' => $count, 'rows' => array_map(static fn (array $r): array => [
            'id' => (int) $r['id'], 'display_name' => (string) $r['display_name'], 'slug' => (string) $r['slug'], 'published_at' => $r['published_at'],
        ], $rows)];
    }

    /** The stats rows of one site's profiles from $from to $to, joined to the profile. */
    private function siteBuilder(string $site, string $from, string $to): \CodeIgniter\Database\BaseBuilder
    {
        $builder = db_connect()->table(self::TABLE . ' s')
            ->join('directory_listings l', 'l.id = s.listing_id')
            ->where('s.day >=', $from)
            ->where('s.day <=', $to)
            ->where('l.deleted_at', null);
        $scope = (new SiteContext(config(\Config\Sites::class)->host($site)))->scopeSql('l.country_code');
        if ($scope !== '') {
            $builder->where($scope, null, false);
        }

        return $builder;
    }

    /** Delete days older than KEEP_MONTHS. */
    public function prune(): int
    {
        $db = db_connect();
        $db->table(self::TABLE)->where('day <', date('Y-m-d', strtotime('-' . self::KEEP_MONTHS . ' months')))->delete();

        return $db->affectedRows();
    }

    /**
     * One more on $column for each profile today, in a single statement.
     *
     * @param list<int> $ids
     */
    private function add(array $ids, string $column): void
    {
        $ids = array_values(array_filter($ids, static fn (int $id): bool => $id > 0));
        if ($ids === []) {
            return;
        }

        try {
            $db    = db_connect();
            $today = $db->escape(date('Y-m-d'));
            $rows  = implode(', ', array_map(static fn (int $id): string => '(' . $id . ', ' . $today . ', 1)', $ids));
            $db->query(
                'INSERT INTO ' . $db->prefixTable(self::TABLE) . ' (listing_id, day, ' . $column . ') VALUES ' . $rows
                . ' ON DUPLICATE KEY UPDATE ' . $column . ' = ' . $column . ' + 1'
            );
        } catch (\Throwable $e) {
            log_message('warning', 'Profile stat not recorded: ' . $e->getMessage());
        }
    }
}

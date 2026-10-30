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

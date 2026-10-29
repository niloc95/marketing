<?php

namespace App\Services\Search;

use App\Filters\ScrapeGuard;
use CodeIgniter\HTTP\IncomingRequest;

/**
 * What people type in Discover and which result they open: the visitor half
 * of the discovery model (DiscoveryModelService::learn() reads it nightly).
 * The only writer of xs_directory_discovery_events.
 *
 * Nothing that identifies anyone is kept: no IP, no user id, no session, no
 * cookie. Just the words, the site, how many results they found, and which
 * profile at which position was opened. Rows go after KEEP_DAYS (Privacy
 * policy, "Discover searches"). Crawlers are left out, so a bot walking the
 * results pages never teaches the model anything.
 *
 * Writes never throw: a broken table must not cost anyone a results page.
 */
class DiscoveryEventService
{
    public const KEEP_DAYS = 180;

    private const TABLE = 'xs_directory_discovery_events';

    private const BOT_AGENTS = '/bot|crawl|spider|slurp|preview|fetch|monitor|curl|wget|python|headless|lighthouse/i';

    /** A search on the results page, with how many profiles it found. */
    public function logSearch(string $q, int $results): void
    {
        $this->write(['query' => self::normalise($q), 'results' => max(0, $results)]);
    }

    /** A result opened from the results page. */
    public function logClick(string $q, int $listingId, int $position): void
    {
        $this->write(['query' => self::normalise($q), 'listing_id' => $listingId, 'position' => max(1, min(1000, $position))]);
    }

    /**
     * Searches that found nothing, most asked first, for /admin/discovery:
     * what people want that the platform does not have yet.
     *
     * @return list<array{query:string,searches:int,last:string}>
     */
    public function emptySearches(int $days = 30, int $limit = 50, ?string $site = null): array
    {
        return $this->grouped('results = 0', $days, $limit, $site);
    }

    /** @return list<array{query:string,searches:int,last:string}> */
    public function topSearches(int $days = 30, int $limit = 50, ?string $site = null): array
    {
        return $this->grouped('results > 0', $days, $limit, $site);
    }

    /** Delete rows older than KEEP_DAYS. */
    public function purge(): int
    {
        $db = db_connect();
        $db->table(self::TABLE)->where('created_at <', date('Y-m-d H:i:s', strtotime('-' . self::KEEP_DAYS . ' days')))->delete();

        return $db->affectedRows();
    }

    /** Is this request a crawler or a tool rather than a person? */
    public static function isBot(IncomingRequest $request): bool
    {
        $token = (string) config('Directory')->verifiedBotToken;
        if ($token !== '' && hash_equals($token, $request->getHeaderLine(ScrapeGuard::BOT_HEADER))) {
            return true;
        }
        $agent = $request->getHeaderLine('User-Agent');
        if ($agent === '') {
            $agent = (string) $request->getUserAgent();
        }

        return $agent === '' || preg_match(self::BOT_AGENTS, $agent) === 1;
    }

    /** Lower case, single spaces, at most 100 characters. */
    public static function normalise(string $q): string
    {
        return mb_substr(trim((string) preg_replace('/\s+/u', ' ', mb_strtolower($q))), 0, 100);
    }

    /** @param array<string,mixed> $row */
    private function write(array $row): void
    {
        if (! config('Search')->discoveryLog || ($row['query'] ?? '') === '') {
            return;
        }
        try {
            db_connect()->table(self::TABLE)->insert($row + [
                'site'       => service('site')->key(),
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            log_message('warning', 'Discovery event not recorded: ' . $e->getMessage());
        }
    }

    /** @return list<array{query:string,searches:int,last:string}> */
    private function grouped(string $condition, int $days, int $limit, ?string $site): array
    {
        return db_connect()->table(self::TABLE)
            ->select('query, COUNT(*) AS searches, MAX(created_at) AS last')
            ->where('site', $site ?? service('site')->key())
            ->where('listing_id', null)
            ->where($condition, null, false)
            ->where('created_at >=', date('Y-m-d H:i:s', strtotime('-' . $days . ' days')))
            ->groupBy('query')
            ->orderBy('searches', 'DESC')
            ->orderBy('last', 'DESC')
            ->limit($limit)
            ->get()->getResultArray();
    }
}

<?php

namespace App\Services;

use App\Libraries\GoogleApi;
use Config\Google as GoogleConfig;
use Config\Sites;

/**
 * What Google says about each profile and each site, pulled once a night
 * (php spark directory:stats) and stored, so no page ever waits on Google.
 * The only writer of xs_directory_listing_search, _listing_search_queries,
 * _site_daily, _site_breakdown and _google_pulls (see the migration
 * 2026-11-03-100000_CreateGoogleData for what each holds).
 *
 * - Search Console, per site, for the site's own host only: per profile page
 *   (impressions, clicks, position), per profile page and query (summed per
 *   month), the site total and its top queries. Owners and admin.
 * - GA4, per site: users, sessions and views, sessions by channel and by
 *   landing page. Admin only: GA4 counts only visitors who accepted cookies,
 *   so our own counter (ListingStatsService) stays the number owners see.
 *
 * A day is pulled once (xs_directory_google_pulls) and written in one
 * transaction after every call for it has answered, so a failure halfway
 * writes nothing and the next night tries the day again. Monthly sums can
 * therefore add a day without counting it twice.
 *
 * Off until config Google is set (see that file); then a pull is a handful
 * of free API requests a night, far under the quotas.
 */
class GoogleDataService
{
    public const KEEP_MONTHS = ListingStatsService::KEEP_MONTHS;

    /** Search Console's largest page of rows. */
    private const PAGE_SIZE = 25000;

    /** Site wide top queries kept per day (summed into the month). */
    private const SITE_QUERIES = 100;

    /** GA4 landing pages kept per day (summed into the month). */
    private const LANDING_PAGES = 50;

    private GoogleApi $api;
    private GoogleConfig $config;

    public function __construct(?GoogleApi $api = null, ?GoogleConfig $config = null)
    {
        $this->api    = $api ?? service('google');
        $this->config = $config ?? config(GoogleConfig::class);
    }

    public function isConfigured(): bool
    {
        return $this->api->isConfigured();
    }

    /**
     * Pull every day that has not been pulled yet, for every site and source
     * that is configured, from the newest settled day back $days days.
     *
     * A source stops at its first failure for a site: a refused key fails
     * every day alike, and asking 400 times says nothing new.
     *
     * @return list<array{source:string,site:string,day:string,rows:int|null}> rows null = failed
     */
    public function catchUp(?int $days = null): array
    {
        if (! $this->isConfigured()) {
            return [];
        }
        $days ??= $this->config->catchUpDays;

        $done = [];
        foreach ($this->sites() as $site) {
            $sources = [
                'gsc' => [$this->config->searchConsoleFor($site) !== '', $this->config->searchConsoleLagDays, 'pullSearchConsole'],
                'ga4' => [$this->config->analyticsPropertyFor($site) !== '', $this->config->analyticsLagDays, 'pullAnalytics'],
            ];
            foreach ($sources as $source => [$configured, $lag, $method]) {
                if (! $configured) {
                    continue;
                }
                for ($back = $lag; $back < $lag + max(1, $days); $back++) {
                    $day = date('Y-m-d', strtotime('-' . $back . ' days'));
                    if ($this->pulled($source, $site, $day)) {
                        continue;
                    }
                    $rows   = $this->{$method}($site, $day);
                    $done[] = ['source' => $source, 'site' => $site, 'day' => $day, 'rows' => $rows];
                    if ($rows === null) {
                        break;
                    }
                }
            }
        }

        return $done;
    }

    /**
     * Search Console for one site and day.
     *
     * @return int|null profile pages stored, or null when Google did not answer
     */
    public function pullSearchConsole(string $site, string $day): ?int
    {
        $property = $this->config->searchConsoleFor($site);
        if ($property === '') {
            return null;
        }

        $host = config(Sites::class)->host($site);
        $base = [
            'startDate' => $day,
            'endDate'   => $day,
            'type'      => 'web',
            'dataState' => 'final',
            // Only this site's own pages: a domain property also covers the
            // marketing site and the other subdomains.
            'dimensionFilterGroups' => [['filters' => [[
                'dimension' => 'page', 'operator' => 'contains', 'expression' => 'https://' . $host . '/',
            ]]]],
        ];

        // Stop at the first refusal: the calls after it would be refused too.
        $total       = $this->api->searchAnalytics($property, $base + ['rowLimit' => 1]);
        $pages       = $total === null ? null : $this->allRows($property, $base + ['dimensions' => ['page']]);
        $pageQueries = $pages === null ? null : $this->allRows($property, $base + ['dimensions' => ['page', 'query']]);
        $queries     = $pageQueries === null ? null : $this->api->searchAnalytics($property, $base + ['dimensions' => ['query'], 'rowLimit' => self::SITE_QUERIES]);
        if ($total === null || $pages === null || $pageQueries === null || $queries === null) {
            log_message('warning', 'Search Console for ' . $site . ' on ' . $day . ' not stored: Google did not answer.');

            return null;
        }

        $ids = $this->listingIds($site, array_merge(
            array_map(static fn (array $r): string => (string) ($r['keys'][0] ?? ''), $pages),
            array_map(static fn (array $r): string => (string) ($r['keys'][0] ?? ''), $pageQueries)
        ));

        // A profile can be reached by more than one URL (a trailing slash, a
        // query string), so rows are summed per profile, position weighted
        // by impressions.
        $perListing = [];
        foreach ($pages as $r) {
            $id = $ids[$this->slugOf((string) ($r['keys'][0] ?? ''))] ?? null;
            if ($id === null) {
                continue;
            }
            $impressions = (int) ($r['impressions'] ?? 0);
            $perListing[$id] ??= ['impressions' => 0, 'clicks' => 0, 'weighted' => 0.0];
            $perListing[$id]['impressions'] += $impressions;
            $perListing[$id]['clicks'] += (int) ($r['clicks'] ?? 0);
            $perListing[$id]['weighted'] += (float) ($r['position'] ?? 0) * $impressions;
        }

        $perQuery = [];
        foreach ($pageQueries as $r) {
            $id    = $ids[$this->slugOf((string) ($r['keys'][0] ?? ''))] ?? null;
            $query = $this->label((string) ($r['keys'][1] ?? ''));
            if ($id === null || $query === '') {
                continue;
            }
            $perQuery[$id . "\0" . $query] ??= [$id, $query, 0, 0];
            $perQuery[$id . "\0" . $query][2] += (int) ($r['impressions'] ?? 0);
            $perQuery[$id . "\0" . $query][3] += (int) ($r['clicks'] ?? 0);
        }

        $month = substr($day, 0, 7) . '-01';

        return $this->write('Search Console', $site, $day, function () use ($site, $day, $month, $perListing, $perQuery, $total, $queries): int {
            $this->insert('directory_listing_search', ['listing_id', 'day', 'impressions', 'clicks', 'position'], array_map(
                static fn (int $id, array $t): array => [$id, $day, $t['impressions'], $t['clicks'], $t['impressions'] > 0 ? round($t['weighted'] / $t['impressions'], 2) : null],
                array_keys($perListing),
                $perListing
            ), 'replace');
            $this->insert('directory_listing_search_queries', ['listing_id', 'month', 'query', 'impressions', 'clicks'], array_map(
                static fn (array $q): array => [$q[0], $month, $q[1], $q[2], $q[3]],
                array_values($perQuery)
            ), 'add');

            $all = $total[0] ?? [];
            $this->insert('directory_site_daily', ['site', 'day', 'sc_impressions', 'sc_clicks', 'sc_position'], [[
                $site, $day, (int) ($all['impressions'] ?? 0), (int) ($all['clicks'] ?? 0),
                isset($all['position']) ? round((float) $all['position'], 2) : null,
            ]], 'replace');
            $this->insert('directory_site_breakdown', ['site', 'month', 'kind', 'label', 'value', 'value2'], array_values(array_filter(array_map(
                fn (array $r): ?array => ($q = $this->label((string) ($r['keys'][0] ?? ''))) === ''
                    ? null
                    : [$site, $month, 'sc_query', $q, (int) ($r['impressions'] ?? 0), (int) ($r['clicks'] ?? 0)],
                $queries
            ))), 'add');

            $this->markPulled('gsc', $site, $day, count($perListing));

            return count($perListing);
        });
    }

    /**
     * GA4 for one site and day.
     *
     * @return int|null landing pages stored, or null when Google did not answer
     */
    public function pullAnalytics(string $site, string $day): ?int
    {
        $property = $this->config->analyticsPropertyFor($site);
        if ($property === '') {
            return null;
        }

        $range    = ['dateRanges' => [['startDate' => $day, 'endDate' => $day]]];
        $sessions = [['name' => 'sessions']];
        $byDesc   = [['metric' => ['metricName' => 'sessions'], 'desc' => true]];

        $totals   = $this->api->runReport($property, $range + ['metrics' => [['name' => 'totalUsers'], ['name' => 'sessions'], ['name' => 'screenPageViews']]]);
        $channels = $totals === null ? null : $this->api->runReport($property, $range + ['dimensions' => [['name' => 'sessionDefaultChannelGroup']], 'metrics' => $sessions, 'orderBys' => $byDesc, 'limit' => 50]);
        $landing  = $channels === null ? null : $this->api->runReport($property, $range + ['dimensions' => [['name' => 'landingPage']], 'metrics' => $sessions, 'orderBys' => $byDesc, 'limit' => self::LANDING_PAGES]);
        if ($totals === null || $channels === null || $landing === null) {
            log_message('warning', 'GA4 for ' . $site . ' on ' . $day . ' not stored: Google did not answer.');

            return null;
        }

        $metric = static fn (array $row, int $i): int => (int) ($row['metricValues'][$i]['value'] ?? 0);
        $first  = $totals[0] ?? [];
        $month  = substr($day, 0, 7) . '-01';
        $rows   = [];
        foreach (['ga_channel' => $channels, 'ga_landing' => $landing] as $kind => $report) {
            foreach ($report as $r) {
                $label = $this->label((string) ($r['dimensionValues'][0]['value'] ?? ''));
                if ($label !== '') {
                    $rows[] = [$site, $month, $kind, $label, $metric($r, 0), 0];
                }
            }
        }

        return $this->write('GA4', $site, $day, function () use ($site, $day, $metric, $first, $rows, $landing): int {
            $this->insert('directory_site_daily', ['site', 'day', 'ga_users', 'ga_sessions', 'ga_views'], [[
                $site, $day, $metric($first, 0), $metric($first, 1), $metric($first, 2),
            ]], 'replace');
            $this->insert('directory_site_breakdown', ['site', 'month', 'kind', 'label', 'value', 'value2'], $rows, 'add');
            $this->markPulled('ga4', $site, $day, count($landing));

            return count($landing);
        });
    }

    // ------------------------------------------------------------------ reads

    /**
     * Google search totals for one profile from $from to $to (both included).
     *
     * @return array{impressions:int,clicks:int,position:float|null}
     */
    public function listingSearch(int $listingId, string $from, string $to): array
    {
        $row = db_connect()->table('directory_listing_search')
            ->select('COALESCE(SUM(impressions), 0) AS impressions, COALESCE(SUM(clicks), 0) AS clicks, SUM(position * impressions) / NULLIF(SUM(impressions), 0) AS position', false)
            ->where('listing_id', $listingId)
            ->where('day >=', $from)
            ->where('day <=', $to)
            ->get()->getRowArray() ?? [];

        return [
            'impressions' => (int) ($row['impressions'] ?? 0),
            'clicks'      => (int) ($row['clicks'] ?? 0),
            'position'    => isset($row['position']) ? round((float) $row['position'], 1) : null,
        ];
    }

    /**
     * The Google searches a profile appeared for, most shown first, from the
     * month of $from on.
     *
     * @return list<array{query:string,impressions:int,clicks:int}>
     */
    public function listingQueries(int $listingId, string $from, int $limit = 10): array
    {
        $rows = db_connect()->table('directory_listing_search_queries')
            ->select('query, SUM(impressions) AS impressions, SUM(clicks) AS clicks')
            ->where('listing_id', $listingId)
            ->where('month >=', substr($from, 0, 7) . '-01')
            ->groupBy('query')
            ->orderBy('impressions', 'DESC')
            ->orderBy('clicks', 'DESC')
            ->limit($limit)
            ->get()->getResultArray();

        return array_map(static fn (array $r): array => [
            'query' => (string) $r['query'], 'impressions' => (int) $r['impressions'], 'clicks' => (int) $r['clicks'],
        ], $rows);
    }

    /**
     * One site's daily Search Console and GA4 totals, oldest first.
     *
     * @return list<array<string,mixed>>
     */
    public function siteDaily(string $site, string $from, string $to): array
    {
        return db_connect()->table('directory_site_daily')
            ->where('site', $site)
            ->where('day >=', $from)
            ->where('day <=', $to)
            ->orderBy('day')
            ->get()->getResultArray();
    }

    /**
     * One site's top rows of a breakdown (sc_query, ga_channel, ga_landing)
     * from the month of $from on.
     *
     * @return list<array{label:string,value:int,value2:int}>
     */
    public function siteBreakdown(string $site, string $kind, string $from, int $limit = 20): array
    {
        $rows = db_connect()->table('directory_site_breakdown')
            ->select('label, SUM(value) AS value, SUM(value2) AS value2')
            ->where('site', $site)
            ->where('kind', $kind)
            ->where('month >=', substr($from, 0, 7) . '-01')
            ->groupBy('label')
            ->orderBy('value', 'DESC')
            ->limit($limit)
            ->get()->getResultArray();

        return array_map(static fn (array $r): array => [
            'label' => (string) $r['label'], 'value' => (int) $r['value'], 'value2' => (int) $r['value2'],
        ], $rows);
    }

    /** The newest day pulled for a source and site, or null. */
    public function lastPulled(string $source, string $site): ?string
    {
        $row = db_connect()->table('directory_google_pulls')
            ->selectMax('day')
            ->where('source', $source)
            ->where('site', $site)
            ->get()->getRowArray();

        return $row['day'] ?? null;
    }

    /** Delete everything older than KEEP_MONTHS. */
    public function prune(): int
    {
        $db      = db_connect();
        $cutoff  = date('Y-m-d', strtotime('-' . self::KEEP_MONTHS . ' months'));
        $month   = substr($cutoff, 0, 7) . '-01';
        $deleted = 0;
        foreach (['directory_listing_search' => ['day', $cutoff], 'directory_site_daily' => ['day', $cutoff], 'directory_google_pulls' => ['day', $cutoff], 'directory_listing_search_queries' => ['month', $month], 'directory_site_breakdown' => ['month', $month]] as $table => [$column, $before]) {
            $db->table($table)->where($column . ' <', $before)->delete();
            $deleted += $db->affectedRows();
        }

        return $deleted;
    }

    // ---------------------------------------------------------------- helpers

    /**
     * Run one day's writes in a transaction: all of them, or none and null.
     *
     * Failure is read from each query (insert()), not from transStatus():
     * a query that failed earlier on the same connection leaves that false.
     *
     * @param callable(): int $writes
     */
    private function write(string $label, string $site, string $day, callable $writes): ?int
    {
        $db = db_connect();
        try {
            $db->transBegin();
            $stored = $writes();
            $db->transCommit();

            return $stored;
        } catch (\Throwable $e) {
            $db->transRollback();
            log_message('error', $label . ' for ' . $site . ' on ' . $day . ' not stored: ' . $e->getMessage());

            return null;
        }
    }

    /** @return list<string> */
    private function sites(): array
    {
        return config(Sites::class)->appEnabled() ? [Sites::ZA, Sites::APP] : [Sites::ZA];
    }

    private function pulled(string $source, string $site, string $day): bool
    {
        return db_connect()->table('directory_google_pulls')
            ->where(['source' => $source, 'site' => $site, 'day' => $day])
            ->countAllResults() > 0;
    }

    private function markPulled(string $source, string $site, string $day, int $rows): void
    {
        $this->insert('directory_google_pulls', ['source', 'site', 'day', 'rows', 'pulled_at'], [[
            $source, $site, $day, $rows, date('Y-m-d H:i:s'),
        ]], 'replace');
    }

    /**
     * Every row of a Search Console query, a page of PAGE_SIZE at a time.
     *
     * @param array<string,mixed> $body
     * @return list<array<string,mixed>>|null
     */
    private function allRows(string $property, array $body): ?array
    {
        $rows = [];
        for ($start = 0; ; $start += self::PAGE_SIZE) {
            $page = $this->api->searchAnalytics($property, $body + ['rowLimit' => self::PAGE_SIZE, 'startRow' => $start]);
            if ($page === null) {
                return null;
            }
            array_push($rows, ...$page);
            if (count($page) < self::PAGE_SIZE) {
                return $rows;
            }
        }
    }

    /** The profile slug a page URL points at, or '' when it is not a profile. */
    private function slugOf(string $url): string
    {
        $path = (string) parse_url($url, PHP_URL_PATH);

        return preg_match('#^/directory/([a-z0-9-]{1,190})/?$#', $path, $m) === 1 ? $m[1] : '';
    }

    /**
     * Profile ids by slug for the page URLs Google returned, on this site only.
     *
     * @param list<string> $urls
     * @return array<string,int>
     */
    private function listingIds(string $site, array $urls): array
    {
        $slugs = array_values(array_unique(array_filter(array_map(fn (string $u): string => $this->slugOf($u), $urls))));
        if ($slugs === []) {
            return [];
        }

        $scope = (new SiteContext(config(Sites::class)->host($site)))->scopeSql('country_code');
        $ids   = [];
        foreach (array_chunk($slugs, 500) as $chunk) {
            $builder = db_connect()->table('directory_listings')->select('id, slug')->whereIn('slug', $chunk)->where('deleted_at', null);
            if ($scope !== '') {
                $builder->where($scope, null, false);
            }
            foreach ($builder->get()->getResultArray() as $r) {
                $ids[(string) $r['slug']] = (int) $r['id'];
            }
        }

        return $ids;
    }

    /** A query or page label as stored: one line, at most 190 characters. */
    private function label(string $text): string
    {
        return mb_substr(trim((string) preg_replace('/\s+/u', ' ', $text)), 0, 190);
    }

    /**
     * Multi row INSERT. 'replace' overwrites the counted columns, 'add' adds
     * to them (the monthly sums).
     *
     * @param list<string>      $columns the key columns first; every column after the key is updated
     * @param list<list<mixed>> $rows
     */
    private function insert(string $table, array $columns, array $rows, string $mode): void
    {
        if ($rows === []) {
            return;
        }

        $db     = db_connect();
        $keys   = ['directory_listing_search' => 2, 'directory_listing_search_queries' => 3, 'directory_site_daily' => 2, 'directory_site_breakdown' => 4, 'directory_google_pulls' => 3][$table];
        $update = implode(', ', array_map(
            static fn (string $c): string => $mode === 'add' ? "`{$c}` = `{$c}` + VALUES(`{$c}`)" : "`{$c}` = VALUES(`{$c}`)",
            array_slice($columns, $keys)
        ));

        foreach (array_chunk($rows, 500) as $chunk) {
            $values = implode(', ', array_map(
                static fn (array $row): string => '(' . implode(', ', array_map(static fn ($v) => $v === null ? 'NULL' : $db->escape($v), $row)) . ')',
                $chunk
            ));
            // DBDebug throws on a failed query; production returns false instead.
            if ($db->query('INSERT INTO ' . $db->prefixTable($table) . ' (`' . implode('`, `', $columns) . '`) VALUES ' . $values . ' ON DUPLICATE KEY UPDATE ' . $update) === false) {
                throw new \RuntimeException('could not write ' . $table);
            }
        }
    }
}

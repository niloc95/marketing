<?php

namespace App\Controllers;

use App\Services\GoogleDataService;
use App\Services\ListingStatsService;
use Config\Sites;

/**
 * /admin/stats: how the platform is seen and used, one site at a time.
 * Behind the admin filter with the rest of /admin (see Routes.php).
 *
 *   - our own counters (ListingStatsService): views, appearances and taps per
 *     day, the most viewed profiles, and published profiles nobody opened;
 *   - Search Console and GA4 (GoogleDataService), pulled nightly by
 *     directory:stats, never fetched from Google here.
 *
 * GA4 is consent gated, so it undercounts: our own counters are the main
 * number, which is why they come first. Admin lives on co.za, so ?site=app
 * shows .app.
 */
class AdminStats extends BaseController
{
    /** The periods offered, in days. */
    private const PERIODS = [7, 30, 90, 365];

    public function index()
    {
        $site  = (string) $this->request->getGet('site') === Sites::APP ? Sites::APP : Sites::ZA;
        $days  = (int) $this->request->getGet('days');
        $days  = in_array($days, self::PERIODS, true) ? $days : 30;
        $to    = date('Y-m-d');
        $from  = date('Y-m-d', strtotime('-' . ($days - 1) . ' days'));
        $stats = new ListingStatsService();

        $google = new GoogleDataService();
        $gsc    = $this->googleWindow($google, 'gsc', $site, $days);
        $ga4    = $this->googleWindow($google, 'ga4', $site, $days);

        return view('admin/stats', [
            'site'     => $site,
            'days'     => $days,
            'periods'  => self::PERIODS,
            'from'     => $from,
            'to'       => $to,
            'since'    => $stats->firstDay(),
            'daily'    => $stats->siteDays($site, $from, $to),
            'previous' => $this->sum($stats->siteDays($site, date('Y-m-d', strtotime('-' . (2 * $days - 1) . ' days')), date('Y-m-d', strtotime('-' . $days . ' days')))),
            'top'      => $stats->topProfiles($site, $from, $to),
            'unseen'   => $stats->unseen($site, $from),

            'googleConfigured' => $google->isConfigured(),
            'gsc' => $gsc === null ? null : $gsc + [
                'totals'   => $this->searchTotals($google->siteDaily($site, $gsc['from'], $gsc['to'])),
                'queries'  => $google->siteBreakdown($site, 'sc_query', $gsc['from'], 25),
                'listings' => $google->topListings($site, $gsc['from'], $gsc['to']),
            ],
            'ga4' => $ga4 === null ? null : $ga4 + [
                'totals'   => $this->analyticsTotals($google->siteDaily($site, $ga4['from'], $ga4['to'])),
                'channels' => $google->siteBreakdown($site, 'ga_channel', $ga4['from'], 12),
                'landing'  => $google->siteBreakdown($site, 'ga_landing', $ga4['from'], 20),
            ],
        ]);
    }

    /**
     * The same number of days as our own counters, ending at the last day
     * pulled from Google (Search Console lags three days), or null when
     * nothing was pulled yet.
     *
     * @return array{from:string,to:string}|null
     */
    private function googleWindow(GoogleDataService $google, string $source, string $site, int $days): ?array
    {
        $last = $google->lastPulled($source, $site);

        return $last === null ? null : ['from' => date('Y-m-d', strtotime($last . ' -' . ($days - 1) . ' days')), 'to' => $last];
    }

    /**
     * @param list<array<string,int|string>> $days
     *
     * @return array<string,int>
     */
    private function sum(array $days): array
    {
        $totals = ['views' => 0, 'appearances' => 0, 'taps' => 0];
        foreach ($days as $d) {
            foreach ($totals as $k => $_) {
                $totals[$k] += (int) $d[$k];
            }
        }

        return $totals;
    }

    /**
     * @param list<array<string,mixed>> $rows siteDaily()
     *
     * @return array{impressions:int,clicks:int,position:?float}
     */
    private function searchTotals(array $rows): array
    {
        $impressions = 0;
        $clicks      = 0;
        $weighted    = 0.0;
        foreach ($rows as $r) {
            $impressions += (int) $r['sc_impressions'];
            $clicks += (int) $r['sc_clicks'];
            $weighted += (float) $r['sc_position'] * (int) $r['sc_impressions'];
        }

        return ['impressions' => $impressions, 'clicks' => $clicks, 'position' => $impressions > 0 ? round($weighted / $impressions, 1) : null];
    }

    /**
     * @param list<array<string,mixed>> $rows siteDaily()
     *
     * @return array{users:int,sessions:int,views:int}
     */
    private function analyticsTotals(array $rows): array
    {
        $totals = ['users' => 0, 'sessions' => 0, 'views' => 0];
        foreach ($rows as $r) {
            $totals['users'] += (int) $r['ga_users'];
            $totals['sessions'] += (int) $r['ga_sessions'];
            $totals['views'] += (int) $r['ga_views'];
        }

        return $totals;
    }
}

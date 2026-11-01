<?php

namespace App\Services;

use App\Libraries\MailHealth;
use App\Libraries\SystemHealth;
use App\Models\CareerApplicationModel;
use App\Models\DirectoryPartnerModel;
use App\Models\DirectoryReferralModel;
use App\Models\DirectoryReviewModel;
use App\Models\DirectoryVerificationModel;
use App\Models\JobPostModel;
use App\Models\ProfileSetupOrderModel;
use Config\Sites;

/**
 * Everything the admin landing page (/admin, AdminDashboard) shows, in one
 * read only call. It owns no table: each number comes from the service or
 * model that already answers it on its own page, so the dashboard can never
 * disagree with the page it links to.
 *
 * Site scoped (?site=app, as on /admin/stats): traffic, signups and form
 * faults. Not site scoped, because the rows carry no site or because the
 * admin works them as one list: health, the queues, setup orders. The badge
 * is co.za's paid plan and Plus is .app's, so the plan shown follows the site.
 *
 * Not cached. Every query is a small grouped count over an indexed date, and
 * a dashboard that lags the queues it links to is worse than a slower one.
 */
class AdminDashboardService
{
    /** The periods offered, in days. */
    public const PERIODS = [7, 30, 90];

    /**
     * What is waiting in each admin queue. The admin bar's counts come from
     * here too, so the tab and the dashboard tile always agree.
     *
     * @return array<string,int> verifications, jobs, reviews, referrals, partners, careers, setup
     */
    public static function queueCounts(): array
    {
        return [
            'verifications' => (new DirectoryVerificationModel())
                ->where('state', DirectoryVerificationModel::STATE_SUBMITTED)
                ->countAllResults(),
            'jobs' => (new JobPostModel())
                ->where('status', JobPostModel::STATUS_PENDING)
                ->countAllResults(),
            'reviews' => (new DirectoryReviewModel())
                ->where('status', DirectoryReviewModel::STATUS_PENDING)
                ->countAllResults(),
            'referrals' => (new DirectoryReferralModel())
                ->where('status', DirectoryReferralModel::STATUS_PENDING)
                ->countAllResults(),
            'partners' => (new DirectoryPartnerModel())
                ->where('status', DirectoryPartnerModel::STATUS_APPLIED)
                ->countAllResults(),
            'careers' => (new CareerApplicationModel())
                ->where('status', CareerApplicationModel::STATUS_NEW)
                ->where('purged_at', null)
                ->countAllResults(),
            'setup' => (new ProfileSetupOrderModel())
                ->where('status', ProfileSetupOrderModel::STATUS_PAID)
                ->countAllResults(),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function overview(string $site, int $days): array
    {
        $site  = $site === Sites::APP ? Sites::APP : Sites::ZA;
        $days  = in_array($days, self::PERIODS, true) ? $days : 30;
        $to    = date('Y-m-d');
        $from  = date('Y-m-d', strtotime('-' . ($days - 1) . ' days'));
        $pTo   = date('Y-m-d', strtotime('-' . $days . ' days'));
        $pFrom = date('Y-m-d', strtotime('-' . (2 * $days - 1) . ' days'));
        $plan  = $site === Sites::APP ? DirectoryVerificationModel::PLAN_INTERNATIONAL : DirectoryVerificationModel::PLAN_BADGE;
        $stats = new ListingStatsService();

        return [
            'site'     => $site,
            'days'     => $days,
            'from'     => $from,
            'to'       => $to,
            'health'   => $this->health(),
            'queues'   => self::queueCounts(),
            'traffic'  => [
                'daily'    => $stats->siteDays($site, $from, $to),
                'previous' => self::sum($stats->siteDays($site, $pFrom, $pTo), ['views', 'appearances', 'taps']),
                'top'      => $stats->topProfiles($site, $from, $to, 5),
                'since'    => $stats->firstDay(),
            ],
            'signups'  => [
                'daily'    => $this->signupDays($site, $plan, $from, $to),
                'previous' => self::sum($this->signupDays($site, $plan, $pFrom, $pTo), ['total', 'paid']),
                'latest'   => $this->latestSignups($site, $plan, 8),
            ],
            'plan'     => [
                'key'    => $plan,
                'label'  => $plan === DirectoryVerificationModel::PLAN_INTERNATIONAL ? 'Plus' : 'Verified',
                'counts' => (new DirectoryVerificationModel())->counts($plan),
            ],
            'forms'    => (new FormHealthService())->dashboard($site, $days),
            'setup'    => [
                'counts' => (new ProfileSetupOrderModel())->statusCounts(),
                'latest' => (new ProfileSetupOrderModel())->orderBy('created_at', 'DESC')->findAll(5),
            ],
        ];
    }

    /**
     * The checks /admin/status runs, plus the two warnings the old landing
     * page carried (mail failing, forms lost) and whether .app is switched on.
     *
     * @return array<string,mixed>
     */
    private function health(): array
    {
        $system = new SystemHealth();
        $checks = $system->checks();

        return [
            'checks'       => $checks,
            'allOk'        => $system->allOk($checks),
            'mailError'    => MailHealth::lastError(),
            'mailFailing'  => MailHealth::isFailing(),
            'formsLost'    => (new FormHealthService())->recentLost(),
            'stalePending' => (new SystemStatusService())->stalePendingCount(),
            'appEnabled'   => config(Sites::class)->appEnabled(),
        ];
    }

    /**
     * New profiles per day on one site, oldest first, days with none left
     * out. "paid" counts the ones that also applied for the site's plan (the
     * badge on co.za, including a badge granted from the admin form; Plus on
     * .app); "free" is the rest. Deleted profiles are left out.
     *
     * @return list<array{day:string,total:int,paid:int,free:int,published:int}>
     */
    private function signupDays(string $site, string $plan, string $from, string $to): array
    {
        $db   = db_connect();
        $paid = $this->paidSql($plan);
        $b    = $this->siteListings($site)
            ->select('DATE(l.created_at) AS day, COUNT(*) AS total, SUM(' . $paid . ') AS paid, SUM(l.status = ' . $db->escape('published') . ') AS published', false)
            ->where('l.created_at >=', $from . ' 00:00:00')
            ->where('l.created_at <=', $to . ' 23:59:59')
            ->groupBy('day')
            ->orderBy('day', 'ASC');

        return array_map(static fn (array $r): array => [
            'day'       => (string) $r['day'],
            'total'     => (int) $r['total'],
            'paid'      => (int) $r['paid'],
            'free'      => (int) $r['total'] - (int) $r['paid'],
            'published' => (int) $r['published'],
        ], $b->get()->getResultArray());
    }

    /**
     * @return list<array{id:int,display_name:string,slug:string,status:string,source:string,created_at:string,paid:bool}>
     */
    private function latestSignups(string $site, string $plan, int $limit): array
    {
        $rows = $this->siteListings($site)
            ->select('l.id, l.display_name, l.slug, l.status, l.signup_source, l.created_at, (' . $this->paidSql($plan) . ') AS paid', false)
            ->orderBy('l.created_at', 'DESC')
            ->orderBy('l.id', 'DESC')
            ->limit($limit)
            ->get()->getResultArray();

        return array_map(static fn (array $r): array => [
            'id'           => (int) $r['id'],
            'display_name' => (string) $r['display_name'],
            'slug'         => (string) $r['slug'],
            'status'       => (string) $r['status'],
            'source'       => (string) ($r['signup_source'] ?? ''),
            'created_at'   => (string) $r['created_at'],
            'paid'         => (bool) $r['paid'],
        ], $rows);
    }

    /** Live profiles on one site, as ListingStatsService::unseen() scopes them. */
    private function siteListings(string $site): \CodeIgniter\Database\BaseBuilder
    {
        $builder = db_connect()->table('directory_listings l')->where('l.deleted_at', null);
        $scope   = (new SiteContext(config(Sites::class)->host($site)))->scopeSql('l.country_code');
        if ($scope !== '') {
            $builder->where($scope, null, false);
        }

        return $builder;
    }

    /** SQL that is 1 when the profile applied for $plan, else 0. */
    private function paidSql(string $plan): string
    {
        $db  = db_connect();
        $sql = 'EXISTS (SELECT 1 FROM ' . $db->prefixTable('directory_verifications') . ' v WHERE v.listing_id = l.id AND v.plan = ' . $db->escape($plan) . ')';
        if ($plan === DirectoryVerificationModel::PLAN_BADGE) {
            $sql = '(' . $sql . ' OR l.admin_plan = ' . $db->escape('verified') . ')';
        }

        return $sql;
    }

    /**
     * @param list<array<string,int|string>> $days
     * @param list<string>                   $keys
     *
     * @return array<string,int>
     */
    private static function sum(array $days, array $keys): array
    {
        $totals = array_fill_keys($keys, 0);
        foreach ($days as $d) {
            foreach ($keys as $k) {
                $totals[$k] += (int) $d[$k];
            }
        }

        return $totals;
    }
}

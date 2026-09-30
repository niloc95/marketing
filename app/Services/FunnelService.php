<?php

namespace App\Services;

use App\Models\DirectoryVerificationModel;
use App\Models\DirectoryVerificationSubmissionModel;
use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Database\BaseConnection;

/**
 * Read-only conversion funnel for the Verified Business badge: how many
 * listings become applications, payments and renewals, and where they came
 * from. Feeds /admin/funnel.
 *
 * Every verification query is restricted to plan = badge. International rows
 * are created already approved with no review, so counting them would inflate
 * every stage past "applied".
 *
 * Soft-deleted listings are left out of every stage, so each percentage
 * compares like with like.
 *
 * Two things the schema cannot answer, which the page footnotes:
 *  - there is no email_verified_at, so the weekly view has no "confirmed" column;
 *  - an expiry lapse is not timestamped, so weekly churn counts cancellations only.
 */
class FunnelService
{
    private BaseConnection $db;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? db_connect();
    }

    /**
     * The funnel, top to bottom.
     *
     * @return array{stages: list<array{key:string,label:string,count:int}>, cancelling:int, churned:int}
     */
    public function stages(): array
    {
        $today = date('Y-m-d');

        $listings = $this->listings()->countAllResults();
        $emailed  = $this->listings()->where('l.is_verified', 1)->countAllResults();

        $applied = $this->submissions()
            ->select('COUNT(DISTINCT v.listing_id) AS c', false)
            ->get()->getRow('c');
        $approved = $this->submissions()
            ->select('COUNT(DISTINCT v.listing_id) AS c', false)
            ->where('s.outcome', DirectoryVerificationSubmissionModel::OUTCOME_APPROVED)
            ->get()->getRow('c');

        // activated_at, not an event row: an EFT activation pays without one.
        $paid   = $this->badges()->where('v.activated_at IS NOT NULL')->countAllResults();
        $active = $this->badges()
            ->where('v.state', DirectoryVerificationModel::STATE_ACTIVE)
            ->where('v.paid_until >=', $today)
            ->countAllResults();
        $cancelling = $this->badges()
            ->where('v.state', DirectoryVerificationModel::STATE_ACTIVE)
            ->where('v.paid_until >=', $today)
            ->where('v.cancelled_at IS NOT NULL')
            ->countAllResults();

        $renewed = count($this->badges()
            ->select('v.id')
            ->join('xs_directory_verification_events e', 'e.verification_id = v.id')
            ->where('e.payment_status', 'COMPLETE')
            ->groupBy('v.id')
            ->having('COUNT(*) >=', 2)
            ->get()->getResultArray());

        $churned = $this->badges()
            ->groupStart()
                ->where('v.state', DirectoryVerificationModel::STATE_LAPSED)
                ->orWhere('v.cancelled_at IS NOT NULL')
            ->groupEnd()
            ->countAllResults();

        return [
            'stages' => [
                ['key' => 'listings', 'label' => 'Listings created', 'count' => $listings],
                ['key' => 'emailed', 'label' => 'Email confirmed', 'count' => $emailed],
                ['key' => 'applied', 'label' => 'Applied for the badge', 'count' => (int) $applied],
                ['key' => 'approved', 'label' => 'Approved', 'count' => (int) $approved],
                ['key' => 'paid', 'label' => 'Paid (ever)', 'count' => $paid],
                ['key' => 'active', 'label' => 'Active now', 'count' => $active],
                ['key' => 'renewed', 'label' => 'Renewed at least once', 'count' => $renewed],
            ],
            'cancelling' => $cancelling,
            'churned'    => $churned,
        ];
    }

    /**
     * Activity per week (Monday start), newest first. Rows are bucketed in PHP
     * rather than with YEARWEEK so the query stays portable to the test DB.
     *
     * @return list<array{week:string,listings:int,applications:int,first_payments:int,renewals:int,cancellations:int}>
     */
    public function weekly(int $weeks = 13): array
    {
        $weeks = max(1, $weeks);
        $start = (new \DateTimeImmutable('monday this week'))->modify('-' . ($weeks - 1) . ' weeks');
        $from  = $start->format('Y-m-d 00:00:00');

        $buckets = [];
        for ($i = 0; $i < $weeks; $i++) {
            $buckets[$start->modify("+{$i} weeks")->format('Y-m-d')] = [
                'listings' => 0, 'applications' => 0, 'first_payments' => 0, 'renewals' => 0, 'cancellations' => 0,
            ];
        }
        $add = static function (?string $at, string $col) use (&$buckets): void {
            if ($at === null || $at === '') {
                return;
            }
            $key = (new \DateTimeImmutable($at))->modify('monday this week')->format('Y-m-d');
            if (isset($buckets[$key])) {
                $buckets[$key][$col]++;
            }
        };

        foreach ($this->listings()->select('l.created_at')->where('l.created_at >=', $from)->get()->getResultArray() as $r) {
            $add($r['created_at'], 'listings');
        }
        foreach ($this->submissions()->select('s.submitted_at')->where('s.submitted_at >=', $from)->get()->getResultArray() as $r) {
            $add($r['submitted_at'], 'applications');
        }
        foreach ($this->badges()->select('v.cancelled_at')->where('v.cancelled_at >=', $from)->get()->getResultArray() as $r) {
            $add($r['cancelled_at'], 'cancellations');
        }

        // First payment vs renewal needs each verification's whole COMPLETE
        // history, not just the window: the first may predate it.
        $events = $this->badges()
            ->select('v.id, v.activated_at, e.created_at')
            ->join('xs_directory_verification_events e', 'e.verification_id = v.id')
            ->where('e.payment_status', 'COMPLETE')
            ->orderBy('e.created_at', 'ASC')
            ->orderBy('e.id', 'ASC')
            ->get()->getResultArray();
        $seen = [];
        foreach ($events as $r) {
            $add($r['created_at'], isset($seen[$r['id']]) ? 'renewals' : 'first_payments');
            $seen[$r['id']] = true;
        }
        // A manual (EFT) activation has no event row; its activated_at is the payment.
        foreach ($this->badges()->select('v.id, v.activated_at')->where('v.activated_at >=', $from)->get()->getResultArray() as $r) {
            if (! isset($seen[$r['id']])) {
                $add($r['activated_at'], 'first_payments');
            }
        }

        $out = [];
        foreach (array_reverse($buckets, true) as $week => $counts) {
            $out[] = ['week' => $week] + $counts;
        }

        return $out;
    }

    /**
     * Conversion by signup channel. NULL predates the signup_source column.
     *
     * @return list<array{label:string,listings:int,applied:int,paid:int}>
     */
    public function bySource(): array
    {
        return $this->grouped('l.signup_source', static fn ($key) => $key === null || $key === ''
            ? '(before tracking)'
            : (string) $key);
    }

    /**
     * Conversion by category, busiest first.
     *
     * @return list<array{label:string,listings:int,applied:int,paid:int}>
     */
    public function byCategory(): array
    {
        $names = [];
        foreach ((new DirectoryAdminService())->allCategories() as $c) {
            $names[(int) $c['id']] = (string) $c['name'];
        }

        return $this->grouped('l.category_id', static fn ($key) => $key === null
            ? '(no category)'
            : ($names[(int) $key] ?? 'Category #' . (int) $key));
    }

    /**
     * What it takes to reach $target active badges by $by.
     *
     * @return array{target:int,by:string,active:int,remaining:int,weeksLeft:float,perWeek:?int}
     */
    public function pace(int $target, string $by): array
    {
        $active = $this->badges()
            ->where('v.state', DirectoryVerificationModel::STATE_ACTIVE)
            ->where('v.paid_until >=', date('Y-m-d'))
            ->countAllResults();

        $remaining = max(0, $target - $active);
        $seconds   = strtotime($by . ' 23:59:59') - time();
        $weeksLeft = $seconds > 0 ? round($seconds / (7 * 86400), 1) : 0.0;

        return [
            'target'    => $target,
            'by'        => $by,
            'active'    => $active,
            'remaining' => $remaining,
            'weeksLeft' => $weeksLeft,
            // null once the date has passed: there is no rate that gets there.
            'perWeek'   => $weeksLeft > 0 ? (int) ceil($remaining / $weeksLeft) : null,
        ];
    }

    // ------------------------------------------------------------------ helpers

    /**
     * @param callable(mixed):string $label
     *
     * @return list<array{label:string,listings:int,applied:int,paid:int}>
     */
    private function grouped(string $column, callable $label): array
    {
        $rows = [];
        $take = static function (array $result, string $col) use (&$rows, $label): void {
            foreach ($result as $r) {
                $name = $label($r['k']);
                $rows[$name] ??= ['label' => $name, 'listings' => 0, 'applied' => 0, 'paid' => 0];
                $rows[$name][$col] += (int) $r['c'];
            }
        };

        $take($this->listings()->select("{$column} AS k, COUNT(*) AS c")->groupBy($column)->get()->getResultArray(), 'listings');
        $take($this->submissions()->select("{$column} AS k, COUNT(DISTINCT v.listing_id) AS c", false)->groupBy($column)->get()->getResultArray(), 'applied');
        $take($this->badges()->select("{$column} AS k, COUNT(*) AS c")->where('v.activated_at IS NOT NULL')->groupBy($column)->get()->getResultArray(), 'paid');

        $rows = array_values($rows);
        usort($rows, static fn ($a, $b) => [$b['paid'], $b['listings']] <=> [$a['paid'], $a['listings']]);

        return $rows;
    }

    /** Listings that are not soft-deleted, aliased l. */
    private function listings(): BaseBuilder
    {
        return $this->db->table('xs_directory_listings l')->where('l.deleted_at', null);
    }

    /** Badge verifications (v) on live listings (l). */
    private function badges(): BaseBuilder
    {
        return $this->db->table('xs_directory_verifications v')
            ->join('xs_directory_listings l', 'l.id = v.listing_id')
            ->where('l.deleted_at', null)
            ->where('v.plan', DirectoryVerificationModel::PLAN_BADGE);
    }

    /** Every badge application attempt (s), with its verification (v) and listing (l). */
    private function submissions(): BaseBuilder
    {
        return $this->badges()->join('xs_directory_verification_submissions s', 's.verification_id = v.id');
    }
}

<?php

namespace App\Models;

use CodeIgniter\Model;

/**
 * A listing's Verified Business application. One row per listing, moved
 * through states rather than re-created — see the migration for why.
 */
class DirectoryVerificationModel extends Model
{
    protected $table         = 'xs_directory_verifications';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = true;
    protected $allowedFields = [
        'listing_id', 'plan', 'state', 'paid_until', 'amount',
        'pf_subscription_token', 'billing_anchor_day', 'pf_m_payment_id', 'rejection_reason',
        'submitted_at', 'reviewed_at', 'reviewed_by', 'activated_at', 'cancelled_at',
    ];

    /**
     * The state machine, as constants rather than the loose string literals the
     * listing `status` column is checked against in three separate files. One
     * spelling mistake there is a silently-never-matching WHERE clause.
     *
     *   submitted → documents are in, waiting for an admin
     *   approved  → admin said yes, owner has not paid yet
     *   active    → PayFast has confirmed at least one payment; badge shows
     *   lapsed    → paid_until has passed; badge hidden, documents kept
     *   rejected  → admin said no, with a reason the owner can see
     */
    public const STATE_SUBMITTED = 'submitted';
    public const STATE_APPROVED  = 'approved';
    public const STATE_ACTIVE    = 'active';
    public const STATE_LAPSED    = 'lapsed';
    public const STATE_REJECTED  = 'rejected';

    public const STATES = [
        self::STATE_SUBMITTED,
        self::STATE_APPROVED,
        self::STATE_ACTIVE,
        self::STATE_LAPSED,
        self::STATE_REJECTED,
    ];

    /**
     * What is being paid for. A listing may hold one row of each — a business
     * outside South Africa that also wants the badge has two subscriptions,
     * which is what the (listing_id, plan) unique index exists for.
     *
     *   badge         → the Verified Business badge. Optional, for anyone.
     *                   Documents, then admin review, then payment.
     *   international → the right to publish at all, for a listing whose
     *                   address is not in South Africa. No documents and no
     *                   review: there is nothing to check, so it is created
     *                   already approved and goes straight to payment.
     *
     * PLAN_BADGE is the column's DEFAULT, so every row that predates the
     * international plan is correctly labelled without a backfill.
     */
    public const PLAN_BADGE         = 'badge';
    public const PLAN_INTERNATIONAL = 'international';

    public const PLANS = [self::PLAN_BADGE, self::PLAN_INTERNATIONAL];

    /**
     * Defaults to the badge so every caller that predates the second plan keeps
     * asking the question it was already asking.
     */
    public function forListing(int $listingId, string $plan = self::PLAN_BADGE): ?array
    {
        $row = $this->where('listing_id', $listingId)->where('plan', $plan)->first();

        return is_array($row) ? $row : null;
    }

    public function findByPaymentId(string $mPaymentId): ?array
    {
        $mPaymentId = trim($mPaymentId);
        if ($mPaymentId === '') {
            return null;
        }

        $row = $this->where('pf_m_payment_id', $mPaymentId)->first();

        return is_array($row) ? $row : null;
    }

    /**
     * One page of the admin review queue, joined to the listing so the table can
     * show a name without an N+1. Listing columns are aliased rather than
     * selected raw: both tables have `id`, `created_at` and `updated_at`, and
     * whichever comes last would otherwise silently win.
     *
     * @return array{rows:array<int,array<string,mixed>>,pager:\CodeIgniter\Pager\Pager,total:int}
     */
    public function queue(string $state, int $page = 1, int $perPage = 20): array
    {
        $builder = $this->select(
            'xs_directory_verifications.*,'
            . ' l.display_name AS listing_name, l.slug AS listing_slug,'
            . ' l.email AS listing_email, l.city AS listing_city,'
            . ' l.status AS listing_status, l.is_verified AS listing_email_verified,'
            . ' l.country AS listing_country'
        )->join('xs_directory_listings AS l', 'l.id = xs_directory_verifications.listing_id', 'left');

        if (in_array($state, self::STATES, true)) {
            $builder->where('xs_directory_verifications.state', $state);
        }

        // Oldest first: a review queue is a queue, and the person who has been
        // waiting longest should be the one an admin sees at the top.
        $rows = $builder->orderBy('xs_directory_verifications.submitted_at', 'ASC')
            ->orderBy('xs_directory_verifications.id', 'ASC')
            ->paginate($perPage, 'default', $page);

        return [
            'rows'  => $rows,
            'pager' => $this->pager,
            'total' => $this->pager->getTotal('default'),
        ];
    }

    /**
     * Row counts per state for the queue tabs, with every state present as a
     * key even at zero — the view should not have to guess whether a missing
     * key means none or means broken.
     *
     * @return array<string,int>
     */
    public function counts(): array
    {
        $counts = array_fill_keys(self::STATES, 0);

        $rows = $this->select('state, COUNT(*) AS n')->groupBy('state')->findAll();
        foreach ($rows as $row) {
            $counts[(string) $row['state']] = (int) $row['n'];
        }

        $counts['all'] = array_sum($counts);

        return $counts;
    }
}

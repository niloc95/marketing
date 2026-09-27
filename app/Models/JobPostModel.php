<?php

namespace App\Models;

use CodeIgniter\Model;

/**
 * Jobs board posts. See the migration for the two kinds and why every post
 * has a closing date; App\Services\JobBoardService owns the rules.
 */
class JobPostModel extends Model
{
    protected $table          = 'xs_directory_job_posts';
    protected $primaryKey     = 'id';
    protected $returnType     = 'array';
    protected $useTimestamps  = true;
    protected $useSoftDeletes = true;
    protected $allowedFields  = [
        'kind', 'slug', 'listing_id',
        'poster_name', 'poster_email', 'poster_phone', 'company_name',
        'title', 'description', 'category_id', 'province', 'city', 'is_remote',
        'employment_type', 'salary_min', 'salary_max', 'salary_period', 'apply_url', 'apply_email',
        'budget_text', 'needed_by',
        'status', 'reject_reason', 'flagged_reason',
        'verify_token', 'verify_expires', 'manage_token', 'manage_expires',
        'published_at', 'valid_through', 'reminded_at', 'ended_at',
        'report_count', 'response_count', 'price_cents',
    ];

    public const KIND_JOB     = 'job';
    public const KIND_SERVICE = 'service';

    public const KINDS = [self::KIND_JOB, self::KIND_SERVICE];

    /**
     *   unverified → unlisted poster has not clicked the email link yet
     *   pending    → waiting for an admin (unlisted, flagged, or reported)
     *   published  → public
     *   rejected   → admin said no, with a reason the poster is emailed
     *   closed     → poster or admin took it down
     *   expired    → valid_through passed (spark jobs:expire)
     */
    public const STATUS_UNVERIFIED = 'unverified';
    public const STATUS_PENDING    = 'pending';
    public const STATUS_PUBLISHED  = 'published';
    public const STATUS_REJECTED   = 'rejected';
    public const STATUS_CLOSED     = 'closed';
    public const STATUS_EXPIRED    = 'expired';

    public const STATUSES = [
        self::STATUS_UNVERIFIED,
        self::STATUS_PENDING,
        self::STATUS_PUBLISHED,
        self::STATUS_REJECTED,
        self::STATUS_CLOSED,
        self::STATUS_EXPIRED,
    ];

    /**
     * Published and not past its closing date. The date check is here as well
     * as in the nightly expiry run, so a post never shows for the hours
     * between midnight and the cron.
     *
     * @return $this
     */
    public function live()
    {
        return $this->where($this->table . '.status', self::STATUS_PUBLISHED)
            ->where($this->table . '.valid_through >=', date('Y-m-d'));
    }

    /** @return list<array<string,mixed>> */
    public function liveForListing(int $listingId, int $limit = 10): array
    {
        return $this->live()
            ->where('listing_id', $listingId)
            ->orderBy('published_at', 'DESC')
            ->findAll($limit);
    }

    /** Every post a listing owns, newest first, for the owner dashboard. */
    public function forListing(int $listingId): array
    {
        return $this->where('listing_id', $listingId)
            ->orderBy('created_at', 'DESC')
            ->findAll(50);
    }

    /**
     * One page of the admin queue, joined to the listing for its name.
     * 'ended' groups closed and expired, which an admin looks through the
     * same way.
     *
     * @return array{rows:array<int,array<string,mixed>>,pager:\CodeIgniter\Pager\Pager,total:int}
     */
    public function queue(string $status, int $page = 1, int $perPage = 20): array
    {
        $t       = $this->table;
        $builder = $this->select($t . '.*, l.display_name AS listing_name, l.slug AS listing_slug')
            ->join('xs_directory_listings AS l', 'l.id = ' . $t . '.listing_id', 'left');

        if ($status === 'ended') {
            $builder->whereIn($t . '.status', [self::STATUS_CLOSED, self::STATUS_EXPIRED]);
        } elseif (in_array($status, self::STATUSES, true)) {
            $builder->where($t . '.status', $status);
        }

        // The review tab is a queue, oldest first; the rest are for looking
        // things up, where the newest is what an admin wants.
        $builder->orderBy($t . '.created_at', $status === self::STATUS_PENDING ? 'ASC' : 'DESC')
            ->orderBy($t . '.id', 'DESC');

        $rows = $builder->paginate($perPage, 'default', $page);

        return [
            'rows'  => $rows,
            'pager' => $this->pager,
            'total' => $this->pager->getTotal('default'),
        ];
    }

    /**
     * Row counts per status for the queue tabs, every status present.
     *
     * @return array<string,int>
     */
    public function counts(): array
    {
        $counts = array_fill_keys(self::STATUSES, 0);

        foreach ($this->select('status, COUNT(*) AS n')->groupBy('status')->findAll() as $row) {
            $counts[(string) $row['status']] = (int) $row['n'];
        }

        $counts['ended'] = $counts[self::STATUS_CLOSED] + $counts[self::STATUS_EXPIRED];

        return $counts;
    }
}

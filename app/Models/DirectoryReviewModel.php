<?php

namespace App\Models;

use CodeIgniter\Model;

/**
 * Customer reviews. See the migration for the two gates before a review is
 * public; App\Services\ReviewService owns the rules and is the only writer.
 */
class DirectoryReviewModel extends Model
{
    protected $table          = 'xs_directory_reviews';
    protected $primaryKey     = 'id';
    protected $returnType     = 'array';
    protected $useTimestamps  = true;
    protected $useSoftDeletes = true;
    protected $allowedFields  = [
        'listing_id', 'rating', 'body',
        'reviewer_name', 'reviewer_email', 'email_hash', 'ip_hash',
        'status', 'reject_reason', 'flagged_reason',
        'verify_token', 'verify_expires',
        'owner_reply', 'owner_reply_at',
        'published_at', 'decided_at', 'report_count',
    ];

    /**
     *   unverified → the reviewer has not clicked the email link yet
     *   pending    → waiting for an admin (new, or sent back by reports)
     *   published  → public, and counted in the listing's totals
     *   rejected   → admin said no before it was ever shown
     *   hidden     → admin took a published review down
     */
    public const STATUS_UNVERIFIED = 'unverified';
    public const STATUS_PENDING    = 'pending';
    public const STATUS_PUBLISHED  = 'published';
    public const STATUS_REJECTED   = 'rejected';
    public const STATUS_HIDDEN     = 'hidden';

    public const STATUSES = [
        self::STATUS_UNVERIFIED,
        self::STATUS_PENDING,
        self::STATUS_PUBLISHED,
        self::STATUS_REJECTED,
        self::STATUS_HIDDEN,
    ];

    /**
     * A page of a listing's published reviews, newest first.
     *
     * @return list<array<string,mixed>>
     */
    public function publishedFor(int $listingId, int $limit = 5, int $offset = 0): array
    {
        return $this->where('listing_id', $listingId)
            ->where('status', self::STATUS_PUBLISHED)
            ->orderBy('published_at', 'DESC')
            ->orderBy('id', 'DESC')
            ->findAll($limit, $offset);
    }

    /**
     * What the owner dashboard lists: published reviews, plus any that
     * reports sent back for a second look, so a review never just vanishes
     * from the owner's view.
     *
     * @return list<array<string,mixed>>
     */
    public function forOwner(int $listingId): array
    {
        return $this->where('listing_id', $listingId)
            ->whereIn('status', [self::STATUS_PUBLISHED, self::STATUS_PENDING])
            ->where('published_at IS NOT NULL', null, false)
            ->orderBy('published_at', 'DESC')
            ->findAll(50);
    }

    /**
     * One page of the admin queue, joined to the listing for its name.
     *
     * @return array{rows:array<int,array<string,mixed>>,pager:\CodeIgniter\Pager\Pager,total:int}
     */
    public function queue(string $status, int $page = 1, int $perPage = 20): array
    {
        $t       = $this->table;
        $builder = $this->select($t . '.*, l.display_name AS listing_name, l.slug AS listing_slug')
            ->join('xs_directory_listings AS l', 'l.id = ' . $t . '.listing_id', 'left');

        if (in_array($status, self::STATUSES, true)) {
            $builder->where($t . '.status', $status);
        }

        // The pending tab is a queue, oldest first; the rest are for looking
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

        return $counts;
    }
}

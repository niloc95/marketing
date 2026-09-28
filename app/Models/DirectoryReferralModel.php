<?php

namespace App\Models;

use CodeIgniter\Model;

/**
 * "Recommend a business" submissions. Written only through ReferralService;
 * see the migration for why this is a queue an admin works, not a mailing list.
 */
class DirectoryReferralModel extends Model
{
    public const STATUS_PENDING   = 'pending';
    public const STATUS_INVITED   = 'invited';
    public const STATUS_LISTED    = 'listed';
    public const STATUS_DISMISSED = 'dismissed';

    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_INVITED,
        self::STATUS_LISTED,
        self::STATUS_DISMISSED,
    ];

    public const RELATIONSHIPS = [
        'customer'       => "I'm a customer",
        'owner_or_staff' => 'I own or work there',
        'other'          => 'Other',
    ];

    protected $table         = 'xs_directory_referrals';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = true;
    protected $allowedFields = [
        'business_name', 'business_email', 'business_phone', 'website', 'category_id',
        'city', 'province', 'note', 'referrer_name', 'referrer_email', 'notify_referrer',
        'relationship', 'status', 'invite_token', 'invited_at', 'listing_id', 'ip_hash',
        'pruned_at',
    ];

    /**
     * One page of the admin queue. Pending is oldest first, because it is the
     * tab with work in it; the others are for looking things up.
     *
     * @return array{rows:list<array<string,mixed>>,pager:\CodeIgniter\Pager\Pager,total:int}
     */
    public function queue(string $status, int $page = 1, int $perPage = 20): array
    {
        $t       = $this->table;
        $builder = $this->select($t . '.*, c.name AS category_name, l.display_name AS listing_name, l.slug AS listing_slug')
            ->join('xs_directory_categories AS c', 'c.id = ' . $t . '.category_id', 'left')
            ->join('xs_directory_listings AS l', 'l.id = ' . $t . '.listing_id', 'left');

        if (in_array($status, self::STATUSES, true)) {
            $builder->where($t . '.status', $status);
        }

        $builder->orderBy($t . '.created_at', $status === self::STATUS_PENDING ? 'ASC' : 'DESC')
            ->orderBy($t . '.id', 'DESC');

        $rows = $builder->paginate($perPage, 'default', $page);

        return [
            'rows'  => $rows,
            'pager' => $this->pager,
            'total' => $this->pager->getTotal('default'),
        ];
    }

    /** @return array<string,int> */
    public function counts(): array
    {
        $counts = array_fill_keys(self::STATUSES, 0);

        foreach ($this->select('status, COUNT(*) AS n')->groupBy('status')->findAll() as $row) {
            $counts[(string) $row['status']] = (int) $row['n'];
        }

        return $counts;
    }
}

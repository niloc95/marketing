<?php

namespace App\Models;

use CodeIgniter\Model;

/**
 * Partner Program affiliates. Written only through PartnerService; see the
 * migration for what each table holds.
 */
class DirectoryPartnerModel extends Model
{
    public const STATUS_APPLIED   = 'applied';
    public const STATUS_APPROVED  = 'approved';
    public const STATUS_REJECTED  = 'rejected';
    public const STATUS_SUSPENDED = 'suspended';

    public const STATUSES = [
        self::STATUS_APPLIED,
        self::STATUS_APPROVED,
        self::STATUS_REJECTED,
        self::STATUS_SUSPENDED,
    ];

    protected $table         = 'xs_directory_partners';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = true;
    protected $allowedFields = [
        'name', 'email', 'phone', 'company', 'website', 'promo_plan', 'code', 'status',
        'commission_rate', 'bank_details', 'login_token', 'login_expires',
        'decided_at', 'decided_by', 'admin_note',
    ];

    /** @return array<string,mixed>|null */
    public function findByEmail(string $email): ?array
    {
        $row = $this->where('email', strtolower(trim($email)))->first();

        return is_array($row) ? $row : null;
    }

    /** An approved partner by link code, or null. */
    public function findApprovedByCode(string $code): ?array
    {
        $code = strtolower(trim($code));
        if (! preg_match('/^[a-z0-9-]{3,40}$/', $code)) {
            return null;
        }

        $row = $this->where('code', $code)->where('status', self::STATUS_APPROVED)->first();

        return is_array($row) ? $row : null;
    }

    /**
     * One page of the admin list. Applications oldest first, because that is
     * the tab with work in it.
     *
     * @return array{rows:list<array<string,mixed>>,pager:\CodeIgniter\Pager\Pager,total:int}
     */
    public function queue(string $status, int $page = 1, int $perPage = 20): array
    {
        if (in_array($status, self::STATUSES, true)) {
            $this->where('status', $status);
        }

        $rows = $this->orderBy('created_at', $status === self::STATUS_APPLIED ? 'ASC' : 'DESC')
            ->orderBy('id', 'DESC')
            ->paginate($perPage, 'default', $page);

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

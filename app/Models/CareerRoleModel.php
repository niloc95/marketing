<?php

namespace App\Models;

use CodeIgniter\Model;

/**
 * Open roles at WebScheduler Local. Written only from /admin/careers through
 * CareersService; see the migration for why these are not Jobs board posts.
 */
class CareerRoleModel extends Model
{
    public const STATUS_DRAFT  = 'draft';
    public const STATUS_OPEN   = 'open';
    public const STATUS_CLOSED = 'closed';

    public const STATUSES = [self::STATUS_DRAFT, self::STATUS_OPEN, self::STATUS_CLOSED];

    protected $table         = 'xs_directory_career_roles';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = true;
    protected $allowedFields = [
        'slug', 'title', 'team', 'summary', 'description',
        'good_fit', 'strong_candidates', 'projects', 'logistics', 'screening_level', 'employment_type',
        'city', 'province', 'is_remote', 'salary_min', 'salary_max', 'salary_period',
        'status', 'published_at', 'valid_through', 'sort_order',
    ];

    /**
     * Open and not past its closing date, in page order. The date is checked
     * here rather than by a cron, so a role can never outlive its date.
     *
     * @return list<array<string,mixed>>
     */
    public function open(): array
    {
        return $this->where('status', self::STATUS_OPEN)
            ->groupStart()
                ->where('valid_through', null)
                ->orWhere('valid_through >=', date('Y-m-d'))
            ->groupEnd()
            ->orderBy('sort_order', 'ASC')
            ->orderBy('title', 'ASC')
            ->findAll();
    }

    /** @return array<string,mixed>|null */
    public function findBySlug(string $slug): ?array
    {
        $row = $this->where('slug', $slug)->first();

        return is_array($row) ? $row : null;
    }
}

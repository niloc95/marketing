<?php

namespace App\Models;

use App\Models\Concerns\ResolvesStoredPath;
use CodeIgniter\Model;

/**
 * Applications from /careers. Written only through CareersService. The CV
 * lives under storageRoot(), outside the web root, and is only ever served
 * by id from /admin/careers/applications/{id}/cv.
 */
class CareerApplicationModel extends Model
{
    use ResolvesStoredPath;

    public const STATUS_NEW         = 'new';
    public const STATUS_REVIEWING   = 'reviewing';
    public const STATUS_SHORTLISTED = 'shortlisted';
    public const STATUS_REJECTED    = 'rejected';
    public const STATUS_HIRED       = 'hired';

    public const STATUSES = [
        self::STATUS_NEW         => 'New',
        self::STATUS_REVIEWING   => 'Reviewing',
        self::STATUS_SHORTLISTED => 'Shortlisted',
        self::STATUS_REJECTED    => 'Not taken further',
        self::STATUS_HIRED       => 'Hired',
    ];

    protected $table         = 'xs_directory_career_applications';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = true;
    protected $allowedFields = [
        'role_id', 'name', 'email', 'phone', 'linkedin_url', 'message',
        'screening_result', 'screening_score', 'screening_total',
        'cv_path', 'cv_name', 'cv_mime', 'cv_sha256', 'consent_at',
        'status', 'admin_notes', 'purged_at',
    ];

    public static function storageRoot(): string
    {
        return rtrim(WRITEPATH, '/\\') . '/careers';
    }

    /**
     * One page of the admin list, newest first, joined to the role title.
     * $minScore keeps only applications that answered screening questions
     * and got at least that many right.
     *
     * @return array{rows:list<array<string,mixed>>,pager:\CodeIgniter\Pager\Pager}
     */
    public function queue(string $status, ?int $roleId, int $page = 1, int $perPage = 25, ?int $minScore = null): array
    {
        $this->select('xs_directory_career_applications.*, r.title AS role_title')
            ->join('xs_directory_career_roles r', 'r.id = xs_directory_career_applications.role_id', 'left');

        if (array_key_exists($status, self::STATUSES)) {
            $this->where('xs_directory_career_applications.status', $status);
        }
        if ($roleId !== null) {
            $roleId === 0
                ? $this->where('xs_directory_career_applications.role_id', null)
                : $this->where('xs_directory_career_applications.role_id', $roleId);
        }

        if ($minScore !== null) {
            $this->where('xs_directory_career_applications.screening_score >=', $minScore);
        }

        $rows = $this->orderBy('xs_directory_career_applications.created_at', 'DESC')
            ->orderBy('xs_directory_career_applications.id', 'DESC')
            ->paginate($perPage, 'default', $page);

        return ['rows' => $rows, 'pager' => $this->pager];
    }

    /** @return array<string,int> */
    public function counts(): array
    {
        $counts = array_fill_keys(array_keys(self::STATUSES), 0);

        foreach ($this->select('status, COUNT(*) AS n')->where('purged_at', null)->groupBy('status')->findAll() as $row) {
            $counts[(string) $row['status']] = (int) $row['n'];
        }

        return $counts;
    }
}

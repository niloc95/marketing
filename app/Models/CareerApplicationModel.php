<?php

namespace App\Models;

use CodeIgniter\Model;

/**
 * Applications from /careers. Written only through CareersService. The CV
 * lives under storageRoot(), outside the web root, and is only ever served
 * by id from /admin/careers/applications/{id}/cv.
 */
class CareerApplicationModel extends Model
{
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
        'cv_path', 'cv_name', 'cv_mime', 'cv_sha256', 'consent_at',
        'status', 'admin_notes', 'purged_at',
    ];

    public static function storageRoot(): string
    {
        return rtrim(WRITEPATH, '/\\') . '/careers';
    }

    /**
     * The absolute path of a stored CV, or null when it is missing or the
     * stored value tries to leave the storage root. Same checks as
     * DirectoryVerificationDocumentModel::resolvePath().
     */
    public function resolvePath(string $path): ?string
    {
        $path = ltrim(trim($path), '/');
        if ($path === '' || str_contains($path, "\0")) {
            return null;
        }

        $root = realpath(self::storageRoot());
        if ($root === false) {
            return null;
        }
        $root = rtrim($root, '/');
        $full = realpath($root . '/' . $path);

        if ($full === false || ! str_starts_with($full, $root . '/') || ! is_file($full)) {
            return null;
        }

        return $full;
    }

    /**
     * One page of the admin list, newest first, joined to the role title.
     *
     * @return array{rows:list<array<string,mixed>>,pager:\CodeIgniter\Pager\Pager}
     */
    public function queue(string $status, ?int $roleId, int $page = 1, int $perPage = 25): array
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

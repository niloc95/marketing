<?php

namespace App\Models;

use CodeIgniter\Model;

/** "Report this post" clicks, one per visitor per post. */
class JobReportModel extends Model
{
    protected $table         = 'xs_directory_job_reports';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = true;
    protected $allowedFields = ['post_id', 'reason', 'ip_hash', 'digested_at'];

    /** @return list<array<string,mixed>> */
    public function forPost(int $postId): array
    {
        return $this->where('post_id', $postId)->orderBy('created_at', 'DESC')->findAll(20);
    }
}

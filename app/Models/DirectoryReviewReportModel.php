<?php

namespace App\Models;

use CodeIgniter\Model;

/** "Report this review" clicks, one per visitor (or the owner) per review. */
class DirectoryReviewReportModel extends Model
{
    protected $table         = 'xs_directory_review_reports';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = true;
    protected $allowedFields = ['review_id', 'reason', 'ip_hash', 'by_owner', 'digested_at'];

    /** @return list<array<string,mixed>> */
    public function forReview(int $reviewId): array
    {
        return $this->where('review_id', $reviewId)->orderBy('created_at', 'DESC')->findAll(20);
    }
}

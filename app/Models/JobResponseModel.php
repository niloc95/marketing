<?php

namespace App\Models;

use CodeIgniter\Model;

/**
 * A listed business's reply to a service request. One per business per post
 * (unique key); the per-post cap is JobBoardService's.
 */
class JobResponseModel extends Model
{
    protected $table         = 'xs_directory_job_responses';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = true;
    protected $allowedFields = ['post_id', 'listing_id', 'message'];

    public function hasResponded(int $postId, int $listingId): bool
    {
        return $this->where('post_id', $postId)->where('listing_id', $listingId)->countAllResults() > 0;
    }
}

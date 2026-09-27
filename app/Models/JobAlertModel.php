<?php

namespace App\Models;

use CodeIgniter\Model;

/**
 * Lead alerts sent: one row per business per service request. See the
 * migration for why the unique key matters.
 */
class JobAlertModel extends Model
{
    protected $table         = 'xs_directory_job_alerts';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = true;
    protected $allowedFields = ['post_id', 'listing_id'];
}

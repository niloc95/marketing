<?php

namespace App\Models;

use CodeIgniter\Model;

/** Rows behind ApplicationOnceService. Written only by it. */
class DirectoryApplicationKeyModel extends Model
{
    protected $table         = 'xs_directory_application_keys';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = false;
    protected $allowedFields = ['scope', 'target_id', 'kind', 'hash', 'created_at'];
}

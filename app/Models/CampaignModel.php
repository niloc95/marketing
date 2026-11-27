<?php

namespace App\Models;

use CodeIgniter\Model;

/**
 * Communications campaigns (/admin/communications). Written only through
 * App\Services\Communications\CommunicationsService; see the migration.
 */
class CampaignModel extends Model
{
    public const STATUS_DRAFT     = 'draft';
    public const STATUS_SENDING   = 'sending';
    public const STATUS_PAUSED    = 'paused';
    public const STATUS_DONE      = 'done';
    public const STATUS_CANCELLED = 'cancelled';

    protected $table         = 'xs_campaigns';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = true;
    protected $allowedFields = [
        'kind', 'sequence', 'name', 'subject', 'body', 'audience', 'daily_limit', 'status', 'paused_reason',
        'test_sent_at', 'started_at', 'finished_at',
    ];
}

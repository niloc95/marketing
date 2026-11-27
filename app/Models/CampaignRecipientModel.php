<?php

namespace App\Models;

use CodeIgniter\Model;

/**
 * The communications send log: one row per campaign and profile. Written only
 * through App\Services\Communications\CommunicationsService.
 */
class CampaignRecipientModel extends Model
{
    public const STATUS_QUEUED  = 'queued';
    public const STATUS_SENT    = 'sent';
    public const STATUS_FAILED  = 'failed';
    public const STATUS_SKIPPED = 'skipped';

    protected $table         = 'xs_campaign_recipients';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = true;
    protected $allowedFields = ['campaign_id', 'listing_id', 'email', 'status', 'reason', 'attempts', 'sent_at', 'step', 'next_send_at', 'entered_at'];
}

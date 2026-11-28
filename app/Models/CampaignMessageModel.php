<?php

namespace App\Models;

use CodeIgniter\Model;

/**
 * One row per campaign email that went out, any campaign. Counts the daily
 * limit and the gap between two emails to one address, and holds the hash of
 * a claim link (ClaimService reads it). Written only through
 * App\Services\Communications\CommunicationsService.
 */
class CampaignMessageModel extends Model
{
    protected $table         = 'xs_campaign_messages';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = false;
    protected $allowedFields = ['campaign_id', 'recipient_id', 'listing_id', 'step', 'email', 'subject', 'link_hash', 'link_expires', 'sent_at'];
}

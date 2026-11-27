<?php

namespace App\Models;

use CodeIgniter\Model;

/**
 * The steps of a sequence campaign (claim, verified): each step's edited
 * subject, paragraphs and delay. Written only through
 * App\Services\Communications\CommunicationsService.
 */
class CampaignStepModel extends Model
{
    protected $table         = 'xs_campaign_steps';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = true;
    protected $allowedFields = ['campaign_id', 'step', 'subject', 'body', 'delay_days'];
}

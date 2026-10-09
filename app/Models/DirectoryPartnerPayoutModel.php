<?php

namespace App\Models;

use CodeIgniter\Model;

/** One EFT an admin made to a partner, and the commissions it settled. */
class DirectoryPartnerPayoutModel extends Model
{
    protected $table         = 'xs_directory_partner_payouts';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = true;
    protected $allowedFields = ['partner_id', 'total', 'eft_reference', 'paid_at', 'paid_by'];

    /** @return list<array<string,mixed>> */
    public function forPartner(int $partnerId): array
    {
        return $this->where('partner_id', $partnerId)->orderBy('paid_at', 'DESC')->findAll();
    }
}

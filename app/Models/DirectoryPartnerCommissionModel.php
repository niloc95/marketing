<?php

namespace App\Models;

use CodeIgniter\Model;

/**
 * Commission earned on one PayFast payment. The unique pf_payment_id is the
 * replay guard; see PartnerService::recordCommission().
 */
class DirectoryPartnerCommissionModel extends Model
{
    public const STATE_PENDING   = 'pending';
    public const STATE_AVAILABLE = 'available';
    public const STATE_PAID      = 'paid';
    public const STATE_VOID      = 'void';

    public const STATES = [
        self::STATE_PENDING,
        self::STATE_AVAILABLE,
        self::STATE_PAID,
        self::STATE_VOID,
    ];

    protected $table         = 'xs_directory_partner_commissions';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = true;
    protected $allowedFields = [
        'partner_id', 'listing_id', 'verification_id', 'pf_payment_id', 'payment_amount',
        'rate', 'amount', 'state', 'available_at', 'payout_id', 'void_reason',
    ];

    /**
     * Rand totals per state for one partner.
     *
     * @return array<string,float>
     */
    public function totals(int $partnerId): array
    {
        $totals = array_fill_keys(self::STATES, 0.0);

        foreach ($this->select('state, SUM(amount) AS total')->where('partner_id', $partnerId)->groupBy('state')->findAll() as $row) {
            $totals[(string) $row['state']] = round((float) $row['total'], 2);
        }

        return $totals;
    }

    /**
     * A partner's commissions with the business each came from, newest first.
     *
     * @return list<array<string,mixed>>
     */
    public function forPartner(int $partnerId, int $limit = 100): array
    {
        $t = $this->table;

        return $this->select($t . '.*, l.display_name AS listing_name, l.slug AS listing_slug')
            ->join('xs_directory_listings AS l', 'l.id = ' . $t . '.listing_id', 'left')
            ->where($t . '.partner_id', $partnerId)
            ->orderBy($t . '.id', 'DESC')
            ->findAll($limit);
    }
}

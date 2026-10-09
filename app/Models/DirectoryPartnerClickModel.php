<?php

namespace App\Models;

use CodeIgniter\Model;

/**
 * Daily click totals per partner link. No IP, no user agent: a count is all
 * the dashboard shows, so a count is all we keep.
 */
class DirectoryPartnerClickModel extends Model
{
    protected $table         = 'xs_directory_partner_clicks';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = false;
    protected $allowedFields = ['partner_id', 'day', 'clicks'];

    /**
     * Add one to today's total. A single upsert, so two clicks arriving
     * together cannot both insert a first row for the day.
     */
    public function bump(int $partnerId): void
    {
        $table = $this->db->prefixTable('directory_partner_clicks');
        $this->db->query(
            "INSERT INTO `{$table}` (`partner_id`, `day`, `clicks`) VALUES (?, ?, 1)
             ON DUPLICATE KEY UPDATE `clicks` = `clicks` + 1",
            [$partnerId, date('Y-m-d')]
        );
    }

    public function total(int $partnerId, ?string $since = null): int
    {
        $builder = $this->db->table($this->table)->selectSum('clicks', 'n')->where('partner_id', $partnerId);
        if ($since !== null) {
            $builder->where('day >=', $since);
        }

        return (int) ($builder->get()->getRowArray()['n'] ?? 0);
    }
}

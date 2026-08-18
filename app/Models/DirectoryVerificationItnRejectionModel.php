<?php

namespace App\Models;

use CodeIgniter\Model;
use Throwable;

/**
 * Refused PayFast notifications. See the migration for why these do not live in
 * directory_verification_events alongside the accepted ones.
 */
class DirectoryVerificationItnRejectionModel extends Model
{
    protected $table         = 'xs_directory_verification_itn_rejections';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = true;
    protected $allowedFields = [
        'verification_id', 'reason', 'pf_payment_id', 'm_payment_id',
        'payment_status', 'amount_gross', 'source_ip', 'payload',
    ];

    /**
     * Record a refusal. Never throws.
     *
     * This is called from the notification handler's only exit path, so a
     * failure here must not become the caller's problem: an exception would
     * turn a refusal into a 500, and PayFast would retry into that error
     * forever. Losing an audit row is bad; converting a quiet refusal into a
     * retry storm is worse. On failure we log at 'error' and carry on, which
     * leaves the WARNING line from the handler as the surviving evidence.
     *
     * @param array<string,string> $fields the parsed POST, for the audit trail
     */
    public function record(
        string $reason,
        array $fields = [],
        string $sourceIp = '',
        ?int $verificationId = null
    ): bool {
        try {
            $amount = isset($fields['amount_gross']) && $fields['amount_gross'] !== ''
                ? (float) $fields['amount_gross']
                : null;

            return (bool) $this->insert([
                'verification_id' => $verificationId,
                // The column is 191; a refusal reason is a fixed sentence we
                // write ourselves, but truncating beats an insert that fails
                // and loses the record entirely.
                'reason'          => mb_substr($reason, 0, 191),
                'pf_payment_id'   => $this->str($fields['pf_payment_id'] ?? null),
                'm_payment_id'    => $this->str($fields['m_payment_id'] ?? null),
                'payment_status'  => $this->str($fields['payment_status'] ?? null),
                'amount_gross'    => $amount,
                'source_ip'       => $sourceIp === '' ? null : mb_substr($sourceIp, 0, 45),
                'payload'         => $fields === []
                    ? null
                    : json_encode($fields, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ], false);
        } catch (Throwable $e) {
            log_message('error', 'Could not record a refused PayFast notification: ' . $e->getMessage());

            return false;
        }
    }

    /** Trim and length-cap a value PayFast supplied, or null if it sent nothing. */
    private function str(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : mb_substr($value, 0, 64);
    }

    /**
     * Drop rejections older than $days. Called by the nightly sweep: unlike
     * accepted events these have no ceiling, and every row holds a payer's
     * email and name inside `payload`.
     */
    public function prune(int $days = 365): int
    {
        $this->where('created_at <', date('Y-m-d H:i:s', time() - ($days * DAY)))->delete();

        return $this->db->affectedRows();
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function recent(int $limit = 50): array
    {
        return $this->orderBy('id', 'DESC')->findAll($limit);
    }
}

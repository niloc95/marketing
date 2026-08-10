<?php

namespace App\Models;

use CodeIgniter\Model;

/**
 * Accepted PayFast notifications. See the migration for why the unique index
 * on pf_payment_id is the replay guard rather than a flag somewhere.
 */
class DirectoryVerificationEventModel extends Model
{
    protected $table         = 'xs_directory_verification_events';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = true;
    protected $allowedFields = [
        'verification_id', 'pf_payment_id', 'payment_status', 'amount_gross', 'payload',
    ];

    /**
     * Claim a PayFast payment id. True means this notification is new and the
     * caller owns it; false means we have already processed it and the caller
     * must do nothing further.
     *
     * The insert is the claim. Checking for an existing row and then inserting
     * would leave a window between the two, and PayFast's retries are exactly
     * the kind of traffic that finds such windows — two deliveries of the same
     * notification arriving together would both see "not present" and both
     * extend the subscription by a month.
     *
     * The duplicate surfaces differently depending on configuration: with
     * DBDebug on, CI4 throws; with it off, insert() returns false. Both mean
     * the same thing here. A genuine database fault is indistinguishable from a
     * duplicate at this level and is treated the same way — declining to act on
     * a payment we cannot record is the safe direction, and PayFast will retry.
     *
     * @param array<string,mixed> $payload the full POST, for the audit trail
     */
    public function claim(int $verificationId, string $pfPaymentId, string $status, ?float $amountGross, array $payload): bool
    {
        try {
            return (bool) $this->insert([
                'verification_id' => $verificationId,
                'pf_payment_id'   => $pfPaymentId,
                'payment_status'  => $status,
                'amount_gross'    => $amountGross,
                'payload'         => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ], false);
        } catch (\Throwable $e) {
            log_message('info', 'PayFast notification not claimed (already seen, or storage refused it): ' . $e->getMessage());

            return false;
        }
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function forVerification(int $verificationId): array
    {
        return $this->where('verification_id', $verificationId)
            ->orderBy('id', 'DESC')
            ->findAll();
    }
}

<?php

namespace App\Services\Bookings;

use App\Libraries\TokenHash;
use App\Models\Bookings\BookingAuditLogModel;

/**
 * The audit log of one business (xs_booking_audit_logs), ported from
 * Bookings' AuditLogModel::log(). Written only here. Recording never throws:
 * a failed log line must not undo the change it describes.
 *
 * Values are stored as JSON and must not carry secrets: callers pass what
 * changed (setting values, roles), never passwords or tokens.
 */
class BookingAudit
{
    public const PAGE_SIZE = 50;

    public function record(int $listingId, ?int $accountId, string $action, ?string $targetType = null, ?int $targetId = null, mixed $old = null, mixed $new = null): void
    {
        try {
            $ip = service('request')->getIPAddress();
            BookingAuditLogModel::forBusiness($listingId)->insert([
                'account_id'  => $accountId,
                'action'      => mb_substr($action, 0, 64),
                'target_type' => $targetType,
                'target_id'   => $targetId,
                'old_value'   => $old === null ? null : json_encode($old, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'new_value'   => $new === null ? null : json_encode($new, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'ip_hash'     => is_string($ip) && $ip !== '' ? TokenHash::ip($ip) : null,
            ]);
        } catch (\Throwable $e) {
            log_message('error', 'Booking audit write failed: {message}', ['message' => $e->getMessage()]);
        }
    }

    /**
     * Newest first, with the name of who did it.
     *
     * @return list<array<string,mixed>>
     */
    public function recent(int $listingId, int $page = 1): array
    {
        $page = max(1, $page);

        return BookingAuditLogModel::forBusiness($listingId)
            ->select('xs_booking_audit_logs.*, a.name AS account_name, a.email AS account_email')
            ->join('xs_accounts a', 'a.id = xs_booking_audit_logs.account_id', 'left')
            ->orderBy('xs_booking_audit_logs.id', 'DESC')
            ->findAll(self::PAGE_SIZE, ($page - 1) * self::PAGE_SIZE);
    }

    /** How many lines recent() pages through, for the pager. */
    public function count(int $listingId): int
    {
        return BookingAuditLogModel::forBusiness($listingId)->countAllResults();
    }
}

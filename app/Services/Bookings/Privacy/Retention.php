<?php

namespace App\Services\Bookings\Privacy;

use App\Services\Bookings\BookingAudit;
use App\Services\Bookings\BookingMetricsCache;
use DateTimeImmutable;

/**
 * Customer data is kept for Config\Bookings::$retentionMonths (24, decision
 * D12, owner 5 Nov 2026) after a customer's last appointment, then the
 * personal fields go. Runs nightly (bookings:retention), across businesses.
 *
 * What goes, for a customer whose last appointment ended before the cutoff
 * (or who never had one, added before it):
 * - their name, email, phone, address, notes and answers on the customer row
 *   (the row stays, marked purged_at, so a business's counts and figures
 *   still add up; purged rows are left out everywhere a person is shown);
 * - the notes and the answers on their appointments.
 * What stays: the appointment's time, service, person and price snapshots
 * (no personal data), and an opt out (kept, hashed, so it is still honoured).
 *
 * Audit log lines older than the same period are deleted too. Delivery logs
 * (90 days), the notification queue and the outbox (30 days) are pruned
 * sooner by bookings:prune.
 */
class Retention
{
    private DateTimeImmutable $now;

    public function __construct(?DateTimeImmutable $now = null)
    {
        $this->now = $now ?? new DateTimeImmutable('now');
    }

    public function cutoff(): string
    {
        return gmdate('Y-m-d H:i:s', $this->now->modify('-' . config('Bookings')->retentionMonths . ' months')->getTimestamp());
    }

    /** @return array{customers:int,audit:int} */
    public function run(int $limit = 1000): array
    {
        $db     = db_connect();
        $cutoff = $this->cutoff();
        $rows   = $db->query(
            'SELECT c.id, c.listing_id FROM xs_booking_customers c
              WHERE c.purged_at IS NULL AND c.created_at < ?
                AND NOT EXISTS (SELECT 1 FROM xs_booking_appointments a WHERE a.customer_id = c.id AND a.end_at >= ?)
              ORDER BY c.id LIMIT ' . (int) $limit,
            [$cutoff, $cutoff],
        )->getResultArray();

        $nowUtc   = gmdate('Y-m-d H:i:s', $this->now->getTimestamp());
        $listings = [];
        foreach ($rows as $row) {
            $id = (int) $row['id'];
            $db->resetTransStatus()->transStart();
            $db->table('xs_booking_customers')->where('id', $id)->update([
                'first_name' => 'Former customer', 'last_name' => null, 'email' => null, 'phone' => null,
                'address' => null, 'notes' => null, 'custom_fields' => null, 'purged_at' => $nowUtc, 'updated_at' => $nowUtc,
            ]);
            $appointments = array_column($db->table('xs_booking_appointments')->select('id')->where('customer_id', $id)->get()->getResultArray(), 'id');
            if ($appointments !== []) {
                $db->table('xs_booking_appointment_fields')->whereIn('appointment_id', $appointments)->delete();
                $db->table('xs_booking_appointments')->whereIn('id', $appointments)->update(['notes' => null]);
            }
            $db->transComplete();
            $listings[(int) $row['listing_id']] = true;
        }
        foreach (array_keys($listings) as $listingId) {
            BookingMetricsCache::forget($listingId);
            (new BookingAudit())->record($listingId, null, 'customers.retention_purged', 'customer', null, null, ['months' => config('Bookings')->retentionMonths]);
        }

        $db->table('xs_booking_audit_logs')->where('created_at <', $cutoff)->delete();

        return ['customers' => count($rows), 'audit' => $db->affectedRows()];
    }
}

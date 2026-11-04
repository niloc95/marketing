<?php

namespace App\Models\Bookings;

/** Who changed what inside a business (xs_booking_audit_logs). Written only by BookingAudit. */
class BookingAuditLogModel extends BookingScopedModel
{
    protected $table         = 'xs_booking_audit_logs';
    protected $primaryKey    = 'id';
    protected $useTimestamps = true;
    protected $updatedField  = '';
    protected $allowedFields = ['listing_id', 'account_id', 'action', 'target_type', 'target_id', 'old_value', 'new_value', 'ip_hash'];
}

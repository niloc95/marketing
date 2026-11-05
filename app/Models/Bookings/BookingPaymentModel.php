<?php

namespace App\Models\Bookings;

/**
 * Deposit payments (xs_booking_payments): one row per attempt to pay and one
 * per deposit marked as paid by hand. Written only by DepositService.
 */
class BookingPaymentModel extends BookingScopedModel
{
    public const STARTED   = 'started';
    public const PAID      = 'paid';
    public const FAILED    = 'failed';
    public const REFUNDED  = 'refunded';

    protected $table         = 'xs_booking_payments';
    protected $primaryKey    = 'id';
    protected $useTimestamps = true;
    protected $allowedFields = [
        'listing_id', 'appointment_id', 'hash', 'reference', 'gateway', 'amount_cents', 'currency', 'status',
        'test', 'gateway_id', 'detail', 'recorded_by_member_id', 'paid_at', 'refunded_at',
    ];
}

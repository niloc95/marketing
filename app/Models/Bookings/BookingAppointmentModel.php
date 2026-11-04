<?php

namespace App\Models\Bookings;

/**
 * Appointments (xs_booking_appointments). Times UTC; see the migration for
 * the snapshot columns. Written only by the appointment service (Phase 4) and
 * BookingLock::reserve() (Phase 3).
 */
class BookingAppointmentModel extends BookingScopedModel
{
    public const STATUS_PENDING   = 'pending';
    public const STATUS_CONFIRMED = 'confirmed';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_NO_SHOW   = 'no_show';

    /** Statuses that hold the time: everything but cancelled. */
    public const HOLDS_TIME = [self::STATUS_PENDING, self::STATUS_CONFIRMED, self::STATUS_COMPLETED, self::STATUS_NO_SHOW];

    protected $table         = 'xs_booking_appointments';
    protected $primaryKey    = 'id';
    protected $useTimestamps = true;
    protected $allowedFields = [
        'listing_id', 'reference', 'hash', 'provider_id', 'service_id', 'customer_id', 'location_id',
        'start_at', 'end_at', 'hold_before_min', 'hold_after_min', 'stored_timezone', 'status', 'source',
        'notes', 'delivery_mode', 'video_link', 'service_name', 'service_duration_min', 'price_cents',
        'currency', 'provider_name', 'location_name', 'location_address', 'payment_status',
        'payment_amount_cents', 'public_token_hash', 'public_token_expires', 'reminder_sent',
        'created_by_member_id', 'cancelled_at',
    ];
}

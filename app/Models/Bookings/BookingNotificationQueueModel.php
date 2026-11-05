<?php

namespace App\Models\Bookings;

/**
 * Messages waiting to go out (xs_booking_notification_queue). Written only by
 * NotificationQueue (enqueue) and NotificationDispatcher (claim, send, retry).
 */
class BookingNotificationQueueModel extends BookingScopedModel
{
    protected $table         = 'xs_booking_notification_queue';
    protected $primaryKey    = 'id';
    protected $useTimestamps = true;
    protected $allowedFields = ['listing_id', 'appointment_id', 'event', 'channel', 'recipient_type', 'member_id', 'status', 'attempts',
        'max_attempts', 'run_after', 'locked_at', 'lock_token', 'last_error', 'sent_at', 'idempotency_key',
        'correlation_id', 'reminder_offset_min', 'schedule_fingerprint'];
}

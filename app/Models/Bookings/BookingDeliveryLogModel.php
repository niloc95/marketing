<?php

namespace App\Models\Bookings;

/**
 * One line per send attempt (xs_booking_delivery_logs), with the recipient
 * masked. Written only by DeliveryLog.
 */
class BookingDeliveryLogModel extends BookingScopedModel
{
    protected $table         = 'xs_booking_delivery_logs';
    protected $primaryKey    = 'id';
    protected $useTimestamps = true;
    protected $allowedFields = ['listing_id', 'queue_id', 'correlation_id', 'appointment_id', 'event', 'channel', 'recipient_type',
        'recipient', 'outcome', 'attempt', 'error'];
    protected $updatedField  = '';
}

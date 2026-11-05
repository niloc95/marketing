<?php

namespace App\Models\Bookings;

/**
 * The notification centre (xs_booking_inbox): one row per team member.
 * Written only by NotificationCentre.
 */
class BookingInboxModel extends BookingScopedModel
{
    protected $table         = 'xs_booking_inbox';
    protected $primaryKey    = 'id';
    protected $useTimestamps = true;
    protected $allowedFields = ['listing_id', 'member_id', 'appointment_id', 'event', 'title', 'body', 'read_at'];
    protected $updatedField  = '';
}

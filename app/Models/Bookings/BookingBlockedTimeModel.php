<?php

namespace App\Models\Bookings;

/** Time off, UTC; provider_id null means the whole business. Written only by TimeOffService. */
class BookingBlockedTimeModel extends BookingScopedModel
{
    protected $table         = 'xs_booking_blocked_times';
    protected $primaryKey    = 'id';
    protected $useTimestamps = true;
    protected $allowedFields = ['listing_id', 'provider_id', 'start_at', 'end_at', 'reason', 'created_by'];
}

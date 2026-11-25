<?php

namespace App\Models\Bookings;

/**
 * Whose bookings a member gets notices for (T36): member × provider.
 * Written only by TeamCover.
 */
class BookingNoticeRouteModel extends BookingScopedModel
{
    protected $table         = 'xs_booking_notice_routes';
    protected $primaryKey    = 'id';
    protected $useTimestamps = true;
    protected $allowedFields = ['listing_id', 'member_id', 'provider_id'];
}

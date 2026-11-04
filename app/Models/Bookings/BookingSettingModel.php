<?php

namespace App\Models\Bookings;

/** Per business booking settings (xs_booking_settings). Written only by BookingSettings. */
class BookingSettingModel extends BookingScopedModel
{
    protected $table         = 'xs_booking_settings';
    protected $primaryKey    = 'id';
    protected $useTimestamps = true;
    protected $allowedFields = ['listing_id', 'name', 'value', 'updated_by'];
}

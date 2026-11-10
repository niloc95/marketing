<?php

namespace App\Models\Bookings;

/** A provider's weekly hours: periods per weekday, each at a place and for some services (service_ids null = all), local time. Always read with a provider filter (Bookings' rule). Written only by TeamService. */
class BookingProviderHoursModel extends BookingScopedModel
{
    protected $table         = 'xs_booking_provider_hours';
    protected $primaryKey    = 'id';
    protected $useTimestamps = true;
    protected $allowedFields = ['listing_id', 'provider_id', 'weekday', 'start_time', 'end_time', 'breaks', 'location_id', 'service_ids'];
}

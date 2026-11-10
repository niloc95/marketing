<?php

namespace App\Models\Bookings;

/** People customers book (Bookings' providers). Written only by TeamService. */
class BookingProviderModel extends BookingScopedModel
{
    protected $table         = 'xs_booking_providers';
    protected $primaryKey    = 'id';
    protected $useTimestamps = true;
    protected $allowedFields = ['listing_id', 'team_member_id', 'member_id', 'slug', 'name', 'title', 'bio', 'color', 'bookable', 'hours_mode', 'hours_seen', 'sort_order', 'archived_at'];
}

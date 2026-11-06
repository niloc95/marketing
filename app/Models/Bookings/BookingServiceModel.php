<?php

namespace App\Models\Bookings;

/** What a business offers to book. Written only by CatalogueService; archived, never deleted, once in use. */
class BookingServiceModel extends BookingScopedModel
{
    protected $table         = 'xs_booking_services';
    protected $primaryKey    = 'id';
    protected $useTimestamps = true;
    protected $allowedFields = ['listing_id', 'slug', 'name', 'description', 'duration_min', 'length_checked', 'price_cents', 'currency', 'deposit_pct', 'buffer_before', 'buffer_after', 'category_id', 'delivery_modes', 'profile_service_name', 'sort_order', 'archived_at'];
}

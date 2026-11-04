<?php

namespace App\Models\Bookings;

/** Service categories of one business. Written only by CatalogueService. */
class BookingCategoryModel extends BookingScopedModel
{
    protected $table         = 'xs_booking_categories';
    protected $primaryKey    = 'id';
    protected $useTimestamps = true;
    protected $allowedFields = ['listing_id', 'name', 'color', 'sort_order'];
}

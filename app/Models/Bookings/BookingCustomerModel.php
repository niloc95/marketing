<?php

namespace App\Models\Bookings;

/**
 * A business's customers (xs_booking_customers). Unique (listing_id, email):
 * the same person at two businesses is two customers. Customers have no
 * account; they are reached by `hash` links. Written only by CustomerService.
 */
class BookingCustomerModel extends BookingScopedModel
{
    protected $table         = 'xs_booking_customers';
    protected $primaryKey    = 'id';
    protected $useTimestamps = true;
    protected $allowedFields = [
        'listing_id', 'hash', 'first_name', 'last_name', 'email', 'phone', 'address', 'notes',
        'custom_fields', 'purged_at',
    ];
}

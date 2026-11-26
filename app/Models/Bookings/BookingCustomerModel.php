<?php

namespace App\Models\Bookings;

/**
 * A business's customers (xs_booking_customers). Unique (listing_id, email):
 * the same person at two businesses is two customers. Customers have no
 * account; they are reached by `hash` links. Written only by CustomerService,
 * except the `login_*` columns (a returning patient's emailed code and link),
 * written only by ReturningPatientService.
 */
class BookingCustomerModel extends BookingScopedModel
{
    protected $table         = 'xs_booking_customers';
    protected $primaryKey    = 'id';
    protected $useTimestamps = true;
    protected $allowedFields = [
        'listing_id', 'hash', 'first_name', 'last_name', 'email', 'phone', 'address', 'notes',
        'custom_fields', 'custom_questions', 'purged_at',
        'login_token_hash', 'login_code_hash', 'login_expires', 'login_code_expires', 'login_tries',
        'login_failures', 'login_failed_at', 'login_sent_at',
    ];
}

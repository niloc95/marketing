<?php

namespace App\Models\Bookings;

/**
 * Customers who asked a business to stop messages on a channel
 * (xs_booking_opt_outs), by a hash of the address or number. Written only by OptOuts.
 */
class BookingOptOutModel extends BookingScopedModel
{
    protected $table         = 'xs_booking_opt_outs';
    protected $primaryKey    = 'id';
    protected $useTimestamps = true;
    protected $allowedFields = ['listing_id', 'channel', 'recipient_hash', 'source'];
    protected $updatedField  = '';
}

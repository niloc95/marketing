<?php

namespace App\Models\Bookings;

/**
 * API keys for /api/v1 (xs_booking_api_keys), each bound to one membership.
 * Written only by Api\ApiKeys.
 */
class BookingApiKeyModel extends BookingScopedModel
{
    protected $table         = 'xs_booking_api_keys';
    protected $primaryKey    = 'id';
    protected $useTimestamps = true;
    protected $allowedFields = ['listing_id', 'member_id', 'name', 'prefix', 'secret_hash', 'scopes', 'last_used_at', 'expires_at', 'revoked_at', 'created_by'];
}

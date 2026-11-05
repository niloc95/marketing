<?php

namespace App\Models\Bookings;

/**
 * The accounts a business brings (WhatsApp, deposits, Zoom, Google, webhook) (xs_booking_integrations), the
 * credentials encrypted in `config`. Written only by BookingIntegrations.
 */
class BookingIntegrationModel extends BookingScopedModel
{
    protected $table         = 'xs_booking_integrations';
    protected $primaryKey    = 'id';
    protected $useTimestamps = true;
    protected $allowedFields = ['listing_id', 'channel', 'provider', 'config', 'is_active', 'health', 'last_tested_at', 'last_error'];
}

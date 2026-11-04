<?php

namespace App\Models\Bookings;

/** Which provider offers which service. Written only by CatalogueService. */
class BookingProviderServiceModel extends BookingScopedModel
{
    protected $table         = 'xs_booking_provider_services';
    protected $primaryKey    = 'provider_id';
    protected $useTimestamps = false;
    protected $useAutoIncrement = false;
    protected $createdField = 'created_at';
    protected $updatedField = '';
    protected $allowedFields = ['listing_id', 'provider_id', 'service_id'];
}

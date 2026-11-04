<?php

namespace App\Models\Bookings;

/** Reception members assigned to providers. Written only by TeamService. */
class BookingStaffAssignmentModel extends BookingScopedModel
{
    protected $table         = 'xs_booking_staff_assignments';
    protected $primaryKey    = 'id';
    protected $useTimestamps = true;
    protected $allowedFields = ['listing_id', 'provider_id', 'member_id', 'assigned_by'];
}

<?php

namespace App\Models\Bookings;

/** Reception members assigned to providers. Written by TeamService (a practitioner's side) and TeamCover (the person's side, hand over). */
class BookingStaffAssignmentModel extends BookingScopedModel
{
    protected $table         = 'xs_booking_staff_assignments';
    protected $primaryKey    = 'id';
    protected $useTimestamps = true;
    protected $allowedFields = ['listing_id', 'provider_id', 'member_id', 'assigned_by'];
}

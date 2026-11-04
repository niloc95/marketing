<?php

namespace App\Models\Bookings;

use CodeIgniter\Model;

/**
 * Answers to the business's own questions on one appointment
 * (xs_booking_appointment_fields). No listing_id: always read and written
 * through an appointment id that AppointmentService has already opened
 * with its business scope. Written only by AppointmentService.
 */
class BookingAppointmentFieldModel extends Model
{
    protected $table         = 'xs_booking_appointment_fields';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = false;
    protected $allowedFields = ['appointment_id', 'field_key', 'value'];
}

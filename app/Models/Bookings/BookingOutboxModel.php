<?php

namespace App\Models\Bookings;

/**
 * Work for the outside world (xs_booking_outbox): webhooks, Google Calendar
 * and Zoom. Written only by Integrations\Outbox and the worker that runs it.
 */
class BookingOutboxModel extends BookingScopedModel
{
    public const QUEUED    = 'queued';
    public const DONE      = 'done';
    public const FAILED    = 'failed';
    public const CANCELLED = 'cancelled';

    public const WEBHOOK = 'webhook';
    public const GOOGLE  = 'google';
    public const ZOOM    = 'zoom';

    protected $table         = 'xs_booking_outbox';
    protected $primaryKey    = 'id';
    protected $useTimestamps = true;
    protected $allowedFields = ['listing_id', 'target', 'appointment_id', 'event', 'payload', 'status', 'attempts', 'next_attempt_at', 'response_code', 'last_error', 'done_at'];
}

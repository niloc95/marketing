<?php

namespace App\Models\Bookings;

/**
 * A business's own wording for a message (xs_booking_templates); the defaults
 * live in Config\BookingTemplates. Written only by NotificationTemplates.
 */
class BookingTemplateModel extends BookingScopedModel
{
    protected $table         = 'xs_booking_templates';
    protected $primaryKey    = 'id';
    protected $useTimestamps = true;
    protected $allowedFields = ['listing_id', 'event', 'channel', 'recipient', 'subject', 'body', 'updated_by'];
    protected $createdField  = '';
}

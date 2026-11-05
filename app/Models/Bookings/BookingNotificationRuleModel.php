<?php

namespace App\Models\Bookings;

/**
 * Which messages a business sends (xs_booking_notification_rules): one row per
 * event, channel and recipient the business changed; the rest are the defaults in
 * NotificationRules. Written only by NotificationRules.
 */
class BookingNotificationRuleModel extends BookingScopedModel
{
    protected $table         = 'xs_booking_notification_rules';
    protected $primaryKey    = 'id';
    protected $useTimestamps = true;
    protected $allowedFields = ['listing_id', 'event', 'channel', 'recipient', 'enabled'];
    protected $createdField  = '';
}

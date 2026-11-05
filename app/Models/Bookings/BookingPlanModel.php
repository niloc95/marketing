<?php

namespace App\Models\Bookings;

use CodeIgniter\Model;

/**
 * The Bookings plan of each business (xs_booking_plans), one row per
 * business. Read across businesses by the webhook and the sweep, so not a
 * BookingScopedModel; written only by Plans\BookingPlanService.
 */
class BookingPlanModel extends Model
{
    public const TRIAL     = 'trial';
    public const ACTIVE    = 'active';
    public const CANCELLED = 'cancelled';

    protected $table         = 'xs_booking_plans';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = true;
    protected $allowedFields = [
        'listing_id', 'state', 'trial_ends_at', 'paid_until', 'amount_cents', 'payment_reference', 'payment_attempts',
        'paystack_customer_code', 'paystack_plan_code', 'paystack_subscription_code', 'paystack_email_token',
        'reminded_at', 'cancelled_at', 'note',
    ];
}

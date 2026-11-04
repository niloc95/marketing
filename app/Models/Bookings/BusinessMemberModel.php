<?php

namespace App\Models\Bookings;

/**
 * Account × business × role (xs_business_members). Written only by
 * BusinessMemberService.
 */
class BusinessMemberModel extends BookingScopedModel
{
    public const STATUS_INVITED = 'invited';
    public const STATUS_ACTIVE  = 'active';
    public const STATUS_REMOVED = 'removed';

    protected $table         = 'xs_business_members';
    protected $primaryKey    = 'id';
    protected $useTimestamps = true;
    protected $allowedFields = ['account_id', 'listing_id', 'role', 'status', 'invited_by', 'notify_on_appointments'];
}

<?php

namespace App\Models;

use CodeIgniter\Model;

/**
 * Orders for the profile setup service (/profile-setup). Written only
 * through ProfileSetupService.
 */
class ProfileSetupOrderModel extends Model
{
    public const STATUS_AWAITING_PAYMENT = 'awaiting_payment';
    public const STATUS_PAID             = 'paid';
    public const STATUS_IN_PROGRESS      = 'in_progress';
    public const STATUS_DONE             = 'done';
    public const STATUS_CANCELLED        = 'cancelled';
    public const STATUS_REFUNDED         = 'refunded';

    /** Admin labels, in the order the queue's tabs show them. */
    public const STATUSES = [
        self::STATUS_PAID             => 'Paid, to do',
        self::STATUS_IN_PROGRESS      => 'In progress',
        self::STATUS_DONE             => 'Done',
        self::STATUS_AWAITING_PAYMENT => 'Awaiting payment',
        self::STATUS_CANCELLED        => 'Cancelled',
        self::STATUS_REFUNDED         => 'Refunded',
    ];

    /** Statuses an admin may set by hand. Paid is only ever set by PayFast. */
    public const ADMIN_SETTABLE = [
        self::STATUS_IN_PROGRESS,
        self::STATUS_DONE,
        self::STATUS_CANCELLED,
        self::STATUS_REFUNDED,
    ];

    protected $table         = 'xs_directory_profile_setup_orders';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = true;
    protected $allowedFields = [
        'reference', 'name', 'business_name', 'email', 'phone', 'profile_url', 'notes',
        'amount', 'status', 'pf_payment_id', 'paid_at', 'terms_at', 'admin_notes',
    ];

    public function findByReference(string $reference): ?array
    {
        $reference = trim($reference);
        if ($reference === '') {
            return null;
        }

        $row = $this->where('reference', $reference)->first();

        return is_array($row) ? $row : null;
    }
}

<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * Rules for the Partner Program (App\Services\PartnerService).
 *
 * Every property can be overridden from .env as `partners.<name>`, e.g.
 * `partners.commissionRate = 25`. A single partner's rate is set on their row
 * at /admin/partners, which beats the default here.
 *
 * Changing a rate never reprices commission already earned: each commission
 * row stores the rate it was worked out at.
 */
class Partners extends BaseConfig
{
    /** Whether /partners takes applications and /p/{code} links track. */
    public bool $enabled = true;

    /** Percent of each cleared payment, before any per-partner rate. */
    public float $commissionRate = 20.0;

    /**
     * Months after a referred business's first payment during which its
     * payments earn commission. Counted from the subscription's activated_at.
     */
    public int $commissionMonths = 12;

    /**
     * Days a commission waits as "pending" before it can be paid out. Covers
     * a refund or a chargeback on the payment it came from.
     */
    public int $holdDays = 30;

    /** Smallest total, in rand, that the payout list offers to pay. */
    public float $minimumPayout = 500.0;

    /** How long the referral cookie lasts. Last click wins. */
    public int $cookieDays = 60;

    /**
     * A signup is credited to the cookie only if its profile was created this
     * recently. submitPublic() hands back an EXISTING profile's id when the
     * email is already listed, and that business was not brought in by
     * whoever's link was clicked today.
     */
    public int $attributionWindowMinutes = 60;

    /** How long an emailed dashboard sign-in link lasts. */
    public int $loginLinkMinutes = 60;
}

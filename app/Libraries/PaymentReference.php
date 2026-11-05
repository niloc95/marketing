<?php

namespace App\Libraries;

use App\Models\DirectoryVerificationModel;

/**
 * Every payment reference we hand a gateway, in one place (owner, 3 Nov 2026):
 *
 *   Profile setup       WSL-SETUP-000123
 *   Verified monthly    WSL-VER-M-000123     yearly WSL-VER-Y-000123
 *   Plus monthly        WSL-PLUS-M-000123    yearly WSL-PLUS-Y-000123
 *   Booking             WSB-BOOK-000123      reserved for the Bookings rebuild
 *
 * The number is the order's or subscription row's own id, zero padded to six
 * digits (a larger id prints wider), so it matches the admin screens. Paystack
 * and PayFast both refuse a reference they have already seen, so a row's second
 * reference adds the attempt: WSL-SETUP-000123-2. The attempt comes from the
 * row's payment_attempts counter, which only goes up, so a row never repeats a
 * reference, through a lapse, a cycle change or a switch.
 *
 * References minted before this (vb-45-…, il-45-…, ps-…) stay on their rows and
 * keep matching: every lookup is an exact string match.
 */
final class PaymentReference
{
    public const SETUP        = 'WSL-SETUP';
    public const VER_MONTHLY  = 'WSL-VER-M';
    public const VER_YEARLY   = 'WSL-VER-Y';
    public const PLUS_MONTHLY = 'WSL-PLUS-M';
    public const PLUS_YEARLY  = 'WSL-PLUS-Y';

    /**
     * Online bookings: an appointment is WSB-BOOK-000123 (BookingLock), and
     * each try at paying its deposit WSB-BOOK-000123-P1, P2 ... (a gateway
     * refuses a reference it has seen, so a second try needs its own).
     */
    public const BOOKING = 'WSB-BOOK';

    public static function forDeposit(int $appointmentId, int $attempt): string
    {
        return self::make(self::BOOKING, $appointmentId) . '-P' . max(1, $attempt);
    }

    public static function make(string $prefix, int $id, int $attempt = 1): string
    {
        return sprintf('%s-%06d', $prefix, max(0, $id)) . ($attempt > 1 ? '-' . $attempt : '');
    }

    /**
     * A badge or Plus reference. The International plan is Plus on .app, and
     * the old co.za International Profile shares its prefix.
     */
    public static function forSubscription(string $plan, string $cycle, int $id, int $attempt = 1): string
    {
        $yearly = $cycle === DirectoryVerificationModel::CYCLE_YEARLY;
        $prefix = $plan === DirectoryVerificationModel::PLAN_INTERNATIONAL
            ? ($yearly ? self::PLUS_YEARLY : self::PLUS_MONTHLY)
            : ($yearly ? self::VER_YEARLY : self::VER_MONTHLY);

        return self::make($prefix, $id, $attempt);
    }

    /** A profile setup reference, today's or one minted before 3 Nov 2026 ('ps-…'). */
    public static function isSetup(string $reference): bool
    {
        return str_starts_with($reference, self::SETUP . '-') || str_starts_with($reference, 'ps-');
    }
}

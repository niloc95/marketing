<?php

namespace App\Services\Bookings;

/**
 * The /bookings session keys, in one place. Only /bookings starts a session
 * for these; public pages stay sessionless.
 */
final class BusinessSession
{
    public const ACCOUNT = 'biz_account_id';
    public const LISTING = 'biz_listing_id';
    public const SEEN    = 'biz_seen';

    /** Start a signed in session for an account. */
    public static function signIn(int $accountId, ?int $listingId = null): void
    {
        // regenerate(true): the pre sign in id must stop working at once (see
        // Admin::attemptLogin() for why the argument matters).
        session()->regenerate(true);
        session()->set(self::ACCOUNT, $accountId);
        session()->set(self::SEEN, time());
        if ($listingId !== null) {
            session()->set(self::LISTING, $listingId);
        } else {
            session()->remove(self::LISTING);
        }
    }

    public static function chooseBusiness(int $listingId): void
    {
        session()->set(self::LISTING, $listingId);
    }

    public static function accountId(): int
    {
        return (int) (session()->get(self::ACCOUNT) ?? 0);
    }

    public static function listingId(): int
    {
        return (int) (session()->get(self::LISTING) ?? 0);
    }

    /**
     * Drop the business keys, then the session. The explicit remove matters:
     * destroy() is a no op under ENVIRONMENT=testing (see AdminFilter).
     */
    public static function signOut(): void
    {
        session()->remove([self::ACCOUNT, self::LISTING, self::SEEN]);
        session()->destroy();
    }
}

<?php

namespace App\Services;

use App\Models\Bookings\AccountModel;
use App\Models\DirectoryListingModel;
use App\Services\Bookings\AccountService;
use App\Services\Bookings\BookingAuthorization;
use App\Services\Bookings\BusinessMemberService;
use App\Services\Bookings\BusinessSession;
use Config\BookingPermissions;

/**
 * One sign in for every owner (owner, 10 Nov 2026): the business area at
 * /business is the only back office, Free profiles included. An owner is an
 * xs_accounts row with an admin membership of their profile; there is no
 * separate /manage session any more.
 *
 * The profile's email is the owner. Anything that proves that inbox (a manage
 * link, the signup verify link, an emailed sign in link) may sign in as the
 * account on that email and makes it an admin of every profile on it.
 */
final class OwnerAccess
{
    /**
     * Sign in as the owner of a profile whose email was just proved. Returns
     * the account, or null when that account was switched off.
     *
     * @param array<string,mixed> $listing
     *
     * @return array<string,mixed>|null
     */
    public static function signInFor(array $listing): ?array
    {
        $accounts = new AccountService();
        $name     = trim((string) ($listing['contact_person'] ?? '')) ?: (string) ($listing['display_name'] ?? '');
        $account  = $accounts->findOrCreate((string) $listing['email'], $name);
        if (($account['status'] ?? '') !== AccountModel::STATUS_ACTIVE) {
            return null;
        }

        self::claimByEmail($account);
        $accounts->markSignedIn((int) $account['id']);
        BusinessSession::signIn((int) $account['id'], (int) $listing['id']);

        return $account;
    }

    /**
     * Make an account an admin of every profile on its email. Called after
     * the account proved its email (a link) or its password.
     *
     * @param array<string,mixed> $account
     */
    public static function claimByEmail(array $account): void
    {
        $email = AccountModel::normaliseEmail((string) ($account['email'] ?? ''));
        if ($email === '') {
            return;
        }
        $members = new BusinessMemberService();
        foreach (self::listingsForEmail($email) as $listing) {
            $member = $members->activeMembership((int) $account['id'], (int) $listing['id']);
            if (($member['role'] ?? null) === BookingPermissions::ADMIN) {
                continue;
            }
            $members->ensureAdmin((int) $listing['id'], (int) $account['id']);
        }
    }

    /**
     * The account for an owner's email, created without a password when the
     * email has a profile but no account yet. Null when the email owns no
     * profile and has no account, so the sign in form stays silent.
     *
     * @return array<string,mixed>|null
     */
    public static function accountForEmail(string $email): ?array
    {
        $accounts = new AccountService();
        $existing = $accounts->findByEmail($email);
        if ($existing !== null) {
            return $existing;
        }
        $listings = self::listingsForEmail($email);
        if ($listings === []) {
            return null;
        }
        $first = $listings[0];
        $name  = trim((string) ($first['contact_person'] ?? '')) ?: (string) $first['display_name'];

        return $accounts->findOrCreate($email, $name);
    }

    /**
     * The profile the signed in account is working on, when it may edit it:
     * an active account, an active membership with profile.edit, and the
     * profile still there. Null otherwise.
     *
     * @return array<string,mixed>|null
     */
    public static function listing(): ?array
    {
        $accountId = BusinessSession::accountId();
        $listingId = BusinessSession::listingId();
        if ($accountId <= 0 || $listingId <= 0) {
            return null;
        }
        $seen = (int) (session()->get(BusinessSession::SEEN) ?? 0);
        if (time() - $seen > config('Bookings')->idleSeconds) {
            return null;
        }
        $account = (new AccountModel())->find($accountId);
        $member  = (new BusinessMemberService())->activeMembership($accountId, $listingId);
        if (! is_array($account) || $account['status'] !== AccountModel::STATUS_ACTIVE
            || $member === null || ! (new BookingAuthorization())->can($member, 'profile.edit')) {
            return null;
        }
        $row = (new DirectoryListingModel())->find($listingId);
        if (! is_array($row)) {
            return null;
        }
        session()->set(BusinessSession::SEEN, time());

        return $row;
    }

    /**
     * The profile id the session is working on, without checks. Only for
     * questions that grant nothing, such as "is this visitor the owner, so
     * do not count the view".
     */
    public static function sessionListingId(): int
    {
        return BusinessSession::accountId() > 0 ? BusinessSession::listingId() : 0;
    }

    /** @return list<array<string,mixed>> */
    private static function listingsForEmail(string $email): array
    {
        $email = trim($email);
        if ($email === '') {
            return [];
        }

        return (new DirectoryListingModel())->where('email', $email)->orderBy('id', 'ASC')->findAll();
    }
}

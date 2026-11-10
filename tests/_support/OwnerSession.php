<?php

namespace Tests\Support;

use App\Services\Bookings\AccountService;
use App\Services\Bookings\BusinessMemberService;
use App\Services\Bookings\BusinessSession;

/**
 * The session of a profile's owner signed in to /business: an account on the
 * profile's email, an admin membership, and the business chosen. For
 * withSession(); replaces the old /manage session key.
 */
final class OwnerSession
{
    /** @return array<string,int> */
    public static function for(int $listingId): array
    {
        $listing = (new \App\Models\DirectoryListingModel())->withDeleted()->find($listingId);
        $email   = trim((string) ($listing['email'] ?? '')) ?: 'owner' . $listingId . '@example.test';
        $account = (new AccountService())->findOrCreate($email, (string) ($listing['display_name'] ?? 'Owner'));
        (new BusinessMemberService())->ensureAdmin($listingId, (int) $account['id']);

        return [
            BusinessSession::ACCOUNT => (int) $account['id'],
            BusinessSession::LISTING => $listingId,
            BusinessSession::SEEN    => time(),
        ];
    }
}

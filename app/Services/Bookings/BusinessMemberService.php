<?php

namespace App\Services\Bookings;

use App\Models\Bookings\BusinessMemberModel;
use Config\BookingPermissions;

/**
 * Who belongs to which business, and in which role. The only writer of
 * xs_business_members. Invitations and role changes arrive in Phase 2; Phase 1
 * only makes the owner an admin when bookings are turned on.
 */
class BusinessMemberService
{
    /**
     * Every business an account is an active member of, with what the
     * business picker needs. Only profiles with bookings switched on count.
     *
     * @return list<array{member_id:int,listing_id:int,role:string,display_name:string,slug:string}>
     */
    public function businessesFor(int $accountId): array
    {
        $rows = db_connect()->table('xs_business_members m')
            ->select('m.id AS member_id, m.listing_id, m.role, l.display_name, l.slug')
            ->join('xs_directory_listings l', 'l.id = m.listing_id')
            ->where('m.account_id', $accountId)
            ->where('m.status', BusinessMemberModel::STATUS_ACTIVE)
            ->where('l.bookings_enabled', 1)
            ->where('l.deleted_at', null)
            ->orderBy('l.display_name', 'ASC')
            ->get()->getResultArray();

        return array_map(static fn (array $r): array => [
            'member_id'    => (int) $r['member_id'],
            'listing_id'   => (int) $r['listing_id'],
            'role'         => (string) $r['role'],
            'display_name' => (string) $r['display_name'],
            'slug'         => (string) $r['slug'],
        ], $rows);
    }

    /**
     * The account's active membership of one business, or null.
     *
     * @return array<string,mixed>|null
     */
    public function activeMembership(int $accountId, int $listingId): ?array
    {
        $row = BusinessMemberModel::forBusiness($listingId)
            ->where('account_id', $accountId)
            ->where('status', BusinessMemberModel::STATUS_ACTIVE)
            ->first();

        return is_array($row) ? $row : null;
    }

    /**
     * Make an account an admin of a business, or bring a removed or invited
     * membership back as admin. Returns the membership id.
     */
    public function ensureAdmin(int $listingId, int $accountId): int
    {
        $members  = BusinessMemberModel::forBusiness($listingId);
        $existing = $members->where('account_id', $accountId)->first();

        if (is_array($existing)) {
            BusinessMemberModel::forBusiness($listingId)->update((int) $existing['id'], [
                'role'   => BookingPermissions::ADMIN,
                'status' => BusinessMemberModel::STATUS_ACTIVE,
            ]);

            return (int) $existing['id'];
        }

        return (int) BusinessMemberModel::forBusiness($listingId)->insert([
            'account_id' => $accountId,
            'role'       => BookingPermissions::ADMIN,
            'status'     => BusinessMemberModel::STATUS_ACTIVE,
        ]);
    }
}

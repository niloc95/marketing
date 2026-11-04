<?php

namespace App\Services\Bookings;

use Config\BookingPermissions;

/**
 * The one place that answers "may this member do that" inside a business
 * (docs/bookings/permissions.md). Ported from Bookings' AuthorizationService
 * and UserPermissionModel; the provider and staff scope methods join it in
 * Phase 2, when providers exist.
 */
class BookingAuthorization
{
    public function __construct(private ?BookingPermissions $permissions = null)
    {
        $this->permissions ??= config('BookingPermissions');
    }

    /** @param array<string,mixed> $member a row of xs_business_members */
    public function can(array $member, string $permission): bool
    {
        if (($member['status'] ?? '') !== 'active') {
            return false;
        }
        $roles = $this->permissions->matrix[$permission] ?? null;
        if ($roles === null) {
            throw new \InvalidArgumentException('Unknown booking permission ' . $permission);
        }

        return in_array((string) ($member['role'] ?? ''), $roles, true);
    }

    /**
     * Which providers a member's calendar, customers and time off are
     * limited to (Bookings' CalendarController and CustomerAppointmentService
     * rules): null means every provider (admin); a provider gets their own;
     * reception gets the providers assigned to them. Never trust a provider id
     * from the request instead of this.
     *
     * @param array<string,mixed> $member
     *
     * @return list<int>|null
     */
    public function providerScope(array $member): ?array
    {
        $listingId = (int) $member['listing_id'];
        $memberId  = (int) $member['id'];

        return match ($member['role'] ?? '') {
            BookingPermissions::ADMIN => null,
            BookingPermissions::PROVIDER => array_map('intval', array_column(
                \App\Models\Bookings\BookingProviderModel::forBusiness($listingId)->where('member_id', $memberId)->findAll(),
                'id',
            )),
            default => array_map('intval', array_column(
                \App\Models\Bookings\BookingStaffAssignmentModel::forBusiness($listingId)->where('member_id', $memberId)->findAll(),
                'provider_id',
            )),
        };
    }

    /** May this member change this provider (profile, hours, time off)? @param array<string,mixed> $member */
    public function canManageProvider(array $member, int $providerId): bool
    {
        if (! $this->can($member, 'providers.manage')) {
            return false;
        }
        $scope = $this->providerScope($member);

        return $scope === null || in_array($providerId, $scope, true);
    }

    /** @param array<string,mixed> $member */
    public function hasRole(array $member, string ...$roles): bool
    {
        return ($member['status'] ?? '') === 'active' && in_array((string) ($member['role'] ?? ''), $roles, true);
    }
}

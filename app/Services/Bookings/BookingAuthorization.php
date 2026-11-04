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

    /** @param array<string,mixed> $member */
    public function hasRole(array $member, string ...$roles): bool
    {
        return ($member['status'] ?? '') === 'active' && in_array((string) ($member['role'] ?? ''), $roles, true);
    }
}

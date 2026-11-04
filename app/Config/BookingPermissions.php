<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * What each role may do inside one business (docs/bookings/permissions.md).
 * Ported from Bookings' UserPermissionModel::ROLE_PERMISSIONS, but a role is
 * held per business (xs_business_members), never globally.
 *
 * Only BookingAuthorization reads this. Permissions that only apply to "own"
 * or "assigned" rows (a provider's own calendar) are granted here and narrowed
 * by BookingAuthorization's scope methods from Phase 2 on.
 */
class BookingPermissions extends BaseConfig
{
    public const ADMIN    = 'admin';
    public const PROVIDER = 'provider';
    public const STAFF    = 'staff';

    public const ROLES = [self::ADMIN, self::PROVIDER, self::STAFF];

    /** @var array<string,list<string>> */
    public array $matrix = [
        'settings.manage'      => [self::ADMIN],
        'members.manage'       => [self::ADMIN],
        'members.manage_staff' => [self::ADMIN, self::PROVIDER],
        'services.manage'      => [self::ADMIN, self::PROVIDER],
        'providers.manage'     => [self::ADMIN, self::PROVIDER],
        'calendar.view_all'    => [self::ADMIN],
        'calendar.view_own'    => [self::ADMIN, self::PROVIDER],
        'calendar.view_assigned' => [self::ADMIN, self::STAFF],
        'appointments.manage'  => [self::ADMIN, self::PROVIDER, self::STAFF],
        'customers.manage'     => [self::ADMIN, self::PROVIDER, self::STAFF],
        'customers.delete'     => [self::ADMIN],
        'analytics.view'       => [self::ADMIN, self::PROVIDER],
        'payments.manage'      => [self::ADMIN, self::PROVIDER],
        'api_keys.manage'      => [self::ADMIN],
        'audit.view'           => [self::ADMIN],
        'bookings.turn_off'    => [self::ADMIN],
    ];

    /** Labels for the roles, as owners read them (docs/bookings/copy.md). */
    public array $roleLabels = [
        self::ADMIN    => 'Admin',
        self::PROVIDER => 'Team member',
        self::STAFF    => 'Reception',
    ];
}

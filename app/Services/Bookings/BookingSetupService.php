<?php

namespace App\Services\Bookings;

use Config\Bookings;
use Config\Sites;

/**
 * Turning online bookings on and off for a profile. The only writer of
 * xs_directory_listings.bookings_enabled / bookings_enabled_at (query builder:
 * the columns are deliberately not in the model's allowedFields or
 * OWNER_EDITABLE, so no profile form can switch them).
 *
 * Turning on makes the owner an admin of the business, with an account on the
 * profile's email. The owner proved that address through the /manage link
 * before reaching the button, so no further check is asked for; they set a
 * password on the next page.
 */
class BookingSetupService
{
    public function __construct(
        private ?AccountService $accounts = null,
        private ?BusinessMemberService $members = null,
        private ?BookingAudit $audit = null,
        private ?Bookings $config = null,
    ) {
        $this->accounts ??= new AccountService();
        $this->members  ??= new BusinessMemberService();
        $this->audit    ??= new BookingAudit();
        $this->config   ??= config('Bookings');
    }

    /**
     * Why this profile cannot turn bookings on, or null when it can.
     *
     * @param array<string,mixed> $listing
     */
    public function whyNot(array $listing): ?string
    {
        if (! $this->config->enabled()) {
            return 'Online bookings are not available yet.';
        }

        $siteKey = site()->forRow($listing);
        $country = strtoupper(trim((string) ($listing['country_code'] ?? '')));
        if (! $this->config->siteAllowed($siteKey) || ($siteKey === Sites::ZA && $country !== '' && $country !== 'ZA')) {
            return 'Online bookings are not available in your country yet.';
        }

        if (($listing['status'] ?? '') !== 'published' || ! empty($listing['deleted_at'])) {
            return 'Your business profile must be live before you can take bookings.';
        }

        return null;
    }

    /** @param array<string,mixed> $listing */
    public function isOn(array $listing): bool
    {
        return (int) ($listing['bookings_enabled'] ?? 0) === 1 && $this->config->enabled();
    }

    /**
     * Switch bookings on and make the owner an admin.
     *
     * @param array<string,mixed> $listing
     *
     * @return array<string,mixed> the owner's account
     */
    public function turnOn(array $listing): array
    {
        $problem = $this->whyNot($listing);
        if ($problem !== null) {
            throw new \DomainException($problem);
        }

        $listingId = (int) $listing['id'];
        $name      = trim((string) ($listing['contact_person'] ?? '')) ?: (string) $listing['display_name'];
        $account   = $this->accounts->findOrCreate((string) $listing['email'], $name);
        $memberId  = $this->members->ensureAdmin($listingId, (int) $account['id']);
        // Most small businesses are one person: they get a bookable provider
        // named after the business, on the profile's opening hours, at once.
        (new TeamService($listingId, $this->audit))->ensureDefaultProvider($listing, $memberId);

        if ((int) ($listing['bookings_enabled'] ?? 0) !== 1) {
            db_connect()->table('xs_directory_listings')->where('id', $listingId)->update([
                'bookings_enabled'    => 1,
                'bookings_enabled_at' => $listing['bookings_enabled_at'] ?? gmdate('Y-m-d H:i:s'),
            ]);
            $this->audit->record($listingId, (int) $account['id'], 'bookings.turned_on');
        }

        return $account;
    }

    /** Switch bookings off. Nothing is deleted; turning on again restores everything. */
    public function turnOff(int $listingId, ?int $accountId): void
    {
        db_connect()->table('xs_directory_listings')->where('id', $listingId)->update(['bookings_enabled' => 0]);
        $this->audit->record($listingId, $accountId, 'bookings.turned_off');
    }
}

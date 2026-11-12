<?php

namespace App\Services;

use App\Services\Bookings\BookingSetupService;
use App\Services\Bookings\CatalogueService;

/**
 * The profile's section tabs in the owner area (layouts/_profile_tabs.php):
 * Details, Where and hours, Services, Photos and video, Team, Branches.
 *
 * While online bookings are usable, Team (and Services, once the catalogue
 * writes the profile's list) are the bookings pages, /business/team and
 * /business/services. Those pages carry the same tabs, docked, so moving
 * between the profile's parts looks the same wherever a part lives (owner,
 * 12 Nov 2026). Business\Profile redirects to them through moved().
 */
final class ProfileSectionTabs
{
    /**
     * The bookings page that holds this section, or null when the profile
     * form does.
     *
     * @param array<string,mixed> $listing
     */
    public static function moved(string $section, array $listing): ?string
    {
        $setup    = new BookingSetupService();
        $bookable = $setup->isOn($listing) && $setup->whyNot($listing) === null;

        return match (true) {
            $bookable && $section === 'team'                                                  => 'business/team',
            $bookable && $section === 'services' && CatalogueService::ownsProfileServices($listing) => 'business/services',
            default                                                                           => null,
        };
    }

    /**
     * Every section, the current one marked. Inline sections are panes of
     * /business/profile, which directory.js switches between in place; the
     * rest (a bookings page, or a Verified or Plus part on a Free profile)
     * are ordinary links.
     *
     * @param array<string,mixed> $listing
     *
     * @return list<array{key:string,href:string,label:string,active:bool,locked:bool,inline:bool}>
     */
    public static function tabs(array $listing, string $current): array
    {
        $open  = (new TeamMemberService())->canManage($listing);
        $items = [];
        foreach (config('ProfileSections')->sections as $key => $s) {
            $moved   = self::moved($key, $listing);
            $locked  = ! empty($s['premium']) && ! $open;
            $items[] = [
                'key'    => $key,
                'href'   => base_url($moved ?? 'business/profile/' . $key),
                'label'  => $s['title'],
                'active' => $key === $current,
                'locked' => $locked,
                'inline' => $moved === null && ! $locked,
            ];
        }

        return $items;
    }

    /**
     * The tabs for a bookings page that is one of the profile's sections, or
     * none: not the owner (staff manage bookings, not the profile), or this
     * page is not where that section lives.
     *
     * @return list<array{key:string,href:string,label:string,active:bool,locked:bool,inline:bool}>
     */
    public static function forBookingsPage(string $section): array
    {
        $context = service('businessContext');
        if (! $context->isSet() || ! $context->can('profile.edit')) {
            return [];
        }
        $listing = $context->listing();

        return self::moved($section, $listing) !== null ? self::tabs($listing, $section) : [];
    }
}

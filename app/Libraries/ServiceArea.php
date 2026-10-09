<?php

namespace App\Libraries;

use App\Models\DirectoryListingModel;

/**
 * Mobile / service-area businesses: the rules for customer_location,
 * show_address and service_areas, shared by signup, the owner's edit and admin
 * intake so the three write paths cannot disagree.
 *
 * The one principle: the address is a required record, and whether the public
 * sees it is a separate preference. Nothing here touches the address columns.
 * Hiding is only possible for a business that travels to its customers — a
 * business customers visit (or "both") must show where its door is — so
 * columns() forces show_address back to 1 for anything but 'travel'.
 *
 * What "hidden" means at render time is listing_public_view() in
 * directory_ui_helper.php, the one place every public surface reads through.
 */
final class ServiceArea
{
    /**
     * Posted with the section. Absent = leave all three columns alone, the
     * same marker convention as the services and features sections — so a
     * partial owner POST cannot quietly reset someone's hidden address to shown.
     */
    public const MARKER = 'service_area_present';

    /** Letters, digits, spaces and the punctuation a place name actually uses. */
    private const ALLOWED = "/^[\\p{L}\\p{M}\\p{Nd} &'\\x{2019}\\x{2013}.\\-()\\/]+$/u";

    /**
     * The stored text (or a posted value) as a clean list. Accepts one per
     * line, commas or bullets, since that is how people will paste them.
     *
     * @return list<string>
     */
    public static function parse(mixed $raw): array
    {
        if (! is_string($raw)) {
            return [];
        }

        $out  = [];
        $seen = [];
        foreach (preg_split('/[\r\n,;•·]+/u', $raw) ?: [] as $part) {
            $area = trim((string) preg_replace('/\s+/u', ' ', $part));
            $key  = mb_strtolower($area);
            if ($area === '' || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[]      = $area;
        }

        return $out;
    }

    /** Why the posted service areas cannot be saved, or null when they can. */
    public static function problem(mixed $raw): ?string
    {
        $areas = self::parse($raw);

        if (count($areas) > DirectoryListingModel::MAX_SERVICE_AREAS) {
            return sprintf('Please list at most %d service areas.', DirectoryListingModel::MAX_SERVICE_AREAS);
        }
        foreach ($areas as $area) {
            if (mb_strlen($area) > DirectoryListingModel::MAX_SERVICE_AREA_LENGTH) {
                return sprintf('Each service area must be under %d characters.', DirectoryListingModel::MAX_SERVICE_AREA_LENGTH + 1);
            }
            if (preg_match('#https?://|www\.|@#i', $area) === 1) {
                return 'Please list only place names as service areas, no links or email addresses.';
            }
            if (preg_match_all('/\d/u', $area) >= 5) {
                return 'Please leave phone numbers out of your service areas.';
            }
            if (preg_match(self::ALLOWED, $area) !== 1) {
                return 'Please use only letters, numbers and ordinary punctuation in your service areas.';
            }
        }

        return null;
    }

    /**
     * The three columns to write, or [] when the form did not carry the section.
     *
     * $stored is the current row on an edit, [] on a new listing. Assumes
     * problem() has already been checked.
     *
     * @param array<string,mixed> $input
     * @param array<string,mixed> $stored
     *
     * @return array{customer_location?:string,show_address?:int,service_areas?:?string}
     */
    public static function columns(array $input, array $stored = []): array
    {
        if (! array_key_exists(self::MARKER, $input)) {
            return [];
        }

        $location = (string) ($input['customer_location'] ?? '');
        if (! array_key_exists($location, DirectoryListingModel::CUSTOMER_LOCATIONS)) {
            $location = (string) ($stored['customer_location'] ?? 'visit');
        }

        // Only 'travel' may hide the address; see the class docblock.
        $show = $location === 'travel' && empty($input['show_address']) ? 0 : 1;

        $areas = self::parse($input['service_areas'] ?? '');

        return [
            'customer_location' => $location,
            'show_address'      => $show,
            'service_areas'     => $areas === [] ? null : implode("\n", $areas),
        ];
    }
}

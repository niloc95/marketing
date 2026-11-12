<?php

namespace Config;

use App\Libraries\ServiceArea;
use App\Services\ListingFacetService;
use App\Services\ServiceMenuService;
use CodeIgniter\Config\BaseConfig;

/**
 * The owner's profile form, split into the pages of the business area
 * (/business/profile/{section}, owner 10 Nov 2026). One place says which
 * posted fields and which validation errors belong to which page, read by
 * Business\Profile and DirectoryListingMutationService::updateOwnSection().
 *
 * A page posts only its own fields; everything else keeps its stored value.
 * `fields` are the input keys a page may post (anything else is dropped),
 * `errors` the error keys it shows (a nested key such as team.0.name counts
 * under its first part). `premium` pages are the Verified badge's (co.za) or
 * Plus's (.app), locked for a Free profile. `anchors` are the form ids the
 * profile strength panel links to, so a step opens the right page.
 */
class ProfileSections extends BaseConfig
{
    /**
     * @var array<string,array{title:string,fields:list<string>,errors:list<string>,premium?:bool,uploads?:bool,anchors?:list<string>}>
     */
    public array $sections = [
        'details' => [
            'title'  => 'Details',
            'fields' => [
                'title', 'position', 'contact_person', 'type', 'category_id',
                'display_name', 'email_public', 'phone', 'phone_alt', 'whatsapp', 'website',
                'social_facebook', 'social_instagram', 'social_linkedin', 'social_tiktok',
                'description', 'credentials', 'specializations',
                'marketing_present', 'marketing_opt_in',
            ],
            'errors'  => ['email', 'consent'],
            'anchors' => ['field-title', 'field-position', 'field-contact-person', 'field-category', 'field-phone', 'field-whatsapp', 'field-website', 'description', 'field-tags', 'field-socials'],
        ],
        'where' => [
            'title'  => 'Where and hours',
            'fields' => [
                'location_name', 'address_line', 'address_line_2', 'suburb', 'city', 'province', 'region', 'postal_code',
                'latitude', 'longitude', 'geocode_precision',
                'customer_location', 'show_address', 'service_areas', ServiceArea::MARKER,
                'hours', 'by_appointment',
            ],
            'errors'  => ['country'],
            'anchors' => ['field-location-name', 'field-address', 'field-map', 'field-customer-location', 'field-hours'],
        ],
        'services' => [
            'title'  => 'Services',
            'fields' => [
                'services', ServiceMenuService::SERVICES_MARKER,
                'attributes', ServiceMenuService::ATTRIBUTES_MARKER,
                'accepts_card_payments', 'offers_delivery', 'offers_online_booking', 'booking_url',
                'facets', ListingFacetService::FACETS_MARKER,
            ],
            'errors'  => [],
            'anchors' => ['field-services', 'field-features', 'field-facets'],
        ],
        'photos' => [
            'title'   => 'Photos and video',
            'fields'  => ['video_url', 'video_settings', 'video_autoplay', 'video_sound', 'menu_remove', 'brand_name'],
            'errors'  => ['photo', 'logo', 'gallery', 'menu', 'brand'],
            'uploads' => true,
            'anchors' => ['field-photos', 'logo-input', 'gallery-input'],
        ],
        'team' => [
            'title'   => 'Team',
            'fields'  => ['team'],
            'errors'  => [],
            'premium' => true,
            'uploads' => true,
        ],
        'branches' => [
            'title'   => 'Branches',
            'fields'  => ['locations'],
            'errors'  => [],
            'premium' => true,
        ],
    ];

    public function has(string $section): bool
    {
        return isset($this->sections[$section]);
    }

    /** @return list<string> */
    public function fields(string $section): array
    {
        return $this->sections[$section]['fields'] ?? [];
    }

    /**
     * Whether an error key belongs on a section's page.
     */
    public function ownsError(string $section, string $key): bool
    {
        $first = (string) preg_split('/[.\[]/', $key, 2)[0];
        $own   = array_merge($this->fields($section), $this->sections[$section]['errors'] ?? []);

        return in_array($first, $own, true);
    }

    /** The section whose page holds a form anchor, for the profile strength panel. */
    public function sectionForAnchor(string $anchor): string
    {
        foreach ($this->sections as $key => $section) {
            if (in_array($anchor, $section['anchors'] ?? [], true)) {
                return $key;
            }
        }

        return 'details';
    }
}

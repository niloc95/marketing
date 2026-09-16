<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * The tick-box "Features & amenities" a listing can claim.
 *
 * The one place these are defined: the form draws its checkboxes from here, the
 * save path filters submitted keys against here, and the profile resolves stored
 * keys to labels through here. Only keys are stored
 * (xs_directory_listing_attributes), so rewording a label needs no migration and
 * a key removed from this file simply stops rendering.
 *
 * $byGroup is keyed by DirectoryCategoriesSeeder's group names, exactly as they
 * appear in directory_categories.group_name. A group missing here just gets the
 * common set.
 *
 * Keys must be unique across $common and every group — a key is stored without
 * its group, so two groups sharing a key means sharing its label too. When two
 * groups genuinely want the same feature, give both the same key and label.
 *
 * Not here: accepts_card_payments, offers_delivery and offers_online_booking.
 * They are listing columns with logic attached (a booking link implies online
 * booking) and are rendered alongside these by the form and the profile.
 */
class ListingAttributes extends BaseConfig
{
    /** @var array<string,string> offered to every category */
    public array $common = [
        'wheelchair_accessible' => 'Wheelchair accessible',
        'parking'               => 'Parking available',
        'free_wifi'             => 'Free Wi-Fi',
        'kid_friendly'          => 'Kid friendly',
        'pet_friendly'          => 'Pet friendly',
        'walk_ins_welcome'      => 'Walk-ins welcome',
        'appointment_only'      => 'By appointment only',
        'after_hours'           => 'After-hours service',
        'women_owned'           => 'Women-owned',
        'black_owned'           => 'Black-owned',
        'speaks_afrikaans'      => 'Afrikaans spoken',
        'speaks_isizulu'        => 'isiZulu spoken',
        'speaks_isixhosa'       => 'isiXhosa spoken',
    ];

    /** @var array<string,array<string,string>> group_name => [key => label] */
    public array $byGroup = [
        'Health & Medical' => [
            'medical_aid_accepted'   => 'Medical aid accepted',
            'telehealth'             => 'Online / video consultations',
            'house_calls'            => 'House calls',
            'emergency_appointments' => 'Emergency appointments',
            'new_patients_welcome'   => 'Accepting new patients',
            'female_practitioner'    => 'Female practitioner available',
        ],
        'Beauty & Wellness' => [
            'bridal'          => 'Bridal & events',
            'home_visits'     => 'Home visits',
            'vegan_products'  => 'Vegan / cruelty-free products',
            'gift_vouchers'   => 'Gift vouchers',
            'couples_treatments' => 'Couples treatments',
            'mens_grooming'   => "Men's grooming",
        ],
        'Hair' => [
            'kids_cuts'      => "Kids' cuts",
            'mens_grooming'  => "Men's grooming",
            'bridal'         => 'Bridal & events',
            'natural_hair'   => 'Natural hair specialist',
            'braids_weaves'  => 'Braids, weaves & extensions',
            'colour_specialist' => 'Colour specialist',
            'home_visits'    => 'Home visits',
        ],
        'Motoring' => [
            'free_quotes'        => 'Free quotes',
            'mobile_service'     => 'Mobile / on-site service',
            'guarantee_on_work'  => 'Guarantee on work',
            'insurance_approved' => 'Insurance approved',
            'towing'             => 'Towing available',
            'courtesy_car'       => 'Courtesy car',
        ],
        'Legal & Financial' => [
            'free_consultation' => 'Free first consultation',
            'online_meetings'   => 'Online meetings',
            'fixed_fees'        => 'Fixed-fee options',
            'registered_body'   => 'Registered with a professional body',
            'pro_bono'          => 'Pro bono work',
        ],
        'Home & Trades' => [
            'free_quotes'        => 'Free quotes',
            'emergency_callouts' => 'Emergency call-outs',
            'guarantee_on_work'  => 'Guarantee on work',
            'insured'            => 'Insured',
            'coc_certificates'   => 'Certificates of compliance issued',
            'registered_trade'   => 'Registered / accredited tradesperson',
        ],
        'Professional Services' => [
            'free_consultation' => 'Free first consultation',
            'online_meetings'   => 'Online meetings',
            'fixed_fees'        => 'Fixed-fee options',
            'registered_body'   => 'Registered with a professional body',
            'nationwide'        => 'Works nationwide',
        ],
        'Fitness & Sport' => [
            'group_classes'       => 'Group classes',
            'personal_training'   => 'Personal training',
            'beginner_friendly'   => 'Beginner friendly',
            'online_classes'      => 'Online classes',
            'free_trial'          => 'Free trial session',
            'showers_changerooms' => 'Showers & change rooms',
            'kids_classes'        => 'Classes for kids',
        ],
        'Education & Training' => [
            'online_lessons'     => 'Online lessons',
            'one_on_one'         => 'One-on-one lessons',
            'group_lessons'      => 'Group lessons',
            'accredited_courses' => 'Accredited courses',
            'home_visits'        => 'Home visits',
            'exam_prep'          => 'Exam preparation',
        ],
        'Events & Hospitality' => [
            'outdoor_seating'  => 'Outdoor seating',
            'private_functions' => 'Private functions',
            'catering'         => 'Catering',
            'halaal'           => 'Halaal options',
            'vegetarian'       => 'Vegetarian options',
            'licensed'         => 'Licensed to sell alcohol',
        ],
        'Travel & Tourism' => [
            'airport_transfers' => 'Airport transfers',
            'guided_tours'      => 'Guided tours',
            'self_catering'     => 'Self-catering',
            'breakfast_included' => 'Breakfast included',
            'pool'              => 'Swimming pool',
            'backup_power'      => 'Backup power',
        ],
        'Pets & Animals' => [
            'home_visits'     => 'Home visits',
            'emergency_care'  => '24-hour emergency care',
            'boarding'        => 'Boarding',
            'grooming'        => 'Grooming',
            'exotic_animals'  => 'Exotic animals',
        ],
        'Everyday Services' => [
            'free_quotes'    => 'Free quotes',
            'same_day'       => 'Same-day service',
            'collection_dropoff' => 'Collection & drop-off',
            'mobile_service' => 'Mobile / on-site service',
        ],
        'Retail & Other' => [
            'online_shop'       => 'Online shop',
            'click_and_collect' => 'Click & collect',
            'nationwide_shipping' => 'Nationwide shipping',
            'gift_vouchers'     => 'Gift vouchers',
        ],
        'Home Industry & Handmade' => [
            'custom_orders'       => 'Custom orders',
            'nationwide_shipping' => 'Nationwide shipping',
            'markets'             => 'Sells at markets',
            'gift_vouchers'       => 'Gift vouchers',
        ],
    ];

    /**
     * Every feature a listing in this group may claim: the common set followed by
     * the group's own. A null or unknown group gets the common set alone.
     *
     * @return array<string,string>
     */
    public function forGroup(?string $group): array
    {
        return $this->common + ($this->byGroup[(string) $group] ?? []);
    }

    /**
     * Label lookup across every set, for rendering stored keys.
     *
     * @return array<string,string>
     */
    public function allLabels(): array
    {
        $labels = $this->common;
        foreach ($this->byGroup as $set) {
            $labels += $set;
        }

        return $labels;
    }
}

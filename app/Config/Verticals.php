<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * What a category calls things, and which order it says them in.
 *
 * A general practitioner and a spice retailer used to render identical pages:
 * the same "Credentials" heading, the same "Trading hours", the same panel
 * order. This file is what gives each vertical its own voice — a practice has
 * "Qualifications & registrations" and "Consulting hours", a restaurant has a
 * "Menu", a guest house has "Rooms & rates".
 *
 * Deliberately wording and order ONLY. A group's colour and icon belong to
 * category_group_style() in app/Helpers/directory_ui_helper.php and are not
 * restated here: two sources of truth for a group's hue is exactly how they
 * drift apart, which is the same reason that helper keeps its icon and tint in
 * one map rather than two.
 *
 * $byGroup is keyed by DirectoryCategoriesSeeder's group names, exactly as they
 * appear in directory_categories.group_name — the same contract
 * Config\ListingAttributes uses, so the two files can be read side by side.
 * $byCategory is keyed by category slug and overrides its group for the handful
 * of categories whose own vocabulary is worth the entry.
 *
 * A group missing from $byGroup is not an error: forCategory() falls back to
 * $defaults, which is the generic wording every page had before this file
 * existed. That is the same "look like nothing rather than borrow another
 * group's identity" rule category_group_style() applies to its grey fallback.
 *
 * Adding a group means adding it here AND to category_group_style() and
 * category_photo(). VerticalProfileTest fails on a seeded group missing from
 * this file; CategoryPhotoTest covers the photograph.
 */
class Verticals extends BaseConfig
{
    /**
     * The generic wording, and the key set every lookup is guaranteed to return.
     *
     * forCategory() merges over this, so a group or category need only name what
     * it actually changes and no view ever has to branch on a missing key.
     *
     * 'order' is the left-hand column of the profile page. Every panel named
     * here has a matching app/Views/directory/_panel_<key>.php, and show.php
     * renders them by walking this list — so a vertical reorders its page by
     * data rather than by an if-chain in the view. A panel with nothing to show
     * returns early and costs a function call, which is why every order carries
     * all eight keys rather than omitting the ones a vertical cares less about.
     *
     * @var array<string,mixed>
     */
    public array $defaults = [
        'noun'       => 'business',
        'nounPlural' => 'businesses',
        'headings'   => [
            'description' => 'Business description',
            'services'    => 'Services',
            'features'    => 'Features & amenities',
            'credentials' => 'Credentials',
            'venue'       => 'In this complex',
            'tags'        => 'Areas of focus',
            'team'        => 'Our team',
            'locations'   => 'Other branches',
            'hours'       => 'Trading hours',
            'ataglance'   => 'At a glance',
        ],
        'cta'   => 'Get in touch',
        'order' => ['ataglance', 'description', 'services', 'features', 'credentials', 'venue', 'tags', 'team', 'locations'],
    ];

    /**
     * group_name => the parts of $defaults it changes.
     *
     * @var array<string,array<string,mixed>>
     */
    public array $byGroup = [
        // Credentials lead. Someone choosing a doctor is asking "is this person
        // qualified" before anything else, and the panel that answers it used to
        // sit fourth, below a free-text description.
        'Health & Medical' => [
            'noun'       => 'practice',
            'nounPlural' => 'practices',
            'headings'   => [
                'credentials' => 'Qualifications & registrations',
                'services'    => 'Treatments & fees',
                'team'        => 'Practitioners',
                'hours'       => 'Consulting hours',
                'tags'        => 'Special interests',
                'locations'   => 'Other rooms & practices',
            ],
            'cta'   => 'Book an appointment',
            'order' => ['credentials', 'ataglance', 'description', 'services', 'team', 'features', 'venue', 'tags', 'locations'],
        ],
        'Beauty & Wellness' => [
            'noun'       => 'salon',
            'nounPlural' => 'salons',
            'headings'   => [
                'services'  => 'Treatments & prices',
                'team'      => 'Our therapists',
                'tags'      => 'Specialities',
                'locations' => 'Other locations',
            ],
            'cta'   => 'Book a treatment',
            'order' => ['services', 'ataglance', 'description', 'features', 'team', 'tags', 'venue', 'credentials', 'locations'],
        ],
        'Hair' => [
            'noun'       => 'salon',
            'nounPlural' => 'salons',
            'headings'   => [
                'services'  => 'Services & prices',
                'team'      => 'Our stylists',
                'tags'      => 'Specialities',
                'locations' => 'Other locations',
            ],
            'cta'   => 'Book an appointment',
            'order' => ['services', 'ataglance', 'description', 'features', 'team', 'tags', 'venue', 'credentials', 'locations'],
        ],
        'Motoring' => [
            'noun'       => 'workshop',
            'nounPlural' => 'workshops',
            'headings'   => [
                'services'    => 'Work we do & rates',
                'credentials' => 'Approvals & accreditation',
                'tags'        => 'Makes & specialities',
                'locations'   => 'Other workshops',
            ],
            'cta'   => 'Request a quote',
            'order' => ['services', 'ataglance', 'description', 'features', 'credentials', 'tags', 'venue', 'team', 'locations'],
        ],
        'Legal & Financial' => [
            'noun'       => 'firm',
            'nounPlural' => 'firms',
            'headings'   => [
                'credentials' => 'Admissions & accreditation',
                'services'    => 'Practice areas & fees',
                'team'        => 'Our people',
                'hours'       => 'Office hours',
                'tags'        => 'Areas of practice',
                'locations'   => 'Other offices',
            ],
            'cta'   => 'Request a consultation',
            'order' => ['credentials', 'ataglance', 'description', 'services', 'team', 'features', 'venue', 'tags', 'locations'],
        ],
        'Home & Trades' => [
            'noun'       => 'tradesperson',
            'nounPlural' => 'tradespeople',
            'headings'   => [
                'services'    => 'Work we do & rates',
                'credentials' => 'Certifications & registrations',
                'tags'        => 'Specialities',
                'locations'   => 'Other depots',
            ],
            'cta'   => 'Request a quote',
            'order' => ['services', 'ataglance', 'description', 'credentials', 'features', 'tags', 'venue', 'team', 'locations'],
        ],
        'Professional Services' => [
            'noun'       => 'practice',
            'nounPlural' => 'practices',
            'headings'   => [
                'credentials' => 'Qualifications & accreditation',
                'services'    => 'What we do & rates',
                'hours'       => 'Office hours',
                'tags'        => 'Specialities',
                'locations'   => 'Other offices',
            ],
            'cta'   => 'Request a quote',
            'order' => ['ataglance', 'description', 'services', 'credentials', 'team', 'features', 'venue', 'tags', 'locations'],
        ],
        'Fitness & Sport' => [
            'noun'       => 'studio',
            'nounPlural' => 'studios',
            'headings'   => [
                'services'  => 'Classes & rates',
                'team'      => 'Our coaches',
                'hours'     => 'Opening hours',
                'tags'      => 'Disciplines',
                'locations' => 'Other locations',
            ],
            'cta'   => 'Book a session',
            'order' => ['services', 'ataglance', 'description', 'features', 'team', 'tags', 'venue', 'credentials', 'locations'],
        ],
        'Education & Training' => [
            'noun'       => 'provider',
            'nounPlural' => 'providers',
            'headings'   => [
                'services'    => 'Courses & fees',
                'credentials' => 'Accreditation & registrations',
                'team'        => 'Our staff',
                'tags'        => 'Subjects',
                'locations'   => 'Other campuses',
            ],
            'cta'   => 'Enquire about enrolment',
            'order' => ['ataglance', 'description', 'services', 'credentials', 'team', 'features', 'venue', 'tags', 'locations'],
        ],
        'Events & Hospitality' => [
            'noun'       => 'venue',
            'nounPlural' => 'venues',
            'headings'   => [
                'services'  => 'What we offer & prices',
                'hours'     => 'Opening hours',
                'tags'      => 'Specialities',
                'locations' => 'Other locations',
            ],
            'cta'   => 'Check availability',
            'order' => ['services', 'ataglance', 'description', 'features', 'tags', 'venue', 'team', 'credentials', 'locations'],
        ],
        'Travel & Tourism' => [
            'noun'       => 'operator',
            'nounPlural' => 'operators',
            'headings'   => [
                'services'  => 'Rates & packages',
                'hours'     => 'Reception hours',
                'tags'      => 'Specialities',
                'locations' => 'Other properties',
            ],
            'cta'   => 'Check availability',
            'order' => ['ataglance', 'description', 'services', 'features', 'tags', 'venue', 'team', 'credentials', 'locations'],
        ],
        'Pets & Animals' => [
            'noun'       => 'practice',
            'nounPlural' => 'practices',
            'headings'   => [
                'services'    => 'Services & fees',
                'credentials' => 'Qualifications & registrations',
                'hours'       => 'Consulting hours',
                'tags'        => 'Animals we see',
                'locations'   => 'Other branches',
            ],
            'cta'   => 'Book an appointment',
            'order' => ['services', 'ataglance', 'description', 'credentials', 'features', 'tags', 'venue', 'team', 'locations'],
        ],
        'Everyday Services' => [
            'noun'       => 'service',
            'nounPlural' => 'services',
            'headings'   => [
                'services' => 'What we do & prices',
                'hours'    => 'Opening hours',
                'tags'     => 'Specialities',
            ],
            'cta'   => 'Get in touch',
            'order' => ['services', 'ataglance', 'description', 'features', 'tags', 'venue', 'credentials', 'team', 'locations'],
        ],
        'Retail & Other' => [
            'noun'       => 'shop',
            'nounPlural' => 'shops',
            'headings'   => [
                'services'  => 'What we stock',
                'hours'     => 'Opening hours',
                'tags'      => 'Brands & ranges',
                'locations' => 'Other stores',
            ],
            'cta'   => 'Visit the shop',
            'order' => ['ataglance', 'description', 'services', 'features', 'venue', 'tags', 'team', 'credentials', 'locations'],
        ],
        // A maker rather than a shop: most of these trade from home, so "shop"
        // and "opening hours" both overclaim. Tags carry more weight here than
        // anywhere else on the site — it is how someone finds a specific bake.
        'Home Industry & Handmade' => [
            'noun'       => 'maker',
            'nounPlural' => 'makers',
            'headings'   => [
                'services' => 'What we make & prices',
                'hours'    => 'When you can collect',
                'tags'     => 'What we are known for',
            ],
            'cta'   => 'Place an order',
            'order' => ['ataglance', 'description', 'services', 'tags', 'features', 'venue', 'credentials', 'team', 'locations'],
        ],
    ];

    /**
     * category slug => the parts of its group it changes.
     *
     * Deliberately short. The group is the unit of design — fifteen identities
     * cover all 160 categories — and this is only for the categories whose own
     * vocabulary is plainly different from their group's: a restaurant has a
     * menu, not "what we offer & prices".
     *
     * A key here that is not a real seeded slug is a silent no-op, so
     * VerticalProfileTest checks every one of them against the seeder.
     *
     * Several entries exist only to fix a noun. A group's noun is chosen for the
     * group and is wrong for some of its members — Events & Hospitality is
     * "venues", which made an empty /directory/restaurant read "No venues here
     * yet" — so the noun is overridden wherever the group's word does not
     * actually describe the category.
     *
     * @var array<string,array<string,mixed>>
     */
    public array $byCategory = [
        'restaurant' => [
            'noun'       => 'restaurant',
            'nounPlural' => 'restaurants',
            'headings'   => ['services' => 'Menu', 'tags' => 'Cuisine'],
            'cta'        => 'Reserve a table',
        ],
        'coffee-shop' => [
            'noun'       => 'coffee shop',
            'nounPlural' => 'coffee shops',
            'headings'   => ['services' => 'Menu'],
            'cta'        => 'Visit us',
        ],
        'bakery' => [
            'noun'       => 'bakery',
            'nounPlural' => 'bakeries',
            'headings'   => ['services' => 'What we bake'],
            'cta'        => 'Place an order',
        ],
        'caterer' => [
            'noun'       => 'caterer',
            'nounPlural' => 'caterers',
            'headings'   => ['services' => 'Menus & packages'],
            'cta'        => 'Request a quote',
        ],
        'home-baker' => [
            'noun'       => 'baker',
            'nounPlural' => 'bakers',
            'headings'   => ['services' => 'What we bake'],
            'cta'        => 'Place an order',
        ],
        'dentist' => ['cta' => 'Book a check-up'],
        'pharmacy' => [
            'noun'       => 'pharmacy',
            'nounPlural' => 'pharmacies',
            'headings'   => ['hours' => 'Opening hours', 'services' => 'Services offered'],
            'cta'        => 'Contact the pharmacy',
        ],
        // A hospital is not booked by the person reading the page, and it is
        // open when its departments are — neither "Book an appointment" nor
        // "Consulting hours" is true of one.
        'hospital' => [
            'noun'       => 'hospital',
            'nounPlural' => 'hospitals',
            'headings'   => ['hours' => 'Opening hours', 'services' => 'Departments & services'],
            'cta'        => 'Contact the hospital',
        ],
        'veterinarian' => [
            'headings' => ['tags' => 'Animals we see'],
            'cta'      => 'Book an appointment',
        ],
        'attorney' => [
            'headings' => ['services' => 'Practice areas & fees'],
            'cta'      => 'Request a consultation',
        ],
        'gym-fitness-centre' => [
            'noun'       => 'gym',
            'nounPlural' => 'gyms',
            'headings'   => ['services' => 'Memberships & classes'],
            'cta'        => 'Enquire about membership',
        ],
        'hair-salon' => ['cta' => 'Book an appointment'],
        'guest-house-accommodation' => [
            'noun'       => 'guest house',
            'nounPlural' => 'guest houses',
            'headings'   => ['services' => 'Rooms & rates'],
            'cta'        => 'Check availability',
        ],
        'game-lodge-safari' => [
            'noun'       => 'lodge',
            'nounPlural' => 'lodges',
            'headings'   => ['services' => 'Packages & rates'],
            'cta'        => 'Check availability',
        ],
        // The Education & Training group speaks for tutors and short-course
        // providers — "provider", "Courses & fees", "Enquire about enrolment".
        // Every entry below exists because that wording is wrong for a school:
        // a parent is not enrolling on a course, and a preschool does not teach
        // subjects. The group's noun was the worst of it — an empty
        // /directory/primary-school read "No providers here yet".
        'preschool-daycare' => [
            'noun'       => 'preschool',
            'nounPlural' => 'preschools',
            'headings'   => [
                'services' => 'Fees',
                'hours'    => 'Opening hours',
                'team'     => 'Our teachers',
                'tags'     => 'Activities & extra-murals',
            ],
            'cta' => 'Book a visit',
        ],
        'aftercare-holiday-care' => [
            'noun'       => 'centre',
            'nounPlural' => 'centres',
            'headings'   => [
                'services' => 'Fees',
                'hours'    => 'Opening hours',
                'team'     => 'Our staff',
                'tags'     => 'Activities',
            ],
            'cta' => 'Enquire about a place',
        ],
        'primary-school' => [
            'noun'       => 'school',
            'nounPlural' => 'schools',
            'headings'   => [
                'services' => 'Fees',
                'hours'    => 'School hours',
                'team'     => 'Our staff',
                'tags'     => 'Subjects & extra-murals',
            ],
            'cta' => 'Book a school tour',
        ],
        'high-school' => [
            'noun'       => 'school',
            'nounPlural' => 'schools',
            'headings'   => [
                'services' => 'Fees',
                'hours'    => 'School hours',
                'team'     => 'Our staff',
                'tags'     => 'Subjects & extra-murals',
            ],
            'cta' => 'Book a school tour',
        ],
        'combined-school' => [
            'noun'       => 'school',
            'nounPlural' => 'schools',
            'headings'   => [
                'services' => 'Fees',
                'hours'    => 'School hours',
                'team'     => 'Our staff',
                'tags'     => 'Subjects & extra-murals',
            ],
            'cta' => 'Book a school tour',
        ],
        // Credentials lead for both of these, the way they do for a practice:
        // a parent looking for a remedial or special needs place is asking who
        // is qualified to work with their child before anything else.
        'special-needs-school' => [
            'noun'       => 'school',
            'nounPlural' => 'schools',
            'headings'   => [
                'services'    => 'Fees',
                'hours'       => 'School hours',
                'credentials' => 'Accreditation & therapeutic staff',
                'team'        => 'Our staff & therapists',
                'tags'        => 'Support we offer',
            ],
            'cta'   => 'Arrange a visit',
            'order' => ['credentials', 'ataglance', 'description', 'services', 'team', 'features', 'venue', 'tags', 'locations'],
        ],
        'remedial-school' => [
            'noun'       => 'school',
            'nounPlural' => 'schools',
            'headings'   => [
                'services'    => 'Fees',
                'hours'       => 'School hours',
                'credentials' => 'Accreditation & therapeutic staff',
                'team'        => 'Our staff & therapists',
                'tags'        => 'Support we offer',
            ],
            'cta'   => 'Arrange a visit',
            'order' => ['credentials', 'ataglance', 'description', 'services', 'team', 'features', 'venue', 'tags', 'locations'],
        ],
        // No "Other campuses" and no trading hours worth the name: the whole
        // point is that there is nowhere to go and no bell.
        'online-school' => [
            'noun'       => 'school',
            'nounPlural' => 'schools',
            'headings'   => [
                'services'  => 'Fees',
                'hours'     => 'When support is available',
                'team'      => 'Our staff',
                'tags'      => 'Subjects offered',
                'locations' => 'Other offices',
            ],
            'cta' => 'Enquire about enrolment',
        ],
        'homeschooling-support' => [
            'headings' => [
                'services' => 'Packages & fees',
                'tags'     => 'Subjects & curricula supported',
            ],
            'cta' => 'Enquire about support',
        ],
        'tutor' => [
            'noun'       => 'tutor',
            'nounPlural' => 'tutors',
            'headings'   => [
                'services' => 'Subjects & rates',
                'team'     => 'Our tutors',
                'tags'     => 'Subjects',
            ],
            'cta' => 'Enquire about lessons',
        ],
        'university' => [
            'noun'       => 'university',
            'nounPlural' => 'universities',
            'headings'   => [
                'services'  => 'Courses & fees',
                'hours'     => 'Office hours',
                'tags'      => 'Faculties & fields of study',
                'locations' => 'Other campuses',
            ],
            'cta' => 'Enquire about admission',
        ],
        'driving-school' => [
            'noun'       => 'school',
            'nounPlural' => 'schools',
            'headings'   => ['services' => 'Lessons & packages'],
            'cta'        => 'Book a lesson',
        ],
        'hardware-store' => [
            'noun'       => 'store',
            'nounPlural' => 'stores',
            'headings'   => ['services' => 'What we stock'],
            'cta'        => 'Visit the store',
        ],
        // Nothing breezy. A CTA is a tone decision as much as a wording one, and
        // "Get in touch" is the only one of these that does not sound like an
        // offer to someone who has just been bereaved.
        'funeral-services' => [
            'noun'       => 'funeral director',
            'nounPlural' => 'funeral directors',
            'headings'   => ['services' => 'Services & packages', 'hours' => 'When we are available'],
            'cta'        => 'Get in touch',
        ],
    ];

    /**
     * The complete wording for one category: $defaults ← group ← category slug.
     *
     * Always returns every key in $defaults, so callers can index straight into
     * it — the same contract ListingAttributes::forGroup() gives. 'headings' is
     * merged one level deep rather than replaced, so an override names only the
     * headings it changes and inherits the rest.
     *
     * @return array<string,mixed>
     */
    public function forCategory(?string $group, ?string $categorySlug = null): array
    {
        $out = $this->defaults;

        foreach ([$this->byGroup[(string) $group] ?? [], $this->byCategory[(string) $categorySlug] ?? []] as $layer) {
            // Carried across the replace below, which would otherwise drop the
            // headings merged by the previous layer: a category override naming
            // only 'services' would take its group's whole heading set with it.
            $headings = $out['headings'];

            // array_replace, not array_merge: 'order' is a list and merge would
            // append the override to the default rather than replace it, leaving
            // one panel rendered twice and another not at all.
            $out = array_replace($out, $layer);

            $out['headings'] = array_replace($headings, $layer['headings'] ?? []);
        }

        return $out;
    }
}

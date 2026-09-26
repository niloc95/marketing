<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * The structured, filterable facts a listing can state about itself.
 *
 * The fourth per-category map, and it should be read beside the other three:
 * Config\Verticals (wording and panel order), Config\ListingAttributes (the
 * tick-boxes) and category_group_style() (icon and tint). Same contract as the
 * first two — keys are DirectoryCategoriesSeeder's group names and category
 * slugs, exactly as they appear in directory_categories.
 *
 * Why this exists rather than more entries in ListingAttributes: an attribute
 * is a boolean with no value and no type. "Ages 18 months to 6 years", "IEB",
 * "fees from R2 500 a month" cannot be expressed as a tick-box, and they are
 * the first three things a parent filters a school on. A facet carries a type,
 * a closed option list and, for ranges, two numbers that SQL can compare.
 *
 * Two things this adds over ListingAttributes:
 *
 *  1. **A $byCategory tier.** ListingAttributes::forGroup() only ever sees
 *     group_name, which is why a preschool and a driving school — both
 *     "Education & Training" — cannot be offered different fields there. This
 *     resolves $defaults <- group <- category slug, the same way
 *     Config\Verticals::forCategory() does, so the narrow tier is available
 *     where the group is genuinely too coarse.
 *  2. **$sets, named bundles.** Six school categories want one identical set of
 *     facets. Referencing 'use' => ['school'] keeps that in one place; copying
 *     it six times is how the sixth copy ends up one option behind.
 *
 * Two rules that differ from ListingAttributes, both worth stating because a
 * reader arriving from that file will assume otherwise:
 *
 *  - **Option keys need only be unique within their own facet.** A row stores
 *    facet_key AND value, unlike an attribute row which stores a bare key — so
 *    'other' may appear in as many facets as want it. Facet *keys* must still
 *    be unique across every set, because the value is stored against the facet
 *    key alone and nothing records which set it came from.
 *  - **Option keys are URL-visible** (?f[curriculum][]=ieb) and end up in
 *    bookmarks, shared links and whatever Search Console has crawled. Rewording
 *    a label is free and needs no migration; renaming a key silently breaks
 *    every saved link, exactly as renaming a slug does. Treat them as slugs.
 *
 * Ages are stored in MONTHS everywhere, including for a high school. One unit
 * throughout is the only way the two ends of a range cannot drift apart;
 * facet_age_label() is what turns 18 into "18 months" and 72 into "6 years".
 */
class ListingFacets extends BaseConfig
{
    /**
     * Reusable bundles, referenced by a group or category through 'use'.
     *
     * @var array<string,array<string,array<string,mixed>>>
     */
    public array $sets = [
        // Everything from Grade R up. Ordered as a parent asks: does it take my
        // child, what does it teach, in what language, what does it cost.
        'school' => [
            'ages' => [
                'label'  => 'Ages taken',
                'type'   => 'range',
                'unit'   => 'months',
                'min'    => 0,
                'max'    => 252,
                'filter' => true,
                'card'   => true,
                'hint'   => 'The youngest and oldest a learner can be. Grade R is about 5, matric about 18.',
            ],
            'school_phase' => [
                'label'   => 'Grades offered',
                'type'    => 'multi',
                'options' => [
                    'grade-r'      => 'Grade R',
                    'foundation'   => 'Foundation Phase (Gr 1-3)',
                    'intermediate' => 'Intermediate Phase (Gr 4-6)',
                    'senior'       => 'Senior Phase (Gr 7-9)',
                    'fet'          => 'FET Phase (Gr 10-12)',
                ],
                'filter' => true,
                // Not on the card: the labels are long enough to push the age
                // range off a phone-width line, and the category already says
                // it — "High School · Senior Phase (Gr 7-9)" tells a parent
                // nothing they did not know when they clicked High School.
                'card' => false,
            ],
            'curriculum' => [
                'label'   => 'Curriculum',
                'type'    => 'multi',
                'options' => [
                    'caps'       => 'CAPS',
                    'ieb'        => 'IEB',
                    'cambridge'  => 'Cambridge',
                    'ib'         => 'IB',
                    'montessori' => 'Montessori',
                    'waldorf'    => 'Waldorf / Steiner',
                    'other'      => 'Other',
                ],
                'filter' => true,
                'card'   => true,
            ],
            'medium' => [
                'label'   => 'Language of instruction',
                'type'    => 'multi',
                'options' => [
                    'english'   => 'English',
                    'afrikaans' => 'Afrikaans',
                    'isizulu'   => 'isiZulu',
                    'isixhosa'  => 'isiXhosa',
                    'sesotho'   => 'Sesotho',
                    'setswana'  => 'Setswana',
                    'dual'      => 'Dual medium',
                ],
                'filter' => true,
                'card'   => false,
            ],
            'school_ownership' => [
                'label'   => 'School type',
                'type'    => 'one',
                'options' => [
                    'public'      => 'Public (government)',
                    'no-fee'      => 'Public, no-fee',
                    'independent' => 'Independent (private)',
                ],
                'filter' => true,
                'card'   => true,
            ],
            'enrolment_gender' => [
                'label'   => 'Learners',
                'type'    => 'one',
                'options' => [
                    'co-ed' => 'Co-educational',
                    'girls' => 'Girls only',
                    'boys'  => 'Boys only',
                ],
                'filter' => true,
                'card'   => false,
            ],
            // A three-state choice, not a tick-box: "boarding only" and "day
            // only" are different answers and a single checkbox can say neither.
            'boarding' => [
                'label'   => 'Boarding',
                'type'    => 'one',
                'options' => [
                    'day-only'     => 'Day school only',
                    'day-boarding' => 'Day & boarding',
                    'boarding-only' => 'Boarding only',
                ],
                'filter' => true,
                'card'   => false,
            ],
            'fees_from' => [
                'label'  => 'Fees from',
                'type'   => 'range',
                'unit'   => 'rand-month',
                'min'    => 0,
                'max'    => 100000,
                'single' => true,
                'filter' => true,
                'card'   => false,
                'hint'   => 'The lowest monthly fee you charge. Leave empty rather than guess.',
            ],
        ],

        // A preschool has no grades and no curriculum ladder; what a parent asks
        // instead is what the day looks like and from what age.
        'preschool' => [
            'ages' => [
                'label'  => 'Ages taken',
                'type'   => 'range',
                'unit'   => 'months',
                'min'    => 0,
                'max'    => 156,
                'filter' => true,
                'card'   => true,
                'hint'   => 'In months for babies and toddlers — 18 months to 6 years is typical.',
            ],
            'programme' => [
                'label'   => 'What you offer',
                'type'    => 'multi',
                'options' => [
                    'baby-class'   => 'Baby class',
                    'half-day'     => 'Half day',
                    'full-day'     => 'Full day',
                    'aftercare'    => 'Aftercare',
                    'holiday-care' => 'Holiday care',
                    'grade-rr'     => 'Grade RR',
                    'grade-r'      => 'Grade R',
                ],
                'filter' => true,
                'card'   => true,
            ],
            'curriculum' => [
                'label'   => 'Approach',
                'type'    => 'multi',
                'options' => [
                    'caps'       => 'CAPS',
                    'montessori' => 'Montessori',
                    'waldorf'    => 'Waldorf / Steiner',
                    'reggio'     => 'Reggio Emilia',
                    'play-based' => 'Play-based',
                    'other'      => 'Other',
                ],
                'filter' => true,
                'card'   => true,
            ],
            'medium' => [
                'label'   => 'Language',
                'type'    => 'multi',
                'options' => [
                    'english'   => 'English',
                    'afrikaans' => 'Afrikaans',
                    'isizulu'   => 'isiZulu',
                    'isixhosa'  => 'isiXhosa',
                    'sesotho'   => 'Sesotho',
                    'setswana'  => 'Setswana',
                    'dual'      => 'Dual medium',
                ],
                'filter' => true,
                'card'   => false,
            ],
            'fees_from' => [
                'label'  => 'Fees from',
                'type'   => 'range',
                'unit'   => 'rand-month',
                'min'    => 0,
                'max'    => 100000,
                'single' => true,
                'filter' => true,
                'card'   => false,
            ],
        ],

        // Tutors and colleges. Subjects stay as tags — Verticals already heads
        // that panel "Subjects" for this group, and a subject list is genuinely
        // long-tail, which is what tags are for and what a closed option list
        // is not. Lesson mode (online / one-on-one / group / exam prep) stays
        // in Config\ListingAttributes; do not offer it twice.
        'tuition' => [
            'school_phase' => [
                'label'   => 'Levels taught',
                'type'    => 'multi',
                'options' => [
                    'foundation'   => 'Foundation Phase (Gr 1-3)',
                    'intermediate' => 'Intermediate Phase (Gr 4-6)',
                    'senior'       => 'Senior Phase (Gr 7-9)',
                    'fet'          => 'FET Phase (Gr 10-12)',
                    'tertiary'     => 'Tertiary',
                    'adult'        => 'Adult learners',
                ],
                'filter' => true,
                'card'   => true,
            ],
            'curriculum' => [
                'label'   => 'Curriculum',
                'type'    => 'multi',
                'options' => [
                    'caps'      => 'CAPS',
                    'ieb'       => 'IEB',
                    'cambridge' => 'Cambridge',
                    'ib'        => 'IB',
                    'other'     => 'Other',
                ],
                'filter' => true,
                'card'   => false,
            ],
            'medium' => [
                'label'   => 'Language',
                'type'    => 'multi',
                'options' => [
                    'english'   => 'English',
                    'afrikaans' => 'Afrikaans',
                    'isizulu'   => 'isiZulu',
                    'isixhosa'  => 'isiXhosa',
                    'sesotho'   => 'Sesotho',
                    'setswana'  => 'Setswana',
                ],
                'filter' => true,
                'card'   => false,
            ],
            'fees_from' => [
                'label'  => 'Rates from',
                'type'   => 'range',
                'unit'   => 'rand-hour',
                'min'    => 0,
                'max'    => 10000,
                'single' => true,
                'filter' => true,
                'card'   => false,
            ],
        ],

        // Restaurants, coffee shops, pubs. The two questions a hungry visitor
        // filters on before cuisine: can I get it the way I want it, and are
        // they open for the meal I am after. Yelp's "Takeout", "Delivery" and
        // "Breakfast & Brunch" / "Lunch" / "Dinner" menu entries, as filters
        // rather than categories, because a place is all of them at once.
        //
        // 'delivery' overlaps the listing's offers_delivery column, which is a
        // generic "Delivery / mobile service" tick shared with plumbers and
        // cannot be filtered on. This is the food-specific answer.
        'dining' => [
            'service_options' => [
                'label'   => 'How to order',
                'type'    => 'multi',
                'options' => [
                    'dine-in'    => 'Dine-in',
                    'takeaway'   => 'Takeaway',
                    'delivery'   => 'Delivery',
                    'drive-thru' => 'Drive-thru',
                ],
                'filter' => true,
                'card'   => true,
            ],
            'meals_served' => [
                'label'   => 'Meals served',
                'type'    => 'multi',
                'options' => [
                    'breakfast'  => 'Breakfast',
                    'brunch'     => 'Brunch',
                    'lunch'      => 'Lunch',
                    'dinner'     => 'Dinner',
                    'late-night' => 'Late night',
                ],
                'filter' => true,
                'card'   => false,
            ],
        ],
    ];

    /**
     * group_name => a set reference and/or facets of its own.
     *
     * "Education & Training" is not here, deliberately: a preschool, a high
     * school and a driving school want three different sets, so there is
     * nothing true of the whole group. Restaurants & Food is the case this tier
     * was kept for — a pizzeria, a coffee shop and a pub all answer the same
     * two questions.
     *
     * @var array<string,array<string,mixed>>
     */
    public array $byGroup = [
        'Restaurants & Food' => ['use' => ['dining']],
    ];

    /**
     * category slug => the facets that category offers.
     *
     * @var array<string,array<string,mixed>>
     */
    public array $byCategory = [
        'preschool-daycare'      => ['use' => ['preschool']],
        'aftercare-holiday-care' => ['use' => ['preschool']],
        'primary-school'         => ['use' => ['school']],
        'high-school'            => ['use' => ['school']],
        'combined-school'        => ['use' => ['school']],
        'special-needs-school'   => ['use' => ['school']],
        'remedial-school'        => ['use' => ['school']],
        // No boarding and no medium-of-instruction question that means anything
        // when there is no classroom; everything else about it is still a school.
        'online-school' => [
            'use'  => ['school'],
            'drop' => ['boarding'],
        ],
        'homeschooling-support' => ['use' => ['tuition']],
        'tutor'                 => ['use' => ['tuition']],
        'language-school'       => ['use' => ['tuition']],
        'computer-training'     => ['use' => ['tuition']],
        'training-college'      => ['use' => ['tuition']],
        'university'            => ['use' => ['tuition']],
    ];

    /**
     * The complete facet definitions for one category: group then slug, with
     * 'use' expanded and 'drop' applied.
     *
     * Always returns a map of facet key => definition, possibly empty — a
     * category with no facets is the normal case, not an error, so every caller
     * can foreach the result without checking first. That is the same contract
     * ListingAttributes::forGroup() and Verticals::forCategory() give.
     *
     * @return array<string,array<string,mixed>>
     */
    public function forCategory(?string $group, ?string $categorySlug = null): array
    {
        $out = [];

        foreach ([$this->byGroup[(string) $group] ?? [], $this->byCategory[(string) $categorySlug] ?? []] as $layer) {
            foreach ($layer['use'] ?? [] as $setName) {
                // A reference to a set that does not exist would otherwise be a
                // silent empty panel. ListingFacetsConfigTest fails on one.
                $out += $this->sets[$setName] ?? [];
            }

            foreach ($layer['facets'] ?? [] as $key => $definition) {
                $out[$key] = $definition;
            }

            foreach ($layer['drop'] ?? [] as $key) {
                unset($out[$key]);
            }
        }

        return $out;
    }

    /**
     * Just the facets a visitor may filter on, in the order they should render.
     *
     * @return array<string,array<string,mixed>>
     */
    public function filterableFor(?string $group, ?string $categorySlug = null): array
    {
        return array_filter(
            $this->forCategory($group, $categorySlug),
            static fn (array $f): bool => ($f['filter'] ?? false) === true
        );
    }

    /**
     * Definition lookup across every set, for rendering a stored row whose
     * category may since have changed — the counterpart of
     * ListingAttributes::allLabels().
     *
     * Where two sets define the same facet key with different options (ages,
     * curriculum, medium all differ between school and preschool), the first
     * set wins. Callers that know the category must use forCategory(); this is
     * only for resolving a label when that context is gone.
     *
     * @return array<string,array<string,mixed>>
     */
    public function allFacets(): array
    {
        $all = [];
        foreach ($this->sets as $set) {
            $all += $set;
        }

        return $all;
    }
}

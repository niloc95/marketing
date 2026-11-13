<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * Words for the signup category matcher ("What does your business do?",
 * App\Services\Search\CategoryMatchService). Our own lists, no outside
 * service.
 *
 * The matcher already knows every category name, its plural and slug, and
 * Config\Search::$categorySynonyms. This file adds what a business says about
 * itself that a visitor's search must NOT be filtered by: "salon" is a hair,
 * beauty or nail business, and as a synonym it would hide two of them. Here a
 * phrase may point at several categories, best first, and only ever
 * suggests. Phrases you link on /admin/discovery are added on top
 * (xs_directory_category_words), without a release.
 */
class CategoryMatch extends BaseConfig
{
    /**
     * Words that say nothing about which category: "practice", "business",
     * "services". Never matched on their own.
     *
     * @var list<string>
     */
    public array $genericWords = [
        'business', 'businesses', 'company', 'companies', 'service', 'services', 'practice',
        'practise', 'shop', 'store', 'centre', 'center', 'agency', 'firm', 'group', 'pty',
        'ltd', 'cc', 'inc', 'llc', 'professional', 'professionals', 'local', 'small',
        'home', 'mobile', 'own', 'private', 'specialist', 'specialists', 'expert', 'experts',
        'provider', 'providers', 'solutions', 'contractor', 'contractors', 'repairs', 'repair',
        'installation', 'installations', 'installer', 'installers', 'supply', 'supplier',
        'suppliers', 'sales', 'consulting', 'studio', 'clinic', 'office', 'we', 'our', 'my', 'i',
        'do', 'offer', 'provide', 'sell', 'run', 'am', 'is', 'are', 'a', 'an', 'the', 'and', 'for', 'of',
    ];

    /**
     * Phrase => category slugs, best first. Lowercase, plain words; a phrase
     * of several words matches only as a whole.
     *
     * @var array<string,list<string>>
     */
    public array $keywords = [
        // Home & trades
        'solar panels'       => ['solar-renewable-energy'],
        'solar installation' => ['solar-renewable-energy'],
        'inverter'           => ['solar-renewable-energy', 'electrician'],
        'inverters'          => ['solar-renewable-energy', 'electrician'],
        'backup power'       => ['solar-renewable-energy', 'electrician'],
        'renewable'          => ['solar-renewable-energy'],
        'geyser'             => ['plumber', 'solar-renewable-energy'],
        'geysers'            => ['plumber', 'solar-renewable-energy'],
        'electrical contractor' => ['electrician'],
        'wiring'             => ['electrician'],
        'coc'                => ['electrician', 'plumber'],
        'hvac'               => ['air-conditioning-refrigeration'],
        'refrigeration'      => ['air-conditioning-refrigeration'],
        'cctv'               => ['security-alarms'],
        'gate motor'         => ['security-alarms'],
        'gate motors'        => ['security-alarms'],
        'electric fence'     => ['security-alarms', 'electrician'],
        'armed response'     => ['security-alarms'],
        'renovations'        => ['builder', 'handyman'],
        'renovation'         => ['builder', 'handyman'],
        'waterproofing'      => ['roofing', 'builder'],
        'carpentry'          => ['carpenter'],
        'joinery'            => ['carpenter'],
        'kitchens'           => ['carpenter', 'interior-design'],
        'cupboards'          => ['carpenter'],
        'garden'             => ['landscaping-garden-services', 'nursery-garden-centre'],
        'tree felling'       => ['landscaping-garden-services'],
        'fumigation'         => ['pest-control'],
        'carpet cleaning'    => ['cleaning-services'],
        'moving'             => ['removals-storage'],
        'storage'            => ['removals-storage'],
        'handyman'           => ['handyman'],
        // Motoring
        'car repairs'        => ['auto-repair'],
        'workshop'           => ['auto-repair'],
        'panel beating'      => ['panel-beater'],
        'spray painting'     => ['auto-body-paint'],
        'tyre'               => ['tyres-exhaust'],
        'exhaust'            => ['tyres-exhaust'],
        'car valet'          => ['car-wash-valet'],
        'motorbike'          => ['motorcycle-service'],
        // Beauty & wellness
        'salon'              => ['hair-salon', 'beauty-salon', 'nail-bar'],
        'hair'               => ['hair-salon', 'barber', 'braiding-extensions'],
        'wigs'               => ['braiding-extensions', 'hair-salon'],
        'extensions'         => ['braiding-extensions'],
        'lashes'             => ['beauty-salon'],
        'brows'              => ['beauty-salon'],
        'makeup'             => ['beauty-salon'],
        'make up'            => ['beauty-salon'],
        'facials'            => ['skin-aesthetics-clinic', 'beauty-salon'],
        'botox'              => ['skin-aesthetics-clinic'],
        'aesthetics'         => ['skin-aesthetics-clinic'],
        'wellness'           => ['wellness-centre', 'spa'],
        // Health
        'teeth'              => ['dentist'],
        'oral hygiene'       => ['dentist'],
        'eyes'               => ['optometrist', 'ophthalmologist'],
        'pharmacist'         => ['pharmacy'],
        'medical practice'   => ['general-practitioner', 'medical-clinic'],
        'surgery'            => ['general-practitioner', 'general-surgeon'],
        'mental health'      => ['psychologist', 'counsellor'],
        'animal hospital'    => ['veterinarian'],
        // Legal & financial
        'law'                => ['attorney'],
        'law practice'       => ['attorney'],
        'legal practice'     => ['attorney'],
        'advocate'           => ['attorney'],
        'property transfers' => ['conveyancer'],
        'accounts'           => ['accountant', 'bookkeeper'],
        'payroll'            => ['bookkeeper', 'accountant'],
        'sars'               => ['tax-practitioner', 'accountant'],
        'audit'              => ['auditor'],
        'wealth'             => ['financial-adviser'],
        'investments'        => ['financial-adviser'],
        'short term insurance' => ['insurance-broker'],
        'debt review'        => ['debt-counsellor'],
        // Professional services
        'computers'          => ['it-support', 'computer-training'],
        'networking'         => ['it-support'],
        'websites'           => ['web-software-development'],
        'software'           => ['web-software-development'],
        'apps'               => ['web-software-development'],
        'seo'                => ['marketing-agency', 'web-software-development'],
        'social media'       => ['marketing-agency'],
        'advertising'        => ['marketing-agency'],
        'branding'           => ['graphic-designer', 'marketing-agency'],
        'logo design'        => ['graphic-designer'],
        'photos'             => ['photographer'],
        'video'              => ['videographer'],
        'signage'            => ['printing-services'],
        'property'           => ['estate-agent', 'property-valuer'],
        'real estate'        => ['estate-agent'],
        'staffing'           => ['recruitment-agency'],
        'engineering'        => ['engineer'],
        'architecture'       => ['architect'],
        // Food & events
        'food'               => ['restaurant', 'fast-food-takeaway', 'caterer'],
        'restaurant'         => ['restaurant'],
        'bistro'             => ['restaurant'],
        'braai'              => ['steakhouse-grill', 'caterer'],
        'baking'             => ['home-baker', 'bakery', 'cake-artist'],
        'baker'              => ['home-baker', 'bakery'],
        'cake'               => ['cake-artist', 'home-baker', 'bakery'],
        'cupcakes'           => ['cake-artist', 'home-baker'],
        'kota'               => ['fast-food-takeaway'],
        'burgers'            => ['fast-food-takeaway'],
        'chicken'            => ['fast-food-takeaway'],
        'bar'                => ['sports-bar-pub'],
        'events'             => ['event-planner', 'venue-hire'],
        'weddings'           => ['event-planner', 'venue-hire', 'photographer'],
        'wedding'            => ['event-planner', 'venue-hire', 'photographer'],
        'decor'              => ['event-planner', 'home-decor'],
        'sound hire'         => ['dj-entertainment'],
        'entertainment'      => ['dj-entertainment'],
        'venue'              => ['venue-hire'],
        // Education, fitness, pets, travel, everyday
        'school'             => ['primary-school', 'high-school', 'combined-school'],
        'lessons'            => ['tutor'],
        'classes'            => ['tutor', 'training-provider'],
        'training'           => ['training-provider', 'training-college'],
        'courses'            => ['training-provider', 'training-college'],
        'coaching'           => ['sports-coaching', 'business-consultant'],
        'bootcamp'           => ['personal-trainer', 'gym-fitness-centre'],
        'crossfit'           => ['gym-fitness-centre'],
        'dogs'               => ['dog-training', 'pet-grooming', 'pet-boarding-kennels'],
        'parlour'            => ['pet-grooming', 'beauty-salon'],
        'dog wash'           => ['pet-grooming'],
        'cats'               => ['veterinarian', 'pet-boarding-kennels'],
        'pets'               => ['pet-shop', 'pet-grooming', 'veterinarian'],
        'tours'              => ['tour-operator-guide'],
        'travel'             => ['travel-agency'],
        'lodge'              => ['game-lodge-safari', 'guest-house-accommodation'],
        'self catering'      => ['guest-house-accommodation'],
        'transport'          => ['shuttle-airport-transfer', 'courier-delivery'],
        'deliveries'         => ['courier-delivery'],
        'delivery'           => ['courier-delivery'],
        'sewing'             => ['sewing-crochet', 'tailor-alterations'],
        'clothing'           => ['clothing-apparel', 'tailor-alterations'],
        'clothes'            => ['clothing-apparel'],
        'fashion'            => ['clothing-apparel'],
        'shoes'              => ['clothing-apparel'],
        'jewelry'            => ['jewellery'],
        'hardware'           => ['hardware-store'],
        'plants'             => ['nursery-garden-centre'],
        'crafts'             => ['crafts-handmade'],
        'handmade'           => ['crafts-handmade'],
        'jams'               => ['preserves-jams'],
        'vegetables'         => ['farm-produce-farm-stall'],
        'farm'               => ['farm-produce-farm-stall'],
        'burial'             => ['funeral-services'],
        // Faith and community
        'worship'            => ['church', 'place-of-worship'],
        'youth'              => ['children-youth'],
        'pensioners'         => ['elderly-care'],
        'women'              => ['women-family-support'],
        'skills development' => ['skills-job-creation'],
    ];
}

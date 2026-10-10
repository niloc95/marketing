<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * Vocabulary for reading a typed search: "Find a dentist in Sandton that does
 * teeth whitening" becomes category, place and the words left over. See
 * App\Services\Search\RuleBasedInterpreter, which is the only reader.
 *
 * Everything is lowercase and written the way the interpreter tokenises: letters
 * and digits only, "&" and "and" dropped, single spaces. "Gym & Fitness Centre"
 * is therefore "gym fitness centre" here.
 *
 * Category targets are slugs. A slug that no longer exists (renamed or switched
 * off in /admin/categories) is ignored rather than an error, so an edit there
 * can never break search; the entry simply stops doing anything until fixed.
 */
class Search extends BaseConfig
{
    /**
     * Words that carry no meaning for the search once the rest is understood.
     * Dropped only from what is left over, never from inside a phrase match, so
     * "near me" and "it company" still match as phrases.
     *
     * @var list<string>
     */
    public array $fillerWords = [
        'a', 'an', 'the', 'i', 'im', 'me', 'my', 'we', 'our', 'you', 'your',
        'find', 'finding', 'need', 'needs', 'want', 'looking', 'look', 'search',
        'show', 'list', 'get', 'please', 'some', 'any', 'someone', 'somebody',
        'who', 'that', 'which', 'what', 'where', 'can', 'could', 'will', 'help',
        'is', 'are', 'be', 'to', 'of', 'or', 'on', 'at', 'in', 'near', 'around',
        'from', 'by', 'for', 'with', 'does', 'do', 'offers', 'offer', 'offering',
        'best', 'good', 'great', 'reliable', 'trusted', 'top', 'recommended',
        'local', 'nearby', 'business', 'businesses', 'company', 'companies',
        'professional', 'professionals', 'service', 'services', 'provider',
        'providers', 'specialising', 'specializing', 'specialist', 'specialises',
        'specializes', 'works', 'work',
    ];

    /**
     * Phrases after which the rest of the query is what the visitor wants done:
     * "...that does teeth whitening", "...specialising in property law".
     * Longest first is not required; the interpreter tries longer ones first.
     *
     * @var list<string>
     */
    public array $serviceConnectors = [
        'that does', 'that do', 'that offers', 'that offer', 'who does', 'who do',
        'who offers', 'who offer', 'offering', 'offers', 'specialising in',
        'specializing in', 'specialises in', 'specializes in', 'that can do',
        'for', 'with',
    ];

    /** Words that mark the next words as a place: "in Sandton", "near Rosebank". */
    public array $placePrepositions = ['in', 'near', 'around', 'at', 'from', 'outside'];

    /** @var list<string> phrases that mean "use my position" */
    public array $nearMePhrases = ['near me', 'nearby', 'close to me', 'close by', 'around me', 'closest'];

    /**
     * Province aliases => canonical name (DirectoryService::SA_PROVINCES).
     *
     * "gp" is deliberately absent: it is far more often a doctor (see the
     * synonyms) than Gauteng. Two-letter codes only count when typed in
     * capitals, so "ec" or "mp" in a sentence is never read as a province.
     *
     * @var array<string,string>
     */
    public array $provinceAliases = [
        'kzn'          => 'KwaZulu-Natal',
        'natal'        => 'KwaZulu-Natal',
        'kwazulu natal' => 'KwaZulu-Natal',
        'western cape' => 'Western Cape',
        'eastern cape' => 'Eastern Cape',
        'northern cape' => 'Northern Cape',
        'free state'   => 'Free State',
        'north west'   => 'North West',
        'gauteng'      => 'Gauteng',
        'limpopo'      => 'Limpopo',
        'mpumalanga'   => 'Mpumalanga',
    ];

    /** @var array<string,string> two-letter codes, matched only in capitals */
    public array $provinceCodes = [
        'wc' => 'Western Cape', 'ec' => 'Eastern Cape', 'nc' => 'Northern Cape',
        'fs' => 'Free State', 'nw' => 'North West', 'lp' => 'Limpopo',
        'mp' => 'Mpumalanga',
    ];

    /**
     * Informal place names => the name listings use. Applied whether or not any
     * listing is in that place yet: "no dentists in Johannesburg" is a truer
     * answer than ignoring the word.
     *
     * @var array<string,string>
     */
    public array $placeAliases = [
        'joburg'     => 'Johannesburg',
        'jozi'       => 'Johannesburg',
        'jhb'        => 'Johannesburg',
        'joeys'      => 'Johannesburg',
        'johannesburg' => 'Johannesburg',
        'pta'        => 'Pretoria',
        'tshwane'    => 'Pretoria',
        'pretoria'   => 'Pretoria',
        'cpt'        => 'Cape Town',
        'kaapstad'   => 'Cape Town',
        'cape town'  => 'Cape Town',
        'dbn'        => 'Durban',
        'durbs'      => 'Durban',
        'durban'     => 'Durban',
        'pmb'        => 'Pietermaritzburg',
        'pietermaritzburg' => 'Pietermaritzburg',
        'port elizabeth' => 'Gqeberha',
        'gqeberha'   => 'Gqeberha',
        'bloem'      => 'Bloemfontein',
        'bloemfontein' => 'Bloemfontein',
    ];

    /**
     * What people type => category slug. Category names, their plurals and
     * their slugs are matched without being listed here; this is only for the
     * words a category is not called.
     *
     * @var array<string,string>
     */
    public array $categorySynonyms = [
        // Health
        'doctor'            => 'general-practitioner',
        'gp'                => 'general-practitioner',
        'family doctor'     => 'general-practitioner',
        'paediatric'        => 'paediatrician',
        'pediatrician'      => 'paediatrician',
        'kids doctor'       => 'paediatrician',
        'heart doctor'      => 'cardiologist',
        'skin doctor'       => 'dermatologist',
        'gynae'             => 'gynaecologist',
        'gynecologist'      => 'gynaecologist',
        'eye doctor'        => 'ophthalmologist',
        'eye test'          => 'optometrist',
        'glasses'           => 'optometrist',
        'spectacles'        => 'optometrist',
        'dental'            => 'dentist',
        'dentistry'         => 'dentist',
        'braces'            => 'orthodontist',
        'hearing'           => 'audiologist',
        'physio'            => 'physiotherapist',
        'physiotherapy'     => 'physiotherapist',
        'chiro'             => 'chiropractor',
        'biokinetics'       => 'biokineticist',
        'occupational therapy' => 'occupational-therapist',
        'speech therapy'    => 'speech-therapist',
        'dietitian'         => 'dietician',
        'therapist'         => 'psychologist',
        'therapy'           => 'psychologist',
        'counselling'       => 'counsellor',
        'counseling'        => 'counsellor',
        'counselor'         => 'counsellor',
        'chemist'           => 'pharmacy',
        'clinic'            => 'medical-clinic',
        'vet'               => 'veterinarian',
        'vets'              => 'veterinarian',
        'veterinary'        => 'veterinarian',
        // Beauty & wellness
        'hairdresser'       => 'hair-salon',
        'hair dresser'      => 'hair-salon',
        'hairstylist'       => 'hair-salon',
        'hair stylist'      => 'hair-salon',
        'barbershop'        => 'barber',
        'barber shop'       => 'barber',
        'braids'            => 'braiding-extensions',
        'braiding'          => 'braiding-extensions',
        'nails'             => 'nail-bar',
        'manicure'          => 'nail-bar',
        'pedicure'          => 'nail-bar',
        'massage'           => 'massage-therapist',
        'beautician'        => 'beauty-salon',
        'beauty'            => 'beauty-salon',
        'laser'             => 'waxing-laser',
        'waxing'            => 'waxing-laser',
        'tattoo'            => 'tattoo-piercing',
        'piercing'          => 'tattoo-piercing',
        // Motoring
        'mechanic'          => 'auto-repair',
        'car repairs'       => 'auto-repair',
        'car service'       => 'auto-repair',
        'panelbeater'       => 'panel-beater',
        'car wash'          => 'car-wash-valet',
        'tyres'             => 'tyres-exhaust',
        'tow truck'         => 'towing-roadside-assistance',
        'towing'            => 'towing-roadside-assistance',
        'car dealer'        => 'vehicle-dealership',
        // Legal & financial
        'lawyer'            => 'attorney',
        'lawyers'           => 'attorney',
        'law firm'          => 'attorney',
        'legal'             => 'attorney',
        'conveyancing'      => 'conveyancer',
        'accounting'        => 'accountant',
        'bookkeeping'       => 'bookkeeper',
        'tax'               => 'tax-practitioner',
        'financial planner' => 'financial-adviser',
        'financial advisor' => 'financial-adviser',
        'insurance'         => 'insurance-broker',
        // Home & trades
        'plumbing'          => 'plumber',
        'electrical'        => 'electrician',
        'sparky'            => 'electrician',
        'building'          => 'builder',
        'construction'      => 'builder',
        'painting'          => 'painter-decorator',
        'painter'           => 'painter-decorator',
        'locks'             => 'locksmith',
        'roofer'            => 'roofing',
        'tiler'             => 'tiling',
        'gardener'          => 'landscaping-garden-services',
        'garden services'   => 'landscaping-garden-services',
        'landscaper'        => 'landscaping-garden-services',
        'pool'              => 'pool-services',
        'exterminator'      => 'pest-control',
        'cleaner'           => 'cleaning-services',
        'cleaning'          => 'cleaning-services',
        'domestic cleaning' => 'cleaning-services',
        'aircon'            => 'air-conditioning-refrigeration',
        'air con'           => 'air-conditioning-refrigeration',
        'solar'             => 'solar-renewable-energy',
        'alarm'             => 'security-alarms',
        'security'          => 'security-alarms',
        'movers'            => 'removals-storage',
        'removals'          => 'removals-storage',
        'interior designer' => 'interior-design',
        // Professional services
        'it company'        => 'it-support',
        'it services'       => 'it-support',
        'computer repairs'  => 'it-support',
        'computer repair'   => 'it-support',
        'fixes computers'   => 'it-support',
        'managed it'        => 'it-support',
        'web designer'      => 'web-software-development',
        'web design'        => 'web-software-development',
        'website'           => 'web-software-development',
        'software developer' => 'web-software-development',
        'app developer'     => 'web-software-development',
        'marketing'         => 'marketing-agency',
        'graphic design'    => 'graphic-designer',
        'photography'       => 'photographer',
        'videography'       => 'videographer',
        'printing'          => 'printing-services',
        'printer'           => 'printing-services',
        'recruiter'         => 'recruitment-agency',
        'recruitment'       => 'recruitment-agency',
        'consultant'        => 'business-consultant',
        'translator'        => 'translation-services',
        'realtor'           => 'estate-agent',
        'property agent'    => 'estate-agent',
        'surveyor'          => 'land-surveyor',
        'valuer'            => 'property-valuer',
        // Fitness & sport
        'gym'               => 'gym-fitness-centre',
        'fitness'           => 'gym-fitness-centre',
        'trainer'           => 'personal-trainer',
        'yoga'              => 'yoga-studio',
        'pilates'           => 'pilates-studio',
        'reformer pilates'  => 'pilates-studio',
        'karate'            => 'martial-arts',
        'judo'              => 'martial-arts',
        'dance'             => 'dance-studio',
        'dancing'           => 'dance-studio',
        // Education
        'creche'            => 'preschool-daycare',
        'daycare'           => 'preschool-daycare',
        'nursery school'    => 'preschool-daycare',
        'preschool'         => 'preschool-daycare',
        'aftercare'         => 'aftercare-holiday-care',
        'tutoring'          => 'tutor',
        'extra lessons'     => 'tutor',
        'driving lessons'   => 'driving-school',
        'music lessons'     => 'music-teacher',
        'piano lessons'     => 'music-teacher',
        // Events, food, travel, pets, everyday
        'wedding planner'   => 'event-planner',
        'catering'          => 'caterer',
        'flowers'           => 'florist',
        'dj'                => 'dj-entertainment',
        'cafe'              => 'coffee-shop',
        'coffee'            => 'coffee-shop',
        'takeaway'          => 'fast-food-takeaway',
        'takeaways'         => 'fast-food-takeaway',
        'sushi'             => 'sushi-asian',
        'steakhouse'        => 'steakhouse-grill',
        'pub'               => 'sports-bar-pub',
        'guest house'       => 'guest-house-accommodation',
        'guesthouse'        => 'guest-house-accommodation',
        'bnb'               => 'guest-house-accommodation',
        'accommodation'     => 'guest-house-accommodation',
        'safari'            => 'game-lodge-safari',
        'shuttle'           => 'shuttle-airport-transfer',
        'airport transfer'  => 'shuttle-airport-transfer',
        'car hire'          => 'car-rental',
        'grooming'          => 'pet-grooming',
        'dog grooming'      => 'pet-grooming',
        'kennels'           => 'pet-boarding-kennels',
        'dry cleaner'       => 'laundry-dry-cleaning',
        'laundromat'        => 'laundry-dry-cleaning',
        'laundry'           => 'laundry-dry-cleaning',
        'alterations'       => 'tailor-alterations',
        'courier'           => 'courier-delivery',
        'undertaker'        => 'funeral-services',
        'funeral'           => 'funeral-services',
        'sangoma'           => 'traditional-healer',
        'inyanga'           => 'traditional-healer',
        'acupuncture'       => 'acupuncturist',
        'reiki'             => 'reiki-practitioner',
        'reflexology'       => 'reflexologist',
        'homeopathy'        => 'homeopath',
        'cakes'             => 'cake-artist',
        // Sport. The bare sport ("padel", "squash", "tennis", "golf",
        // "cricket") is deliberately NOT a synonym: it would filter to the
        // courts category and hide a club like the Wanderers, filed as Sports
        // Club. Left as keywords, relevance still matches the category name.
        'golf course'       => 'golf-club-driving-range',
        'driving range'     => 'golf-club-driving-range',
        'country club'      => 'sports-club',
        'swimming lessons'  => 'swimming-school',
        // Places & Venues
        'mall'              => 'shopping-centre-mall',
        'shopping mall'     => 'shopping-centre-mall',
        'shopping centre'   => 'shopping-centre-mall',
        'shopping center'   => 'shopping-centre-mall',
        'stadium'           => 'stadium-arena',
        'arena'             => 'stadium-arena',
        'community hall'    => 'community-hall-centre',
        'community centre'  => 'community-hall-centre',
        'community center'  => 'community-hall-centre',
        'flea market'       => 'market-flea-market',
        'market'            => 'market-flea-market',
        'office park'       => 'office-business-park',
        'business park'     => 'office-business-park',
        'theatre'           => 'theatre-arts-venue',
        'theater'           => 'theatre-arts-venue',
        'museum'            => 'museum-gallery',
        'art gallery'       => 'museum-gallery',
        'monument'          => 'heritage-site-landmark',
        'heritage site'     => 'heritage-site-landmark',
        'landmark'          => 'heritage-site-landmark',
        'nature reserve'    => 'park-recreation-area',
        // Faith & Worship. "temple" alone is left out: Hindu and Buddhist
        // temples are separate categories, and a guess would hide one.
        'masjid'            => 'mosque',
        'mandir'            => 'hindu-temple',
        'shul'              => 'synagogue',
        'kerk'              => 'church',
        'chapel'            => 'church',
        'cathedral'         => 'church',
        'gurdwara'          => 'place-of-worship',
        'ministry'          => 'faith-organisation',
        // Community & Nonprofit, by cause. "trust" and "shelter" are left
        // out: a family trust is an attorney's work, and a shelter may be
        // for animals.
        'ngo'               => 'ngo-nonprofit',
        'npo'               => 'ngo-nonprofit',
        'nonprofit'         => 'ngo-nonprofit',
        'non profit'        => 'ngo-nonprofit',
        'charity'           => 'charity-welfare',
        'charities'         => 'charity-welfare',
        'foundation'        => 'foundation-trust',
        'soup kitchen'      => 'feeding-scheme-food-security',
        'feeding scheme'    => 'feeding-scheme-food-security',
        'food bank'         => 'feeding-scheme-food-security',
        'bursary'           => 'education-bursaries',
        'bursaries'         => 'education-bursaries',
        'scholarship'       => 'education-bursaries',
        'orphanage'         => 'children-youth',
        'childrens home'    => 'children-youth',
        'youth programme'   => 'children-youth',
        'old age home'      => 'elderly-care',
        'retirement home'   => 'elderly-care',
        'spca'              => 'animal-welfare',
        'animal rescue'     => 'animal-welfare',
        'homeless shelter'  => 'shelter-housing',
        'hiv'               => 'health-hiv-support',
        'disability'        => 'disability-support',
        'conservation'      => 'environment-conservation',
        'ratepayers'        => 'residents-association',
        'ratepayers association' => 'residents-association',
    ];

    /**
     * Examples for the home search when the site has nothing to build real
     * ones from (an empty database). Real ones come from
     * DirectoryService::searchExamples(), so they always have results.
     *
     * @var list<string>
     */
    public array $fallbackExamples = [
        'Dentist in Sandton',
        'IT support in Johannesburg',
        'Pilates studio in Cape Town',
        'Attorney in Pretoria',
        'Photographer in Durban',
    ];
}

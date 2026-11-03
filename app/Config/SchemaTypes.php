<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * Which schema.org type a profile publishes, by category.
 *
 * schema_business_type() reads these tables in this order, first hit wins:
 *
 *   1. the category's own `schema_type` column, set on /admin/categories and
 *      limited to $allowed, so a new category can be typed without a release;
 *   2. $places, the worship and civic slugs (checked before the nonprofit rule:
 *      a church filed by an NGO is still a Church);
 *   3. the nonprofit rule ($nonprofitTypes, $nonprofitGroup) → NGO;
 *   4. $bySlug;
 *   5. $byGroup, so a category nobody has typed inherits its group's type;
 *   6. LocalBusiness.
 *
 * Every table holds bare type names. A type listed in $pairWithLocalBusiness is
 * not a LocalBusiness subtype in schema.org (the schools, VeterinaryCare, the
 * civic places), so it is published as ["LocalBusiness", Type]: without the
 * LocalBusiness half, address, geo and opening hours have nothing to hang off.
 *
 * Slug keys are slugify() of the names in DirectoryCategoriesSeeder;
 * SchemaTypesTest checks every key is a seeded slug and every value is in
 * $allowed. To add a category, see "Adding a category" in the listing-service
 * skill.
 */
class SchemaTypes extends BaseConfig
{
    /**
     * Places of worship and Places & Venues: schema.org names each exactly.
     *
     * @var array<string,string>
     */
    public array $places = [
        'church'                 => 'Church',
        'mosque'                 => 'Mosque',
        'hindu-temple'           => 'HinduTemple',
        'synagogue'              => 'Synagogue',
        'buddhist-temple'        => 'BuddhistTemple',
        'place-of-worship'       => 'PlaceOfWorship',
        'stadium-arena'          => 'StadiumOrArena',
        'community-hall-centre'  => 'EventVenue',
        'theatre-arts-venue'     => 'PerformingArtsTheater',
        'museum-gallery'         => 'Museum',
        'heritage-site-landmark' => 'LandmarksOrHistoricalBuildings',
        'park-recreation-area'   => 'Park',
        'shopping-centre-mall'   => 'ShoppingCenter',
    ];

    /**
     * Profile types (DirectoryListingModel::TYPES) that are an NGO whatever
     * category they filed under.
     *
     * @var list<string>
     */
    public array $nonprofitTypes = ['ngo', 'community', 'foundation', 'project'];

    public string $nonprofitGroup = 'Community & Nonprofit';

    /** @var array<string,string> */
    public array $bySlug = [
        // Fitness & Sport: the clubs and courts schema.org names exactly.
        'sports-club'              => 'SportsClub',
        'tennis-courts'            => 'TennisComplex',
        'golf-club-driving-range'  => 'GolfCourse',
        'gym-fitness-centre'       => 'ExerciseGym',
        // Health & Medical. Every doctor is a Physician, the specialties the
        // October 2026 import brought in included: published as the group's
        // MedicalBusiness they lost medicalSpecialty and read as a shop.
        'general-practitioner'            => 'Physician',
        'specialist-physician'            => 'Physician',
        'paediatrician'                   => 'Physician',
        'cardiologist'                    => 'Physician',
        'dermatologist'                   => 'Physician',
        'gynaecologist'                   => 'Physician',
        'neurologist'                     => 'Physician',
        'oncologist'                      => 'Physician',
        'urologist'                       => 'Physician',
        'ent-specialist'                  => 'Physician',
        'ophthalmologist'                 => 'Physician',
        'general-surgeon'                 => 'Physician',
        'orthopaedic-surgeon'             => 'Physician',
        'anaesthetist'                    => 'Physician',
        'radiologist'                     => 'Physician',
        'psychiatrist'                    => 'Physician',
        'neurosurgeon'                    => 'Physician',
        'cardiothoracic-surgeon'          => 'Physician',
        'vascular-surgeon'                => 'Physician',
        'plastic-reconstructive-surgeon'  => 'Physician',
        'maxillofacial-oral-surgeon'      => 'Physician',
        'paediatric-surgeon'              => 'Physician',
        'hepatobiliary-surgeon'           => 'Physician',
        'colorectal-surgeon'              => 'Physician',
        'breast-surgeon'                  => 'Physician',
        'spinal-surgeon'                  => 'Physician',
        'trauma-surgeon'                  => 'Physician',
        'gastroenterologist'              => 'Physician',
        'pulmonologist'                   => 'Physician',
        'endocrinologist'                 => 'Physician',
        'nephrologist'                    => 'Physician',
        'rheumatologist'                  => 'Physician',
        'haematologist'                   => 'Physician',
        'infectious-diseases-specialist'  => 'Physician',
        'radiation-oncologist'            => 'Physician',
        'gynaecological-oncologist'       => 'Physician',
        'fertility-specialist'            => 'Physician',
        'neonatologist'                   => 'Physician',
        'paediatric-neurologist'          => 'Physician',
        'geriatrician'                    => 'Physician',
        'intensivist'                     => 'Physician',
        'emergency-medicine-doctor'       => 'Physician',
        'nuclear-medicine-physician'      => 'Physician',
        'pain-specialist'                 => 'Physician',
        'pathologist'                     => 'Physician',
        'dentist'                => 'Dentist',
        'orthodontist'           => 'Dentist',
        'optometrist'            => 'Optician',
        'physiotherapist'        => 'Physiotherapy',
        'pharmacy'               => 'Pharmacy',
        'medical-clinic'         => 'MedicalClinic',
        'hospital'               => 'Hospital',
        'pathology-laboratory'   => 'DiagnosticLab',
        // Beauty & Wellness (Hair folded in; its rows keep HairSalon)
        'hair-salon'             => 'HairSalon',
        'braiding-extensions'    => 'HairSalon',
        'nail-bar'               => 'NailSalon',
        'beauty-salon'           => 'BeautySalon',
        'spa'                    => 'DaySpa',
        'tattoo-piercing'        => 'TattooParlor',
        'barber'                 => 'HairSalon',
        // Motoring
        'auto-repair'            => 'AutoRepair',
        'auto-electrician'       => 'AutoRepair',
        'motorcycle-service'     => 'MotorcycleRepair',
        'panel-beater'           => 'AutoBodyShop',
        'auto-body-paint'        => 'AutoBodyShop',
        'car-wash-valet'         => 'AutoWash',
        'tyres-exhaust'          => 'TireShop',
        'vehicle-dealership'     => 'AutoDealer',
        // Legal & Financial
        'attorney'               => 'Attorney',
        'notary'                 => 'Notary',
        'conveyancer'            => 'LegalService',
        'accountant'             => 'AccountingService',
        'bookkeeper'             => 'AccountingService',
        'tax-practitioner'       => 'AccountingService',
        'auditor'                => 'AccountingService',
        'financial-adviser'      => 'FinancialService',
        'debt-counsellor'        => 'FinancialService',
        'insurance-broker'       => 'InsuranceAgency',
        // Home & Trades
        'plumber'                => 'Plumber',
        'electrician'            => 'Electrician',
        'builder'                => 'GeneralContractor',
        'painter-decorator'      => 'HousePainter',
        'roofing'                => 'RoofingContractor',
        'locksmith'              => 'Locksmith',
        'air-conditioning-refrigeration' => 'HVACBusiness',
        'removals-storage'       => 'MovingCompany',
        // Professional Services
        'estate-agent'           => 'RealEstateAgent',
        'recruitment-agency'     => 'EmploymentAgency',
        // Education & Training
        'preschool-daycare'      => 'Preschool',
        'aftercare-holiday-care' => 'Preschool',
        'primary-school'         => 'ElementarySchool',
        'high-school'            => 'HighSchool',
        'combined-school'        => 'School',
        'special-needs-school'   => 'School',
        'remedial-school'        => 'School',
        'online-school'          => 'School',
        'training-college'       => 'CollegeOrUniversity',
        'university'             => 'CollegeOrUniversity',
        // Events & Hospitality
        'florist'                => 'Florist',
        // Restaurants & Food. All FoodEstablishment subtypes, so no pair. A
        // cuisine is a Restaurant; the cuisine itself would be servesCuisine.
        'restaurant'             => 'Restaurant',
        'coffee-shop'            => 'CafeOrCoffeeShop',
        'bakery'                 => 'Bakery',
        'pizza'                  => 'Restaurant',
        'italian-restaurant'     => 'Restaurant',
        'chinese-restaurant'     => 'Restaurant',
        'mexican-restaurant'     => 'Restaurant',
        'indian-restaurant'      => 'Restaurant',
        'sushi-asian'            => 'Restaurant',
        'steakhouse-grill'       => 'Restaurant',
        'fast-food-takeaway'     => 'FastFoodRestaurant',
        'sports-bar-pub'         => 'BarOrPub',
        // Travel & Tourism
        'guest-house-accommodation' => 'LodgingBusiness',
        'game-lodge-safari'      => 'LodgingBusiness',
        'travel-agency'          => 'TravelAgency',
        'car-rental'             => 'AutoRental',
        // Pets & Animals
        'veterinarian'           => 'VeterinaryCare',
        'pet-shop'               => 'PetStore',
        // Everyday Services
        'laundry-dry-cleaning'   => 'DryCleaningOrLaundry',
        // Retail & Other
        'clothing-apparel'       => 'ClothingStore',
        'jewellery'              => 'JewelryStore',
        'furniture'              => 'FurnitureStore',
        'hardware-store'         => 'HardwareStore',
        'nursery-garden-centre'  => 'GardenStore',
    ];

    /**
     * The group's type, for every category without one of its own.
     *
     * Deliberately absent: Travel & Tourism, Everyday Services, Events &
     * Hospitality, Pets & Animals and Home Industry & Handmade. Each holds
     * categories whose types diverge (a game lodge is a LodgingBusiness, the
     * travel agent selling the stay a TravelAgency), and LocalBusiness is true
     * of all of them where a wrong subtype would not be.
     *
     * @var array<string,string>
     */
    public array $byGroup = [
        'Health & Medical'      => 'MedicalBusiness',
        'Beauty & Wellness'     => 'HealthAndBeautyBusiness',
        'Hair'                  => 'HairSalon',
        'Motoring'              => 'AutomotiveBusiness',
        'Legal & Financial'     => 'ProfessionalService',
        'Home & Trades'         => 'HomeAndConstructionBusiness',
        'Professional Services' => 'ProfessionalService',
        'Fitness & Sport'       => 'SportsActivityLocation',
        'Education & Training'  => 'EducationalOrganization',
        'Restaurants & Food'    => 'FoodEstablishment',
        'Retail & Other'        => 'Store',
    ];

    /**
     * Types published as ["LocalBusiness", Type]; see the class docblock.
     * StadiumOrArena is a LocalBusiness already but has always been paired,
     * and changing that would gain nothing.
     *
     * @var list<string>
     */
    public array $pairWithLocalBusiness = [
        'Church', 'Mosque', 'HinduTemple', 'Synagogue', 'BuddhistTemple', 'PlaceOfWorship',
        'StadiumOrArena', 'EventVenue', 'PerformingArtsTheater', 'Museum',
        'LandmarksOrHistoricalBuildings', 'Park',
        'NGO',
        'EducationalOrganization', 'Preschool', 'ElementarySchool', 'HighSchool', 'School',
        'CollegeOrUniversity',
        'VeterinaryCare', 'DiagnosticLab',
    ];

    /**
     * Extra types an admin may choose for a new category beyond those the
     * tables above already use. $allowed() is the union.
     *
     * @var list<string>
     */
    public array $extraAllowed = [
        'LocalBusiness', 'ChildCare', 'EntertainmentBusiness', 'SelfStorage', 'Library',
        'AnimalShelter', 'RecyclingCenter', 'TouristInformationCenter', 'Winery', 'Brewery',
        'IceCreamShop', 'BookStore', 'ElectronicsStore', 'GroceryStore', 'ConvenienceStore',
        'MobilePhoneStore', 'SportingGoodsStore', 'ToyStore', 'AutoPartsStore', 'BikeStore',
        'ComputerStore', 'HomeGoodsStore', 'OfficeEquipmentStore', 'LiquorStore',
        'MedicalClinic', 'Optician', 'LegalService', 'InternetCafe', 'NightClub', 'Hotel',
        'Campground', 'BedAndBreakfast', 'Hostel',
    ];

    /**
     * schema.org MedicalSpecialty members per doctor slug. Published only on a
     * node whose type takes the property ($medicalSpecialtyTypes). A slug with
     * no fitting member (specialist physician, intensivist) has none rather
     * than a near miss.
     *
     * @var array<string,list<string>>
     */
    public array $medicalSpecialty = [
        'general-practitioner'           => ['PrimaryCare'],
        'paediatrician'                  => ['Pediatric'],
        'cardiologist'                   => ['Cardiovascular'],
        'dermatologist'                  => ['Dermatology'],
        'gynaecologist'                  => ['Gynecologic', 'Obstetric'],
        'neurologist'                    => ['Neurologic'],
        'oncologist'                     => ['Oncologic'],
        'urologist'                      => ['Urologic'],
        'ent-specialist'                 => ['Otolaryngologic'],
        'ophthalmologist'                => ['Ophthalmology'],
        'general-surgeon'                => ['Surgical'],
        'orthopaedic-surgeon'            => ['Musculoskeletal', 'Surgical'],
        'anaesthetist'                   => ['Anesthesia'],
        'radiologist'                    => ['Radiography'],
        'psychiatrist'                   => ['Psychiatric'],
        'neurosurgeon'                   => ['Neurologic', 'Surgical'],
        'cardiothoracic-surgeon'         => ['Cardiovascular', 'Surgical'],
        'vascular-surgeon'               => ['Cardiovascular', 'Surgical'],
        'plastic-reconstructive-surgeon' => ['PlasticSurgery'],
        'maxillofacial-oral-surgeon'     => ['Dentistry', 'Surgical'],
        'paediatric-surgeon'             => ['Pediatric', 'Surgical'],
        'hepatobiliary-surgeon'          => ['Gastroenterologic', 'Surgical'],
        'colorectal-surgeon'             => ['Gastroenterologic', 'Surgical'],
        'breast-surgeon'                 => ['Surgical'],
        'spinal-surgeon'                 => ['Musculoskeletal', 'Surgical'],
        'trauma-surgeon'                 => ['Emergency', 'Surgical'],
        'gastroenterologist'             => ['Gastroenterologic'],
        'pulmonologist'                  => ['Pulmonary'],
        'endocrinologist'                => ['Endocrine'],
        'nephrologist'                   => ['Renal'],
        'rheumatologist'                 => ['Rheumatologic'],
        'haematologist'                  => ['Hematologic'],
        'infectious-diseases-specialist' => ['Infectious'],
        'radiation-oncologist'           => ['Oncologic', 'Radiography'],
        'gynaecological-oncologist'      => ['Gynecologic', 'Oncologic'],
        'fertility-specialist'           => ['Gynecologic'],
        'neonatologist'                  => ['Pediatric'],
        'paediatric-neurologist'         => ['Pediatric', 'Neurologic'],
        'geriatrician'                   => ['Geriatric'],
        'emergency-medicine-doctor'      => ['Emergency'],
        'nuclear-medicine-physician'     => ['Radiography'],
        'pain-specialist'                => ['Anesthesia'],
        'pathologist'                    => ['Pathology'],
        'dentist'                        => ['Dentistry'],
        'orthodontist'                   => ['Dentistry'],
        'pathology-laboratory'           => ['LaboratoryScience'],
    ];

    /**
     * Types that take medicalSpecialty (its domain is Hospital, MedicalClinic,
     * MedicalOrganization and Physician; Dentist and DiagnosticLab are
     * MedicalOrganizations).
     *
     * @var list<string>
     */
    public array $medicalSpecialtyTypes = ['Physician', 'Dentist', 'MedicalClinic', 'Hospital', 'DiagnosticLab'];

    /**
     * Every type a category may be given on /admin/categories, sorted.
     *
     * @return list<string>
     */
    public function allowed(): array
    {
        $all = array_unique(array_merge(
            array_values($this->places),
            array_values($this->bySlug),
            array_values($this->byGroup),
            $this->pairWithLocalBusiness,
            $this->extraAllowed
        ));
        sort($all);

        return $all;
    }

    /**
     * A bare type as published: the type, or the pair for the types that are
     * not LocalBusiness subtypes.
     *
     * @return string|list<string>
     */
    public function publish(string $type)
    {
        return in_array($type, $this->pairWithLocalBusiness, true) ? ['LocalBusiness', $type] : $type;
    }
}

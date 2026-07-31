<?php

namespace App\Database\Seeds;

use CodeIgniter\Database\Seeder;

/**
 * Service-business taxonomy for the directory. Grouped via the existing
 * `group_name` column so the browse filter can render optgroups.
 *
 * Idempotent — re-running only inserts slugs that are missing, so this is safe
 * to run against a database that already holds listings.
 */
class DirectoryCategoriesSeeder extends Seeder
{
    public function run(): void
    {
        helper('slug');

        $groups = [
            'Health & Medical' => [
                'General Practitioner', 'Dentist', 'Optometrist', 'Physiotherapist',
                'Chiropractor', 'Dietician', 'Psychologist', 'Psychiatrist',
                'Occupational Therapist', 'Speech Therapist', 'Podiatrist',
                'Audiologist', 'Biokineticist', 'Specialist Physician',
                'Pharmacy', 'Medical Clinic', 'Hospital', 'Pathology Laboratory',
                'Radiology Practice',
            ],
            'Beauty & Wellness' => [
                'Spa', 'Nail Bar', 'Beauty Salon', 'Massage Therapist',
                'Skin & Aesthetics Clinic', 'Waxing & Laser', 'Tattoo & Piercing',
                'Wellness Centre',
            ],
            'Hair' => [
                'Hair Salon', 'Barber', 'Braiding & Extensions', 'Hair Removal',
            ],
            'Motoring' => [
                'Auto Repair', 'Auto Electrician', 'Panel Beater', 'Car Wash & Valet',
                'Tyres & Exhaust', 'Auto Body & Paint', 'Towing & Roadside Assistance',
                'Vehicle Dealership', 'Motorcycle Service',
            ],
            'Legal & Financial' => [
                'Attorney', 'Conveyancer', 'Notary', 'Accountant', 'Bookkeeper',
                'Tax Practitioner', 'Auditor', 'Financial Adviser',
                'Insurance Broker', 'Debt Counsellor',
            ],
            'Home & Trades' => [
                'Plumber', 'Electrician', 'Builder', 'Painter & Decorator',
                'Carpenter', 'Locksmith', 'Roofing', 'Tiling', 'Paving',
                'Landscaping & Garden Services', 'Pool Services', 'Pest Control',
                'Cleaning Services', 'Appliance Repair', 'Handyman',
                'Air Conditioning & Refrigeration', 'Solar & Renewable Energy',
                'Security & Alarms', 'Removals & Storage', 'Interior Design',
            ],
            'Professional Services' => [
                'Architect', 'Engineer', 'Land Surveyor', 'Property Valuer',
                'Estate Agent', 'IT Support', 'Web & Software Development',
                'Marketing Agency', 'Graphic Designer', 'Photographer',
                'Videographer', 'Printing Services', 'Recruitment Agency',
                'Business Consultant', 'Translation Services',
            ],
            'Fitness & Sport' => [
                'Gym & Fitness Centre', 'Personal Trainer', 'Yoga Studio',
                'Pilates Studio', 'Martial Arts', 'Dance Studio', 'Sports Coaching',
            ],
            'Education & Training' => [
                'Tutor', 'Driving School', 'Language School', 'Music Teacher',
                'Preschool & Daycare', 'Training Provider', 'Computer Training',
            ],
            'Events & Hospitality' => [
                'Event Planner', 'Caterer', 'Venue Hire', 'Florist',
                'DJ & Entertainment', 'Restaurant', 'Coffee Shop',
                'Guest House & Accommodation', 'Bakery',
            ],
            'Pets & Animals' => [
                'Veterinarian', 'Pet Grooming', 'Pet Boarding & Kennels',
                'Dog Training', 'Pet Shop',
            ],
            'Retail & Other' => [
                'Clothing & Apparel', 'Jewellery', 'Furniture', 'Hardware Store',
                'Nursery & Garden Centre', 'Courier & Delivery', 'Laundry & Dry Cleaning',
                'Tailor & Alterations', 'Funeral Services',
            ],
        ];

        $now  = date('Y-m-d H:i:s');
        $rows = [];
        foreach ($groups as $group => $names) {
            $sort = 0;
            foreach ($names as $name) {
                $rows[] = [
                    'name'       => $name,
                    'slug'       => slugify($name),
                    'group_name' => $group,
                    'is_active'  => 1,
                    'sort_order' => $sort++,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        $db    = $this->db;
        $table = 'xs_directory_categories';
        $have  = array_column($db->table($table)->select('slug')->get()->getResultArray(), 'slug');

        // Insert what's missing.
        $insert = array_values(array_filter($rows, static fn ($r) => ! in_array($r['slug'], $have, true)));
        if ($insert !== []) {
            $db->table($table)->insertBatch($insert);
        }

        // Re-group rows that already existed. The directory launched with a
        // healthcare-only taxonomy, so slugs like "dentist" are already present
        // but carry an old group_name. Updating in place keeps their ids — and
        // therefore any listing's category_id foreign key — intact.
        foreach ($rows as $r) {
            if (in_array($r['slug'], $have, true)) {
                $db->table($table)->where('slug', $r['slug'])->update([
                    'group_name' => $r['group_name'],
                    'sort_order' => $r['sort_order'],
                    'updated_at' => $now,
                ]);
            }
        }

        // Fold the remaining legacy healthcare groups into the new group set so
        // the browse filter doesn't render orphaned optgroups.
        $legacyGroups = [
            'Medical Practitioners' => 'Health & Medical',
            'Mental Health'         => 'Health & Medical',
            'Dental'                => 'Health & Medical',
            'Allied Health'         => 'Health & Medical',
            'Nursing & Pharmacy'    => 'Health & Medical',
            'Facilities'            => 'Health & Medical',
            'Wellness'              => 'Beauty & Wellness',
        ];
        foreach ($legacyGroups as $from => $to) {
            $db->table($table)->where('group_name', $from)->update([
                'group_name' => $to,
                'updated_at' => $now,
            ]);
        }
    }
}

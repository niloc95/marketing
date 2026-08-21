<?php

namespace App\Database\Seeds;

use CodeIgniter\Database\Seeder;

/**
 * Taxonomy of services, professionals and home industry. Grouped via the
 * existing `group_name` column so the browse filter can render optgroups.
 *
 * Idempotent — re-running only inserts slugs that are missing, so this is safe
 * to run against a database that already holds listings. It is also how a
 * taxonomy change reaches an existing site: moving a name between the groups
 * below and re-running the seeder re-files it in place, keeping the row's id and
 * slug, and therefore every listing on it and every link to it.
 */
class DirectoryCategoriesSeeder extends Seeder
{
    public function run(): void
    {
        helper('slug');

        $groups = [
            // Enumerated in full, legacy rows included. Everything the launch
            // taxonomy created — the specialists, the nursing and pharmacy rows —
            // used to sit outside this list, which meant nothing set their
            // sort_order and the group rendered as two interleaved sequences both
            // counting from zero. Listing them here is what makes the order in the
            // picker deterministic; the update pass below leaves their ids alone.
            'Health & Medical' => [
                'General Practitioner', 'Specialist Physician', 'Paediatrician',
                'Cardiologist', 'Dermatologist', 'Gynaecologist', 'Neurologist',
                'Oncologist', 'Urologist', 'ENT Specialist', 'Ophthalmologist',
                'General Surgeon', 'Orthopaedic Surgeon', 'Anaesthetist', 'Radiologist',
                'Dentist', 'Orthodontist', 'Oral Hygienist',
                'Optometrist', 'Audiologist',
                'Physiotherapist', 'Chiropractor', 'Biokineticist',
                'Occupational Therapist', 'Speech Therapist', 'Podiatrist',
                // Dietician and Nutritionist are neighbours here rather than one in
                // Beauty & Wellness, where the legacy "Wellness" group left it —
                // Nutritionist carries more listings than any other category on the
                // site, and it was the only clinical row filed under beauty.
                'Dietician', 'Nutritionist', 'Homeopath',
                'Psychologist', 'Psychiatrist', 'Counsellor', 'Social Worker',
                'Nurse', 'Midwife', 'Pharmacist',
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
            // Ordered as the school ladder, then everything taught outside it.
            // "Training College" is the TVET / private college a school leaver
            // enrols at; "Training Provider" is the accredited outfit that runs
            // short courses for people already working. Different searches, and
            // the directory launched with only the second one.
            'Education & Training' => [
                'Preschool & Daycare', 'Primary School', 'High School',
                'Training College', 'University',
                'Tutor', 'Driving School', 'Language School', 'Music Teacher',
                'Computer Training', 'Training Provider',
            ],
            'Events & Hospitality' => [
                'Event Planner', 'Caterer', 'Venue Hire', 'Florist',
                'DJ & Entertainment', 'Restaurant', 'Coffee Shop', 'Bakery',
            ],
            // Somewhere to sleep is what a visitor searches for, not a party
            // supplier — so Guest House & Accommodation moves out of Events and
            // leads here. Its slug is untouched, so /directory/guest-house-
            // accommodation and every saved filter link still resolve.
            'Travel & Tourism' => [
                'Guest House & Accommodation', 'Game Lodge & Safari', 'Travel Agency',
                'Tour Operator & Guide', 'Car Rental', 'Shuttle & Airport Transfer',
            ],
            'Pets & Animals' => [
                'Veterinarian', 'Pet Grooming', 'Pet Boarding & Kennels',
                'Dog Training', 'Pet Shop',
            ],
            // Services you buy over a counter but do not walk out holding. All
            // four sat in Retail & Other, which typed them as schema.org/Store on
            // every profile they appear on — a dry cleaner is not a shop, and the
            // group had become the place things went when nothing else fitted.
            'Everyday Services' => [
                'Laundry & Dry Cleaning', 'Tailor & Alterations', 'Courier & Delivery',
                'Funeral Services',
            ],
            // What is left is genuinely retail: a counter, stock, a till. It stays
            // the catch-all for anything future that has no better home.
            'Retail & Other' => [
                'Clothing & Apparel', 'Jewellery', 'Furniture', 'Hardware Store',
                'Nursery & Garden Centre',
            ],
            // Home industry — people trading from home rather than premises. A
            // deliberate positioning bet, not an afterthought: it is the part of
            // the local economy the big search engines index worst.
            'Home Industry & Handmade' => [
                'Home Baker', 'Cake Artist', 'Preserves & Jams', 'Crafts & Handmade',
                'Sewing & Crochet', 'Farm Produce & Farm Stall', 'Home Decor',
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

        // Pairs the launch taxonomy left behind, where the list above already
        // covers the same thing under a clearer name. Both being active offers
        // the visitor the same choice twice in one optgroup.
        //
        // Deactivated, never deleted, and only while nothing points at the row:
        // a category with listings on it keeps working exactly as it did, and
        // whoever is holding the reins can switch any of these back on from
        // /admin/categories. Rows already switched off stay off — the update is
        // a no-op for them.
        $superseded = [
            'physician'       => 'specialist-physician',
            'clinic'          => 'medical-clinic',
            'pharmacy-retail' => 'pharmacy',
        ];
        foreach (array_keys($superseded) as $slug) {
            $row = $db->table($table)->select('id')->where('slug', $slug)->get()->getRowArray();
            if ($row === null) {
                continue;
            }
            $inUse = $db->table('xs_directory_listings')
                ->where('category_id', (int) $row['id'])
                ->countAllResults();
            if ($inUse === 0) {
                $db->table($table)->where('id', (int) $row['id'])->update([
                    'is_active'  => 0,
                    'updated_at' => $now,
                ]);
            }
        }
    }
}

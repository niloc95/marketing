<?php

namespace App\Database\Seeds;

use App\Models\DirectoryCategoryGroupModel;
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
                // Homeopath moved to Alternative & Traditional Medicine.
                'Dietician', 'Nutritionist',
                'Psychologist', 'Psychiatrist', 'Counsellor', 'Social Worker',
                'Nurse', 'Midwife', 'Pharmacist',
                'Pharmacy', 'Medical Clinic', 'Hospital', 'Pathology Laboratory',
                'Radiology Practice',
            ],
            // Hair folded in here, as Yelp files hair under "Beauty & Spas": a
            // four-row group was the smallest main category in the picker, and
            // someone looking for a salon does not decide first whether it is
            // hair or beauty. Hair Removal sits beside Waxing & Laser, which is
            // what it is. The slugs are untouched, so every link still resolves;
            // the Hair-only features and wording now come from the merged
            // Beauty & Wellness attribute set and Verticals' per-slug overrides.
            'Beauty & Wellness' => [
                'Hair Salon', 'Barber', 'Braiding & Extensions',
                'Spa', 'Nail Bar', 'Beauty Salon', 'Massage Therapist',
                'Skin & Aesthetics Clinic', 'Waxing & Laser', 'Hair Removal',
                'Tattoo & Piercing', 'Wellness Centre',
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
            // The courts and clubs are places people book or join, not
            // coaching: a padel court hire and a squash ladder are searched for
            // by the sport. Sports Club is the Wanderers kind of place, and its
            // own facilities are the 'facilities' facet in Config\ListingFacets.
            'Fitness & Sport' => [
                'Gym & Fitness Centre', 'Personal Trainer', 'Yoga Studio',
                'Pilates Studio', 'Martial Arts', 'Dance Studio', 'Sports Coaching',
                'Sports Club', 'Indoor Sports Centre', 'Padel Courts', 'Tennis Courts',
                'Squash Courts', 'Cricket Academy', 'Swimming School',
                'Golf Club & Driving Range',
            ],
            // Ordered as the school ladder, then everything taught outside it.
            // "Training College" is the TVET / private college a school leaver
            // enrols at; "Training Provider" is the accredited outfit that runs
            // short courses for people already working. Different searches, and
            // the directory launched with only the second one.
            //
            // The school types below are the *kind of institution* someone is
            // looking for. How a school teaches — Montessori, Waldorf, CAPS,
            // IEB, Cambridge — is deliberately NOT a category: it is the
            // 'curriculum' facet in Config\ListingFacets, because a Montessori
            // preschool and a Montessori primary are two rungs of the ladder
            // sharing one method, and a category cannot express that. Same for
            // boarding, which is a facet rather than a "Boarding School" row.
            'Education & Training' => [
                'Preschool & Daycare', 'Aftercare & Holiday Care',
                'Primary School', 'High School', 'Combined School',
                'Special Needs School', 'Remedial School',
                'Online School', 'Homeschooling Support',
                'Training College', 'University',
                'Tutor', 'Driving School', 'Language School', 'Music Teacher',
                'Computer Training', 'Training Provider',
            ],
            // Caterer stays: it is hired for an event, not walked into for a meal.
            'Events & Hospitality' => [
                'Event Planner', 'Caterer', 'Venue Hire', 'Florist',
                'DJ & Entertainment',
            ],
            // Somewhere to eat is what a hungry visitor searches for, not a party
            // supplier — so Restaurant, Coffee Shop and Bakery move out of Events
            // and lead here. Their slugs are untouched, so /directory/restaurant
            // and every saved filter link still resolve. The names stay too:
            // renaming "Coffee Shop" to "Coffee & Cafés" would mint a second slug
            // rather than rename the first.
            //
            // Same rule as the schools above: the *kind of place* is a category.
            // Takeaway, delivery and which meals are served are the 'dining'
            // facets in Config\ListingFacets, not rows — a pizzeria that delivers
            // is still a pizzeria. The cuisines carry "Restaurant" so the slug is
            // /directory/chinese-restaurant rather than a bare /directory/chinese.
            'Restaurants & Food' => [
                'Restaurant', 'Coffee Shop', 'Bakery',
                'Pizza', 'Italian Restaurant', 'Chinese Restaurant', 'Mexican Restaurant',
                'Indian Restaurant', 'Sushi & Asian', 'Steakhouse & Grill',
                'Fast Food & Takeaway', 'Sports Bar & Pub',
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
            // Not businesses. Filed by cause, the way Google asks a nonprofit to
            // choose its most specific category ("Food bank", not "Non-profit
            // organization"): a foundation is a trust, an NPC or a voluntary
            // association in law, and none of those says what it does. The
            // general rows stay first for a body with no single cause.
            'Community & Nonprofit' => [
                'NGO & Nonprofit', 'Community Organisation', 'Foundation & Trust',
                'Community Project', 'Scheme & Programme', 'Charity & Welfare',
                'Residents Association',
                'Children & Youth', 'Education & Bursaries', 'Feeding Scheme & Food Security',
                'Health & HIV Support', 'Elderly Care', 'Disability Support',
                'Women & Family Support', 'Skills & Job Creation', 'Shelter & Housing',
                'Animal Welfare', 'Environment & Conservation', 'Arts & Culture',
                'Sport Development',
            ],
            // Yelp's "Religious Organizations": the buildings people go to on a
            // Sunday or a Friday. Faith Organisation is the faith based ministry
            // or NGO that is not a congregation. Place of Worship catches the rest.
            'Faith & Worship' => [
                'Church', 'Mosque', 'Hindu Temple', 'Synagogue', 'Buddhist Temple',
                'Place of Worship', 'Faith Organisation',
            ],
            // Somewhere people go that holds other things: Yelp's Shopping
            // Centers, Stadiums & Arenas, Community Centers and Landmarks. The
            // profile type Place or Venue suggests these first, and a Place can
            // be linked to the businesses inside it (see directory_venues).
            'Places & Venues' => [
                'Shopping Centre & Mall', 'Stadium & Arena', 'Community Hall & Centre',
                'Market & Flea Market', 'Office & Business Park', 'Theatre & Arts Venue',
                'Museum & Gallery', 'Heritage Site & Landmark', 'Park & Recreation Area',
            ],
            // Complementary and traditional practice, kept apart from Health &
            // Medical so a search for a GP does not surface a healer and the
            // other way round. Last in DirectoryCategoryGroupModel::DEFAULT_ORDER.
            // Traditional Healer first: a sangoma or inyanga is the most
            // searched-for practitioner of this kind in South Africa.
            'Alternative & Traditional Medicine' => [
                'Traditional Healer', 'Homeopath', 'Naturopath', 'Acupuncturist',
                'Reiki Practitioner', 'Ayurvedic Practitioner', 'Herbalist',
                'Reflexologist',
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

        // A healer category was added by hand from /admin/categories before
        // this group existed, under a longer name ("Alternative Medicine
        // Traditional Healer"). Adopt that row rather than inserting a second
        // "Traditional Healer" beside it: the listing on it keeps its category,
        // and its slug — which saveCategory() never rewrites — keeps its links.
        $healer = $db->table($table)->select('slug')
            ->where('slug !=', 'traditional-healer')
            ->like('name', 'Traditional Healer')
            ->orderBy('id', 'ASC')
            ->get()->getRowArray();
        $healerTaken = $db->table($table)->where('slug', 'traditional-healer')->countAllResults() > 0;
        if ($healer !== null && ! $healerTaken) {
            foreach ($rows as $i => $r) {
                if ($r['slug'] === 'traditional-healer') {
                    $rows[$i]['slug'] = $healer['slug'];
                }
            }
            $db->table($table)->where('slug', $healer['slug'])->update([
                'name'       => 'Traditional Healer',
                'updated_at' => $now,
            ]);
        }

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
            'Hair'                  => 'Beauty & Wellness',
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

        // One groups row per group now in use, placed by DEFAULT_ORDER. A row
        // that already exists keeps the position the admin gave it. Rows for
        // groups this run emptied — Hair, and whatever group the hand-made
        // healer row sat in — go, or they would linger in the admin panel as
        // main categories with nothing under them.
        $groupModel = new DirectoryCategoryGroupModel($db);
        $groupNames = array_column(
            $db->table($table)->distinct()->select('group_name')
                ->where('group_name IS NOT NULL')->where('group_name !=', '')
                ->get()->getResultArray(),
            'group_name'
        );
        foreach ($groupNames as $name) {
            $groupModel->ensure((string) $name);
        }
        if ($groupNames !== []) {
            $db->table('xs_directory_category_groups')->whereNotIn('name', $groupNames)->delete();
        }
    }
}

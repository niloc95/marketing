<?php

namespace App\Database\Seeds;

use CodeIgniter\Database\Seeder;

class DirectoryProfessionsSeeder extends Seeder
{
    public function run(): void
    {
        helper('slug');

        $groups = [
            'Medical Practitioners' => [
                'General Practitioner', 'Physician', 'Paediatrician', 'Cardiologist',
                'Dermatologist', 'Gynaecologist', 'General Surgeon', 'Orthopaedic Surgeon',
                'Ophthalmologist', 'ENT Specialist', 'Neurologist', 'Oncologist',
                'Anaesthetist', 'Urologist', 'Radiologist',
            ],
            'Mental Health' => [
                'Psychiatrist', 'Psychologist', 'Counsellor', 'Social Worker',
            ],
            'Dental' => [
                'Dentist', 'Orthodontist', 'Oral Hygienist',
            ],
            'Allied Health' => [
                'Physiotherapist', 'Occupational Therapist', 'Speech Therapist',
                'Dietician', 'Biokineticist', 'Podiatrist', 'Chiropractor',
                'Audiologist', 'Optometrist',
            ],
            'Nursing & Pharmacy' => [
                'Nurse', 'Midwife', 'Pharmacist',
            ],
            'Wellness' => [
                'Nutritionist', 'Massage Therapist', 'Homeopath',
            ],
            'Facilities' => [
                'Hospital', 'Clinic', 'Pharmacy (Retail)', 'Pathology Laboratory',
                'Radiology Practice',
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

        // Idempotent: skip names that already exist.
        $db      = $this->db;
        $table   = $db->table('xs_directory_professions');
        $existing = $table->select('slug')->get()->getResultArray();
        $have    = array_column($existing, 'slug');
        $insert  = array_values(array_filter($rows, static fn ($r) => ! in_array($r['slug'], $have, true)));

        if ($insert !== []) {
            $table->insertBatch($insert);
        }
    }
}

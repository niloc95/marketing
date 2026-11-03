<?php

use CodeIgniter\Test\CIUnitTestCase;

/**
 * Config\SchemaTypes and the Person node an individual's profile publishes.
 *
 * The type tables decide what Google is told every profile is, and the admin
 * override publishes its value as-is, so both are pinned here: every doctor
 * is a Physician with real MedicalSpecialty members, an override can only be
 * a listed type, and the Person on an individual's page carries what
 * identifies the person while the practice keeps the local business facts.
 *
 * @internal
 */
final class SchemaTypesTest extends CIUnitTestCase
{
    /** Every member of schema.org's MedicalSpecialty enumeration (v29). */
    private const SPECIALTIES = [
        'Anesthesia', 'Audiology', 'Cardiovascular', 'CommunityHealth', 'Dentistry', 'Dermatology',
        'DietNutrition', 'Emergency', 'Endocrine', 'Gastroenterologic', 'Genetic', 'Geriatric',
        'Gynecologic', 'Hematologic', 'Infectious', 'LaboratoryScience', 'Midwifery', 'Musculoskeletal',
        'Neurologic', 'Nursing', 'Obstetric', 'Oncologic', 'Ophthalmology', 'Optometric',
        'Otolaryngologic', 'Pathology', 'Pediatric', 'PharmacySpecialty', 'Physiotherapy',
        'PlasticSurgery', 'Podiatric', 'PrimaryCare', 'Psychiatric', 'PublicHealth', 'Pulmonary',
        'Radiography', 'Renal', 'RespiratoryTherapy', 'Rheumatologic', 'SpeechPathology', 'Surgical',
        'Toxicologic', 'Urologic',
    ];

    private const CANONICAL = 'https://example.test/directory/dr-ana-pillay';

    // ---- the mapping -----------------------------------------------------

    public function testEveryMappedTypeIsOneAnAdminCouldChoose(): void
    {
        $types   = config('SchemaTypes');
        $allowed = $types->allowed();

        foreach (array_merge($types->places, $types->bySlug, $types->byGroup) as $key => $type) {
            $this->assertContains($type, $allowed, $key);
        }
        $this->assertContains('LocalBusiness', $allowed);
    }

    public function testEveryDoctorIsAPhysician(): void
    {
        // The specialties the October 2026 import brought in used to publish as
        // the group's MedicalBusiness.
        foreach (['neurosurgeon', 'plastic-reconstructive-surgeon', 'radiation-oncologist', 'vascular-surgeon',
            'nephrologist', 'endocrinologist', 'gastroenterologist', 'pathologist'] as $slug) {
            $this->assertSame('Physician', schema_business_type('Health & Medical', $slug), $slug);
        }
        // Not a doctor: still the group's type.
        $this->assertSame('MedicalBusiness', schema_business_type('Health & Medical', 'psychologist'));
    }

    public function testSpecialtiesAreRealSchemaOrgMembers(): void
    {
        foreach (config('SchemaTypes')->medicalSpecialty as $slug => $list) {
            $this->assertNotEmpty($list, $slug);
            foreach ($list as $member) {
                $this->assertContains($member, self::SPECIALTIES, $slug);
            }
        }
    }

    public function testSpecialtyOnlyOnTypesThatTakeIt(): void
    {
        $this->assertSame('https://schema.org/Endocrine', schema_medical_specialty('Physician', 'endocrinologist'));
        $this->assertSame(
            ['https://schema.org/Neurologic', 'https://schema.org/Surgical'],
            schema_medical_specialty('Physician', 'neurosurgeon')
        );
        // A category without a fitting member claims none.
        $this->assertNull(schema_medical_specialty('Physician', 'specialist-physician'));
        // MedicalBusiness is not a MedicalOrganization: no medicalSpecialty.
        $this->assertNull(schema_medical_specialty('MedicalBusiness', 'endocrinologist'));
        $this->assertNull(schema_medical_specialty(['LocalBusiness', 'DiagnosticLab'], 'not-a-slug'));
    }

    public function testTheAdminOverrideWinsButOnlyFromTheList(): void
    {
        $this->assertSame('Winery', schema_business_type('Restaurants & Food', 'restaurant', 'practice', 'Winery'));
        // A non LocalBusiness type is paired, exactly as from the tables.
        $this->assertSame(['LocalBusiness', 'Museum'], schema_business_type('Retail & Other', '', '', 'Museum'));
        // Anything else is ignored, so a bad row never publishes an invented type.
        $this->assertSame('Restaurant', schema_business_type('Restaurants & Food', 'restaurant', '', 'MadeUpType'));
        $this->assertSame('Restaurant', schema_business_type('Restaurants & Food', 'restaurant', '', ''));
    }

    public function testANewCategoryInheritsItsGroup(): void
    {
        $this->assertSame('AutomotiveBusiness', schema_business_type('Motoring', 'mobile-tyre-fitter'));
        $this->assertSame('LocalBusiness', schema_business_type('A Group Nobody Mapped', 'anything'));
    }

    // ---- the Person on an individual's profile ---------------------------

    /** @param array<string,mixed> $over */
    private function doctor(array $over = []): array
    {
        return array_merge([
            'type'            => 'person',
            'slug'            => 'dr-ana-pillay',
            'display_name'    => 'Dr Ana Pillay',
            'contact_person'  => 'Ana Pillay',
            'title'           => 'Dr',
            'email'           => 'ana@example.test',
            'phone'           => '0115551234',
            'city'            => 'Johannesburg',
            'province'        => 'Gauteng',
            'credentials'     => "MBChB (UCT)\nFCP (SA)",
            'description_text' => 'Endocrinologist in Rosebank.',
            'social_linkedin' => 'https://www.linkedin.com/in/ana-pillay',
            'social_facebook' => 'https://www.facebook.com/pillayrooms',
            'tags'            => ['Diabetes', 'Thyroid'],
            'category'        => ['group_name' => 'Health & Medical', 'slug' => 'endocrinologist', 'name' => 'Endocrinologist'],
        ], $over);
    }

    public function testAnIndividualPublishesAPersonWhoWorksAtThePractice(): void
    {
        $l      = $this->doctor();
        $person = schema_person($l, self::CANONICAL);

        $this->assertSame('Person', $person['@type']);
        $this->assertSame(self::CANONICAL . '#person', $person['@id']);
        $this->assertSame('Dr Ana Pillay', $person['name']);
        $this->assertSame('Ana Pillay', $person['alternateName']);
        $this->assertSame('Dr', $person['honorificPrefix']);
        $this->assertSame('Endocrinologist', $person['jobTitle']);
        $this->assertSame(['@type' => 'Occupation', 'name' => 'Endocrinologist'], $person['hasOccupation']);
        $this->assertSame(['MBChB (UCT)', 'FCP (SA)'], array_column($person['hasCredential'], 'name'));
        $this->assertSame(['Diabetes', 'Thyroid'], $person['knowsAbout']);
        $this->assertSame(['https://www.linkedin.com/in/ana-pillay'], $person['sameAs']);
        $this->assertSame(['@id' => self::CANONICAL . '#business'], $person['worksFor']);
        // Nothing to reach the person by: no email, no telephone.
        $this->assertArrayNotHasKey('telephone', $person);
        $this->assertStringNotContainsString('ana@example.test', json_encode($person));
    }

    public function testThePracticeKeepsTheLocalFactsAndPointsAtThePerson(): void
    {
        $business = schema_local_business($this->doctor(), self::CANONICAL);

        $this->assertSame('Physician', $business['@type']);
        $this->assertSame('https://schema.org/Endocrine', $business['medicalSpecialty']);
        $this->assertSame('+27115551234', $business['telephone']);
        $this->assertSame([['@id' => self::CANONICAL . '#person']], $business['employee']);
        // The person's own facts are on the person, not repeated here.
        $this->assertArrayNotHasKey('hasCredential', $business);
        $this->assertSame(['https://www.facebook.com/pillayrooms'], $business['sameAs']);
    }

    public function testAPracticeHasNoPersonAndKeepsItsCredentials(): void
    {
        $l = $this->doctor(['type' => 'practice']);

        $this->assertSame([], schema_person($l, self::CANONICAL));
        $business = schema_local_business($l, self::CANONICAL);
        $this->assertSame(['MBChB (UCT)', 'FCP (SA)'], array_column($business['hasCredential'], 'name'));
        $this->assertArrayNotHasKey('employee', $business);
        $this->assertContains('https://www.linkedin.com/in/ana-pillay', $business['sameAs']);
    }

    public function testTheTeamCardThatIsThePersonMergesIntoThePerson(): void
    {
        $business = schema_local_business($this->doctor(['team' => [
            ['name' => 'Ana  Pillay', 'slug' => 'ana-pillay', 'role' => 'Head of Practice'],
            ['name' => 'Sam Dube', 'slug' => 'sam-dube', 'role' => 'Practice manager'],
        ]]), self::CANONICAL);

        [$ana, $sam] = $business['employee'];
        $this->assertSame(self::CANONICAL . '#person', $ana['@id']);
        $this->assertSame('Head of Practice', $ana['jobTitle']);
        $this->assertSame(self::CANONICAL . '#team-sam-dube', $sam['@id']);
        $this->assertCount(2, $business['employee']);
    }

    public function testTheProfilePageIsAboutThePerson(): void
    {
        $l    = $this->doctor();
        $page = schema_page(
            [schema_person($l, self::CANONICAL), schema_local_business($l, self::CANONICAL)],
            self::CANONICAL,
            'ProfilePage',
            'Dr Ana Pillay',
            false,
            ['dateCreated' => schema_datetime('2026-10-20 08:58:41')]
        );
        $node = null;
        foreach ($page['@graph'] as $n) {
            if (($n['@type'] ?? '') === 'ProfilePage') {
                $node = $n;
            }
        }

        $this->assertSame(['@id' => self::CANONICAL . '#person'], $node['mainEntity']);
        $this->assertSame(['@id' => self::CANONICAL . '#person'], $node['about']);
        $this->assertSame('2026-10-20T08:58:41+00:00', $node['dateCreated']);
        $this->assertSame('', schema_datetime(null));
        $this->assertSame('', schema_datetime('0000-00-00 00:00:00'));
    }

    // ---- our own accounts ------------------------------------------------

    public function testOurOwnSocialAccountsNeverReachAProfile(): void
    {
        foreach ([
            ['social_facebook', 'https://www.facebook.com/WebScheduler'],
            ['social_facebook', 'facebook.com/webscheduler/'],
            ['social_instagram', 'https://www.instagram.com/WebScheduler'],
            ['social_instagram', '@webschedulerlocal'],
            ['social_linkedin', 'https://www.linkedin.com/company/webscheduler-local/'],
        ] as [$field, $value]) {
            $this->assertSame('', social_profile_url($field, $value), $value);
        }
        // A customer's own page with a similar name is theirs.
        $this->assertSame('https://www.facebook.com/WebSchedulerFan', social_profile_url('social_facebook', 'https://www.facebook.com/WebSchedulerFan'));

        $business = schema_local_business([
            'display_name'     => 'Collins Road Attorneys',
            'social_facebook'  => 'https://www.facebook.com/WebScheduler',
            'social_linkedin'  => 'https://www.linkedin.com/company/webscheduler-local/',
        ], 'https://example.test/directory/collins-road-attorneys');
        $this->assertArrayNotHasKey('sameAs', $business);
    }
}

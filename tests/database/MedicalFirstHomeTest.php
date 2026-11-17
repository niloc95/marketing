<?php

use App\Models\DirectoryCategoryModel;
use App\Models\DirectoryListingModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;
use Tests\Support\FreshDatabase;

/**
 * Medical first, phase 3 (owner, 17 Nov 2026): the home page's "Find a
 * doctor, dentist or vet" row (DirectoryService::careEntryPoints()) and the
 * "For patients" box on a profile (listing_care_facts(), _care_panel.php).
 *
 * Requires the `tests` database group — see the Tests section of README.md.
 *
 * @internal
 */
final class MedicalFirstHomeTest extends CIUnitTestCase
{
    use FreshDatabase;
    use FeatureTestTrait;

    private DirectoryListingModel $listings;

    /** @var array<string,int> */
    private array $cats = [];

    protected function setUp(): void
    {
        parent::setUp();
        cache()->clean();
        Services::resetSingle('site');

        $model = new DirectoryCategoryModel();
        foreach ([
            ['General Practitioner', 'general-practitioner', 'Health & Medical'],
            ['Dentist', 'dentist', 'Health & Medical'],
            ['Veterinarian', 'veterinarian', 'Pets & Animals'],
            ['Plumber', 'plumber', 'Home & Trades'],
        ] as [$name, $slug, $group]) {
            $this->cats[$slug] = (int) $model->insert(['name' => $name, 'slug' => $slug, 'group_name' => $group, 'is_active' => 1], true);
        }
        $this->listings = new DirectoryListingModel();
    }

    public function testTheRowNamesOnlyKindsWithSomeoneBehindThem(): void
    {
        $this->publish('general-practitioner', 'Dr One');
        $this->publish('dentist', 'Smile Dental');
        $this->publish('plumber', 'Pipe Plumbing');

        $html = $this->home();
        $this->assertStringContainsString('Find a doctor or dentist', $html);
        $this->assertStringContainsString('directory?group=doctors', html_entity_decode($html));
        $this->assertStringNotContainsString('directory/veterinarian', html_entity_decode($html), 'no vet yet, no empty page');

        $this->publish('veterinarian', 'Paws Vet');
        cache()->clean();
        $this->assertStringContainsString('Find a doctor, dentist or vet', $this->home());
    }

    public function testNoMedicalProfilesNoRow(): void
    {
        $this->publish('plumber', 'Pipe Plumbing');
        $this->assertStringNotContainsString('home-care', $this->home());
    }

    public function testThePatientBoxLiftsFeaturesAndReadsTheHours(): void
    {
        $v     = vertical_profile('Health & Medical', 'general-practitioner');
        $hours = ['mon' => ['closed' => false, 'open' => '08:00', 'close' => '19:00'], 'sat' => ['closed' => false, 'open' => '09:00', 'close' => '12:00'], 'sun' => ['closed' => true, 'open' => '', 'close' => '']];
        $facts = listing_care_facts([
            'attributes'    => ['wheelchair_access' => 'Wheelchair access', 'medical_aid_accepted' => 'Medical aid accepted'],
            'trading_hours' => $hours,
        ], $v);
        $this->assertSame(['Medical aid accepted', 'Open in the evening', 'Open on weekends'], $facts);

        $this->assertSame(['Open in the evening'], listing_care_facts(['trading_hours' => json_encode(['mon' => $hours['mon']])], $v), 'stored JSON reads the same');
        $this->assertSame([], listing_care_facts(['attributes' => ['medical_aid_accepted' => 'x'], 'trading_hours' => $hours], vertical_profile('Home & Trades', 'plumber')), 'a plumber gets no box');
        $this->assertSame('clients', vertical_profile('Pets & Animals', 'veterinarian')['audience']);
    }

    public function testTheProfileShowsTheBoxOnceAndNotInFeatures(): void
    {
        $id = $this->publish('general-practitioner', 'Dr Box');
        db_connect()->table('xs_directory_listing_attributes')->insert(['listing_id' => $id, 'attribute_key' => 'medical_aid_accepted']);

        $html = (string) $this->get('/directory/' . slugify('Dr Box'))->getBody();
        $this->assertStringContainsString('id="for-patients"', $html);
        $this->assertStringContainsString('For patients', $html);
        $this->assertSame(1, substr_count($html, '<span>Medical aid accepted</span>'), 'said once, in the box');
    }

    private function publish(string $slug, string $name): int
    {
        return (int) $this->listings->insert([
            'type' => 'practice', 'display_name' => $name, 'slug' => slugify($name),
            'email' => slugify($name) . '@example.test', 'status' => 'published', 'category_id' => $this->cats[$slug],
            'city' => 'Durban', 'province' => 'KwaZulu-Natal', 'country' => 'South Africa',
            'published_at' => date('Y-m-d H:i:s'),
        ], true);
    }

    private function home(): string
    {
        Services::resetSingle('site');

        return (string) $this->get('/')->getBody();
    }
}

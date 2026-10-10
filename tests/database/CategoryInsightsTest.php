<?php

use App\Models\DirectoryCategoryModel;
use App\Models\DirectoryListingModel;
use App\Models\DirectoryListingServiceModel;
use App\Models\DirectoryTagModel;
use App\Services\Description\CategoryInsightsService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * The hints under the description field ("Others in Dentist often mention")
 * and the two endpoints behind "Help me write this".
 *
 * The draft's own wording is pinned without a database in
 * DescriptionDraftServiceTest. These are the parts that need real rows: what
 * may be mined, from whom, and what never comes out.
 *
 * Requires the `tests` database group — see the Tests section of README.md.
 *
 * @internal
 */
final class CategoryInsightsTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $namespace   = 'App';
    protected $migrate     = true;
    protected $migrateOnce = false;
    protected $refresh     = true;

    private DirectoryListingModel $listings;

    /** @var array<string,int> slug => id */
    private array $categories = [];

    protected function setUp(): void
    {
        parent::setUp();
        cache()->clean();
        // Templates only: a developer's .env may hold real Workers AI
        // credentials, and a test must never call Cloudflare.
        $_ENV['directory.workersAiAccountId'] = '';
        $_ENV['directory.workersAiToken']     = '';
        \Config\Services::resetSingle('descriptionWriter');
        $this->listings = new DirectoryListingModel();

        foreach ([
            'dentist'       => ['Dentist', 'Health & Medical'],
            'orthodontist'  => ['Orthodontist', 'Health & Medical'],
            'plumber'       => ['Plumber', 'Home & Trades'],
        ] as $slug => [$name, $group]) {
            $this->categories[$slug] = (int) (new DirectoryCategoryModel())->insert([
                'name' => $name, 'slug' => $slug, 'group_name' => $group, 'is_active' => 1,
            ], true);
        }
    }

    protected function tearDown(): void
    {
        unset($_ENV['directory.workersAiAccountId'], $_ENV['directory.workersAiToken']);
        \Config\Services::resetSingle('descriptionWriter');
        parent::tearDown();
    }

    public function testServicesSharedByThreeListingsBecomeHints(): void
    {
        foreach (['A', 'B', 'C'] as $n) {
            $this->listing("Dent {$n}", 'dentist', services: ['Teeth Whitening', 'Only ' . $n]);
        }

        $hints = (new CategoryInsightsService())->build($this->categories['dentist']);

        $this->assertSame('category', $hints['source']);
        $this->assertContains('Teeth whitening', $hints['hints']);
        // One listing's own service is never shown.
        $this->assertNotContains('Only a', $hints['hints']);
    }

    public function testTwoListingsAreNotEnough(): void
    {
        foreach (['A', 'B'] as $n) {
            $this->listing("Dent {$n}", 'dentist', services: ['Teeth whitening']);
        }

        $this->assertNotContains('Teeth whitening', (new CategoryInsightsService())->build($this->categories['dentist'])['hints']);
    }

    public function testOnlyPublishedListingsCount(): void
    {
        $this->listing('Dent A', 'dentist', services: ['Teeth whitening']);
        $this->listing('Dent B', 'dentist', services: ['Teeth whitening']);
        $this->listing('Dent C', 'dentist', ['status' => 'pending'], services: ['Teeth whitening']);

        $this->assertNotContains('Teeth whitening', (new CategoryInsightsService())->build($this->categories['dentist'])['hints']);
    }

    public function testSharedTagsBecomeHints(): void
    {
        foreach (['A', 'B', 'C'] as $n) {
            $id = $this->listing("Dent {$n}", 'dentist');
            (new DirectoryTagModel())->syncListingTags($id, ['Nervous patients']);
        }

        $this->assertContains('Nervous patients', (new CategoryInsightsService())->build($this->categories['dentist'])['hints']);
    }

    public function testAThinCategoryBorrowsFromItsMainCategory(): void
    {
        foreach (['A', 'B', 'C'] as $n) {
            $this->listing("Dent {$n}", 'dentist', services: ['Teeth whitening']);
        }

        $hints = (new CategoryInsightsService())->build($this->categories['orthodontist']);

        $this->assertSame('group', $hints['source']);
        $this->assertContains('Teeth whitening', $hints['hints']);
    }

    public function testWithNothingToMineTheSearchWordsAreUsed(): void
    {
        // Config\Search maps "braces" to orthodontist.
        $hints = (new CategoryInsightsService())->build($this->categories['orthodontist']);

        $this->assertSame('search', $hints['source']);
        $this->assertContains('Braces', $hints['hints']);
    }

    public function testStandoutWordPairsComeFromStrongDescriptionsOnly(): void
    {
        $strong = ['quality_score' => 80];
        $this->listing('Dent A', 'dentist', $strong + ['description_text' => 'Gentle dental care. We offer teeth whitening for the whole family.']);
        $this->listing('Dent B', 'dentist', $strong + ['description_text' => 'Teeth whitening and fillings, done with gentle dental care.']);
        $this->listing('Dent C', 'dentist', $strong + ['description_text' => 'Book teeth whitening today! Gentle dental care in Sandton.']);
        // Weak listings may say it too; it is the strong ones that count.
        $this->listing('Pipes A', 'plumber', ['quality_score' => 10, 'description_text' => 'Burst geysers fixed fast.']);
        $this->listing('Pipes B', 'plumber', ['quality_score' => 10, 'description_text' => 'Leaking taps and blocked drains.']);
        $this->listing('Pipes C', 'plumber', ['quality_score' => 10, 'description_text' => 'Geyser installs and repairs.']);

        $hints = (new CategoryInsightsService())->build($this->categories['dentist'])['hints'];

        $this->assertContains('Teeth whitening', $hints);
        $this->assertContains('Gentle dental', $hints);
        // Nothing longer than a pair of words, so no one's sentence comes out.
        foreach ($hints as $hint) {
            $this->assertLessThanOrEqual(2, count(explode(' ', $hint)), $hint);
        }

        $this->assertNotContains('Burst geysers', (new CategoryInsightsService())->build($this->categories['plumber'])['hints']);
    }

    public function testPlaceNamesAreNeverHints(): void
    {
        foreach (['A', 'B', 'C'] as $n) {
            $this->listing("Dent {$n}", 'dentist', ['city' => 'Sandton', 'quality_score' => 80, 'description_text' => 'Sandton dentistry for everyone.']);
        }

        foreach ((new CategoryInsightsService())->build($this->categories['dentist'])['hints'] as $hint) {
            $this->assertStringNotContainsStringIgnoringCase('sandton', $hint);
        }
    }

    public function testTheInsightsEndpoint(): void
    {
        foreach (['A', 'B', 'C'] as $n) {
            $this->listing("Dent {$n}", 'dentist', services: ['Teeth whitening']);
        }

        $json = json_decode($this->get('description/insights?category_id=' . $this->categories['dentist'])->getJSON(), true);

        $this->assertSame('Dentist', $json['category']);
        $this->assertContains('Teeth whitening', $json['hints']);
    }

    public function testTheDraftEndpoint(): void
    {
        $query = http_build_query([
            'type'              => 'practice',
            'category_id'       => $this->categories['dentist'],
            'display_name'      => 'Sandton Smiles',
            'city'              => 'Johannesburg',
            'suburb'            => 'Sandton',
            'customer_location' => 'visit',
            'services'          => ['Fillings', ''],
            'tags'              => 'Braces, Nervous patients',
            'features'          => ['parking', 'accepts_card_payments', 'not_a_feature'],
        ]);

        $json = json_decode($this->get('description/draft?' . $query)->getJSON(), true);

        $this->assertStringContainsString('Sandton Smiles is a dentist', $json['html']);
        $this->assertStringContainsString('fillings', $json['html']);
        $this->assertStringContainsString('braces and nervous patients', $json['html']);
        $this->assertStringContainsString('parking available and card payments accepted', $json['html']);
        $this->assertStringNotContainsString('not_a_feature', $json['html']);
    }

    public function testTheDraftEndpointAsksForWhatIsMissing(): void
    {
        $json = json_decode($this->get('description/draft?display_name=Sandton+Smiles')->getJSON(), true);

        $this->assertSame('', $json['html']);
        $this->assertStringContainsString('category', $json['message']);
    }

    public function testNoAnswerOnWhereCustomersMeetYouMeansTheyVisit(): void
    {
        $query = http_build_query(['category_id' => $this->categories['dentist'], 'display_name' => 'Sandton Smiles', 'services' => ['Fillings']]);

        $json = json_decode($this->get('description/draft?' . $query)->getJSON(), true);

        $this->assertStringNotContainsString('travel', $json['message']);
    }

    /** @param list<string> $services */
    private function listing(string $name, string $category, array $overrides = [], array $services = []): int
    {
        helper('slug');
        $id = (int) $this->listings->insert($overrides + [
            'type'         => 'practice',
            'display_name' => $name,
            'email'        => slugify($name) . '@example.test',
            'slug'         => slugify($name),
            'status'       => 'published',
            'category_id'  => $this->categories[$category],
            'city'         => 'Johannesburg',
            'province'     => 'Gauteng',
        ], true);
        $this->assertGreaterThan(0, $id, implode(', ', $this->listings->errors()));

        foreach ($services as $i => $service) {
            (new DirectoryListingServiceModel())->insert(['listing_id' => $id, 'name' => $service, 'sort_order' => $i]);
        }

        return $id;
    }
}

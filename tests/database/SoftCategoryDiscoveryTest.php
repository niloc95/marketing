<?php

use App\Models\DirectoryCategoryModel;
use App\Models\DirectoryListingModel;
use App\Services\DirectoryService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use Tests\Support\FreshDatabase;

/**
 * "solar installation" (owner, 13 Nov 2026: found no solar business in the
 * Discover dropdown).
 *
 *   - A category read from the visitor's words is a preference: its own
 *     profiles first, then any other profile that uses those words
 *     (DirectoryService::applyCategory()). A category chosen outright stays
 *     strict.
 *   - Words match their stem: "installation" finds "installer".
 *   - The dropdown offers the understood category first, then businesses
 *     that use every word, and "Discover every match" for all of it.
 *
 * @internal
 */
final class SoftCategoryDiscoveryTest extends CIUnitTestCase
{
    use FreshDatabase;
    use FeatureTestTrait;

    /** @var array<string,int> slug => id */
    private array $ids = [];

    protected function setUp(): void
    {
        parent::setUp();
        cache()->clean();

        foreach ([
            ['Solar & Renewable Energy', 'solar-renewable-energy', 'Home & Trades'],
            ['Electrician', 'electrician', 'Home & Trades'],
            ['Security & Alarms', 'security-alarms', 'Home & Trades'],
        ] as [$name, $slug, $group]) {
            $this->ids[$slug] = (int) (new DirectoryCategoryModel())->insert([
                'name' => $name, 'slug' => $slug, 'group_name' => $group, 'is_active' => 1,
            ], true);
        }
    }

    public function testAWordCategoryAlsoFindsProfilesOutsideItThatUseTheWordAfterItsOwn(): void
    {
        $this->listing('Sun Panels', 'solar-renewable-energy', 'Panels and batteries for homes.', 10);
        $this->listing('Bright Sparks', 'electrician', 'Wiring, compliance and solar installations.', 90);
        $this->listing('Gate Guard', 'security-alarms', 'Gate motors and alarms.', 95);

        $slugs = $this->browse(['category' => 'solar-renewable-energy', 'soft' => 'solar']);

        $this->assertSame(['sun-panels', 'bright-sparks'], $slugs, 'the category first, whatever the score');
        $this->assertStringContainsString('Bright Sparks', $this->search('solar'));
    }

    public function testAChosenCategoryStaysStrict(): void
    {
        $this->listing('Sun Panels', 'solar-renewable-energy', 'Panels and batteries for homes.');
        $this->listing('Bright Sparks', 'electrician', 'Wiring, compliance and solar installations.');

        $this->assertSame(['sun-panels'], $this->browse(['category' => 'solar-renewable-energy']));
        $this->assertStringNotContainsString('Bright Sparks', $this->search('solar', '&category=solar-renewable-energy'));
        $this->assertStringNotContainsString('Bright Sparks', (string) $this->get('directory/solar-renewable-energy')->getBody());
    }

    public function testAWordFindsItsStem(): void
    {
        $this->listing('Sun Panels', 'solar-renewable-energy', 'We are a certified solar installer in Pretoria.');

        $this->assertSame(['sun-panels'], $this->browse(['q' => 'installation']));
        $this->assertSame(['sun-panels'], $this->browse(['q' => 'installing']));
    }

    public function testTheDropdownOffersTheUnderstoodCategoryAndBusinessesUsingEveryWord(): void
    {
        $this->listing('Sun Panels', 'solar-renewable-energy', 'Solar installation for homes and farms.');
        $this->listing('Leo Green', 'solar-renewable-energy', 'Reliable solar systems for homes.');
        $this->listing('Pacific Installations', 'security-alarms', 'CCTV installation and gate motors.');

        $items  = $this->suggest('solar installation');
        $labels = array_column($items, 'label');

        $this->assertSame('category', $items[0]['type']);
        $this->assertSame('Solar & Renewable Energy', $items[0]['label']);
        $this->assertContains('Sun Panels', $labels);
        $this->assertContains('Leo Green', $labels, 'the category fills up when few use every word');
        $this->assertNotContains('Pacific Installations', $labels, 'never matched on the leftover word alone');
        $this->assertContains('Discover every match for "solar installation"', $labels);
        $this->assertNotContains('Discover every match for "installation"', $labels);
        $this->assertSame(count($labels), count(array_unique(array_column($items, 'url'))), 'one row per link');
    }

    /** @return list<string> slugs, in order */
    private function browse(array $filters): array
    {
        return array_column((new DirectoryService())->browse($filters)['items'], 'slug');
    }

    private function search(string $q, string $extra = ''): string
    {
        $result = $this->get('directory?q=' . rawurlencode($q) . $extra);
        $result->assertOK();

        return (string) $result->getBody();
    }

    /** @return list<array<string,string>> */
    private function suggest(string $q): array
    {
        $result = $this->get('directory/suggest?q=' . rawurlencode($q));
        $result->assertOK();

        return json_decode($result->getJSON(), true)['items'];
    }

    private function listing(string $name, string $category, string $description, int $score = 50): int
    {
        helper('slug');
        $id = (int) (new DirectoryListingModel())->insert([
            'type'             => 'practice',
            'display_name'     => $name,
            'email'            => slugify($name) . '@example.test',
            'slug'             => slugify($name),
            'status'           => 'published',
            'category_id'      => $this->ids[$category],
            'description'      => '<p>' . $description . '</p>',
            'description_text' => $description,
            'quality_score'    => $score,
            'city'             => 'Johannesburg',
            'province'         => 'Gauteng',
        ], true);
        $this->assertGreaterThan(0, $id);

        return $id;
    }
}

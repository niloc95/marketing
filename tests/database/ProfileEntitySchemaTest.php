<?php

use App\Models\DirectoryCategoryModel;
use App\Models\DirectoryListingModel;
use App\Filters\AdminFilter;
use App\Services\DirectoryAdminService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;
use Tests\Support\FreshDatabase;
use Tests\Support\WithEnv;

/**
 * The JSON-LD a real profile page renders: an individual is a Person who
 * works at their practice, a practice is a business as before, the category's
 * admin override reaches the page, and on .app every @id is on the .app host.
 *
 * The node builders are pinned in tests/unit/SchemaTypesTest.php; this checks
 * show.php wires them up from what getProfile() loads.
 *
 * Requires the `tests` database group — see the Tests section of README.md.
 *
 * @internal
 */
final class ProfileEntitySchemaTest extends CIUnitTestCase
{
    use FreshDatabase;
    use FeatureTestTrait;
    use WithEnv;

    private const APP_HOST = 'local.webscheduler.app';

    private int $categoryId;

    protected function setUp(): void
    {
        parent::setUp();
        cache()->clean();
        $this->categoryId = (int) (new DirectoryCategoryModel())->insert([
            'name' => 'Endocrinologist', 'slug' => 'endocrinologist', 'group_name' => 'Health & Medical', 'is_active' => 1,
        ], true);
    }

    protected function tearDown(): void
    {
        $this->restoreEnv();
        Services::resetSingle('site');
        cache()->clean();
        parent::tearDown();
    }

    public function testAnIndividualsPageIsAboutThePerson(): void
    {
        $this->listing('dr-ana-pillay', 'person');

        $graph = $this->graph('example.com', 'directory/dr-ana-pillay');
        $page  = $this->node($graph, 'ProfilePage');
        $base  = $page['@id'];

        $this->assertSame(['@id' => $base . '#person'], $page['mainEntity']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\+00:00$/', $page['dateCreated']);
        $this->assertArrayHasKey('dateModified', $page);

        $person = $this->node($graph, 'Person');
        $this->assertSame('Dr', $person['honorificPrefix']);
        $this->assertSame('Endocrinologist', $person['jobTitle']);
        $this->assertSame(['@id' => $base . '#business'], $person['worksFor']);

        $business = $this->node($graph, 'Physician');
        $this->assertSame('https://schema.org/Endocrine', $business['medicalSpecialty']);
        $this->assertStringNotContainsString('dr-ana-pillay@example.test', json_encode($graph));
    }

    public function testAPracticePageIsAboutTheBusinessAsBefore(): void
    {
        $this->listing('pillay-rooms', 'practice');

        $graph = $this->graph('example.com', 'directory/pillay-rooms');
        $page  = $this->node($graph, 'ProfilePage');

        $this->assertSame(['@id' => $page['@id'] . '#business'], $page['mainEntity']);
        $this->assertNull($this->node($graph, 'Person'));
    }

    public function testTheCategoryOverrideReachesThePage(): void
    {
        $result = (new DirectoryAdminService())->saveCategory($this->categoryId, [
            'name' => 'Endocrinologist', 'group_name' => 'Health & Medical', 'is_active' => 1, 'schema_type' => 'MedicalClinic',
        ]);
        $this->assertTrue($result['ok']);
        $this->listing('pillay-rooms', 'practice');

        $this->assertNotNull($this->node($this->graph('example.com', 'directory/pillay-rooms'), 'MedicalClinic'));
    }

    public function testTheOverrideRefusesATypeOffTheList(): void
    {
        $svc    = new DirectoryAdminService();
        $result = $svc->saveCategory($this->categoryId, [
            'name' => 'Endocrinologist', 'group_name' => 'Health & Medical', 'is_active' => 1, 'schema_type' => 'Wizard',
        ]);
        $this->assertFalse($result['ok']);

        // Empty means automatic, and a form without the field leaves it alone.
        $svc->saveCategory($this->categoryId, ['name' => 'Endocrinologist', 'group_name' => 'Health & Medical', 'is_active' => 1, 'schema_type' => 'Hospital']);
        $svc->saveCategory($this->categoryId, ['name' => 'Endocrinologist', 'group_name' => 'Health & Medical', 'is_active' => 1]);
        $this->assertSame('Hospital', (new DirectoryCategoryModel())->find($this->categoryId)['schema_type']);
        $svc->saveCategory($this->categoryId, ['name' => 'Endocrinologist', 'group_name' => 'Health & Medical', 'is_active' => 1, 'schema_type' => '']);
        $this->assertNull((new DirectoryCategoryModel())->find($this->categoryId)['schema_type']);
    }

    public function testTheCategoriesScreenOffersTheTypeWithTheAutomaticOne(): void
    {
        $html = (string) $this->withSession(['dir_admin' => true, AdminFilter::SEEN_KEY => time()])
            ->get('admin/categories')->getBody();

        $this->assertStringContainsString('name="schema_type"', $html);
        $this->assertStringContainsString('Automatic (Physician)', $html);
        $this->assertStringContainsString('<option value="Winery"', $html);
    }

    public function testOnTheAppAnIndividualIsAPersonWithOneHostForEveryId(): void
    {
        // Under test base_url() keeps the configured host whatever the
        // request's (see SiteScopeTest::testTheHomePagesPointAtEachOther), so
        // this pins that every @id shares the page's host; on a real server
        // that host is local.webscheduler.app.
        $this->setEnv('sites.app.enabled', 'true');
        $this->listing('dr-tom-hart', 'person', [
            'city' => 'London', 'region' => 'England', 'country' => 'United Kingdom', 'province' => null,
        ]);

        $graph = $this->graph(self::APP_HOST, 'directory/dr-tom-hart');
        $page  = $this->node($graph, 'ProfilePage');
        $host  = parse_url($page['@id'], PHP_URL_HOST);
        foreach ($graph as $node) {
            if (isset($node['@id'])) {
                $this->assertSame($host, parse_url($node['@id'], PHP_URL_HOST), $node['@id']);
            }
        }
        $this->assertSame($page['@id'] . '#person', $page['mainEntity']['@id']);
        $this->assertSame('United Kingdom', $this->node($graph, 'Physician')['address']['addressCountry']);
    }

    // ------------------------------------------------------------- helpers

    /** @param array<string,mixed> $over */
    private function listing(string $slug, string $type, array $over = []): int
    {
        $name = ucwords(str_replace('-', ' ', $slug));

        return (int) (new DirectoryListingModel())->insert(array_merge([
            'type'             => $type,
            'display_name'     => $name,
            'contact_person'   => preg_replace('/^Dr /', '', $name),
            'title'            => 'Dr',
            'email'            => $slug . '@example.test',
            'slug'             => $slug,
            'status'           => 'published',
            'category_id'      => $this->categoryId,
            'city'             => 'Johannesburg',
            'province'         => 'Gauteng',
            'country'          => 'South Africa',
            'description'      => '<p>Diabetes and thyroid care in Rosebank, Johannesburg.</p>',
            'description_text' => 'Diabetes and thyroid care in Rosebank, Johannesburg.',
            'published_at'     => date('Y-m-d H:i:s'),
        ], $over), true);
    }

    /** @return list<array<string,mixed>> */
    private function graph(string $host, string $path): array
    {
        Services::resetSingle('site');
        $result = $this->withHeaders(['Host' => $host])->get($path);
        $result->assertOK();

        preg_match('#<script type="application/ld\+json"[^>]*>(.*?)</script>#s', (string) $result->getBody(), $m);
        $this->assertNotEmpty($m, 'no JSON-LD on ' . $path);

        return json_decode($m[1], true, 512, JSON_THROW_ON_ERROR)['@graph'];
    }

    /** @param list<array<string,mixed>> $graph */
    private function node(array $graph, string $type): ?array
    {
        foreach ($graph as $node) {
            if (in_array($type, (array) ($node['@type'] ?? ''), true)) {
                return $node;
            }
        }

        return null;
    }
}

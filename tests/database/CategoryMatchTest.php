<?php

use App\Filters\AdminFilter;
use App\Models\DirectoryCategoryModel;
use App\Models\DirectoryListingModel;
use App\Services\Search\CategoryMatchService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use Tests\Support\FreshDatabase;

/**
 * "What does your business do?" on the profile forms: the categories offered
 * for a business described in its own words (CategoryMatchService), what is
 * kept of it, and the /admin/discovery block that links a miss to a category.
 *
 * Added 13 Nov 2026 after the owner saw new businesses struggle to find their
 * category among 255 in two selects.
 *
 * @internal
 */
final class CategoryMatchTest extends CIUnitTestCase
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
            ['Solar & Renewable Energy', 'solar-renewable-energy', 'Home & Trades', 1],
            ['Electrician', 'electrician', 'Home & Trades', 1],
            ['Plumber', 'plumber', 'Home & Trades', 1],
            ['Dentist', 'dentist', 'Health & Medical', 1],
            ['Hair Salon', 'hair-salon', 'Beauty & Wellness', 1],
            ['Beauty Salon', 'beauty-salon', 'Beauty & Wellness', 1],
            ['Nail Bar', 'nail-bar', 'Beauty & Wellness', 1],
            ['Panel Beater', 'panel-beater', 'Motoring', 1],
            ['Wind Turbines', 'wind-turbines', 'Home & Trades', 0],
        ] as [$name, $slug, $group, $active]) {
            $this->ids[$slug] = (int) (new DirectoryCategoryModel())->insert([
                'name' => $name, 'slug' => $slug, 'group_name' => $group, 'is_active' => $active,
            ], true);
        }
    }

    // ------------------------------------------------------------- matching

    public function testSolarInstallationIsTheSolarCategory(): void
    {
        $this->assertSame('solar-renewable-energy', $this->match('solar installation')[0] ?? null);
    }

    public function testBusinessWordingFindsTheCategoryByItsSynonym(): void
    {
        $this->assertSame('dentist', $this->match('Dental practice')[0] ?? null);
        $this->assertSame('electrician', $this->match('sparky')[0] ?? null);
    }

    public function testTheCategoryNameMatchesByStem(): void
    {
        $this->assertSame('panel-beater', $this->match('panel beating and spray painting')[0] ?? null);
    }

    public function testEveryCategoryNamedIsOffered(): void
    {
        $slugs = $this->match('electrical contractor solar');

        $this->assertContains('electrician', $slugs);
        $this->assertContains('solar-renewable-energy', $slugs);
    }

    public function testAWordThatMeansSeveralOffersThemAll(): void
    {
        $slugs = $this->match('salon');

        $this->assertSame(['hair-salon', 'beauty-salon', 'nail-bar'], $slugs);
    }

    public function testNothingIsOfferedForWordsWeDoNotKnow(): void
    {
        $this->assertSame([], $this->match('spaza shop'));
        $this->assertSame([], $this->match('ab'));
    }

    public function testASwitchedOffCategoryIsNeverOffered(): void
    {
        $this->assertNotContains('wind-turbines', $this->match('wind turbines'));
    }

    public function testLinkedWordingComesFirstOnItsOwnSiteOnly(): void
    {
        $svc = new CategoryMatchService();
        $this->assertTrue($svc->link('za', 'Spaza shop', $this->ids['plumber']));

        $this->assertSame('plumber', $this->match('spaza shop')[0] ?? null);
        $this->assertSame([], array_column((new CategoryMatchService())->match('spaza shop', 3, 'app'), 'slug'));
        $this->assertFalse($svc->link('za', 'x', $this->ids['plumber']), 'too short');
        $this->assertFalse($svc->link('za', 'wind farm', $this->ids['wind-turbines']), 'inactive category');
    }

    public function testEveryKeywordPointsAtASeededCategory(): void
    {
        helper('slug');
        $seeder = file_get_contents(APPPATH . 'Database/Seeds/DirectoryCategoriesSeeder.php');
        preg_match_all("/'([^'\\n]+)'/", $seeder, $m);
        $seeded = array_map('slugify', $m[1]);

        foreach (config('CategoryMatch')->keywords as $phrase => $slugs) {
            foreach ($slugs as $slug) {
                $this->assertContains($slug, $seeded, $phrase . ' points at ' . $slug . ', which is not a seeded category');
            }
        }
    }

    public function testTheEndpointAnswersWithTheBestCategories(): void
    {
        $items = json_decode($this->get('add-profile/categories?q=' . rawurlencode('solar installation'))->getJSON(), true)['items'];

        $this->assertSame('Solar & Renewable Energy', $items[0]['name']);
        $this->assertSame('Home & Trades', $items[0]['group']);
        $this->assertSame($this->ids['solar-renewable-energy'], $items[0]['id']);

        $short = json_decode($this->get('add-profile/categories?q=so')->getJSON(), true)['items'];
        $this->assertSame([], $short);
    }

    public function testTheSignupFormCarriesTheMatcher(): void
    {
        $body = (string) $this->get('add-profile')->getBody();

        $this->assertStringContainsString('data-category-match', $body);
        $this->assertStringContainsString('name="business_kind"', $body);
        $this->assertStringContainsString('What does your business do?', $body);
    }

    // ---------------------------------------------------- what is kept of it

    public function testAMissIsKeptForAdminAndAMatchIsNot(): void
    {
        $svc  = new CategoryMatchService();
        $one  = $this->listing('Corner Spaza');
        $two  = $this->listing('Sun Power');

        $svc->record($one, ['business_kind' => 'Spaza  shop', 'business_kind_offered' => '', 'category_id' => (string) $this->ids['plumber']], 'za');
        $svc->record($one, ['business_kind' => 'spaza shop', 'business_kind_offered' => '', 'category_id' => (string) $this->ids['plumber']], 'za');
        $svc->record($two, ['business_kind' => 'solar', 'business_kind_offered' => (string) $this->ids['solar-renewable-energy'], 'category_id' => (string) $this->ids['solar-renewable-energy']], 'za');
        $svc->record($two, ['business_kind' => '', 'category_id' => '1'], 'za');

        $this->assertSame(2, db_connect()->table('xs_directory_category_matches')->countAllResults(), 'the same words twice are one row; empty words none');

        $misses = $svc->unmatched('za');
        $this->assertCount(1, $misses);
        $this->assertSame('Spaza shop', $misses[0]['text']);
        $this->assertSame('Plumber', $misses[0]['category']);
        $this->assertSame([], $svc->unmatched('app'));
    }

    public function testPurgeRemovesOldWording(): void
    {
        db_connect()->table('xs_directory_category_matches')->insert([
            'site' => 'za', 'text' => 'old', 'text_key' => 'old', 'offered' => '', 'matched' => 0,
            'created_at' => date('Y-m-d H:i:s', strtotime('-200 days')),
        ]);

        $this->assertSame(1, (new CategoryMatchService())->purge());
    }

    public function testTheAdminPageListsMissesAndLinksThem(): void
    {
        (new CategoryMatchService())->record($this->listing('Corner Spaza'), ['business_kind' => 'spaza shop', 'category_id' => (string) $this->ids['plumber']], 'za');
        $session = ['dir_admin' => true, AdminFilter::SEEN_KEY => time()];

        $page = $this->withSession($session)->get('admin/discovery');
        $page->assertOK();
        $this->assertStringContainsString('Business types we could not match', (string) $page->getBody());
        $this->assertStringContainsString('spaza shop', (string) $page->getBody());

        $this->withSession($session)->post('admin/discovery/category-link', [
            csrf_token() => csrf_hash(),
            'site'        => 'za',
            'phrase'      => 'spaza shop',
            'category_id' => (string) $this->ids['plumber'],
        ])->assertRedirect();

        $this->assertSame('plumber', $this->match('spaza shop')[0] ?? null);
        $this->assertSame([], (new CategoryMatchService())->unmatched('za'), 'a linked wording leaves the misses');
    }

    /** @return list<string> slugs offered, best first */
    private function match(string $text): array
    {
        return array_column((new CategoryMatchService())->match($text, 3, 'za'), 'slug');
    }

    private function listing(string $name): int
    {
        helper('slug');

        return (int) (new DirectoryListingModel())->insert([
            'type'         => 'practice',
            'display_name' => $name,
            'email'        => slugify($name) . '@example.test',
            'slug'         => slugify($name),
            'status'       => 'published',
            'category_id'  => $this->ids['plumber'],
            'city'         => 'Johannesburg',
            'province'     => 'Gauteng',
        ], true);
    }
}

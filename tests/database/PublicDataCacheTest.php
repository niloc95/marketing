<?php

use App\Models\DirectoryCategoryModel;
use App\Models\DirectoryListingModel;
use App\Services\DirectoryService;
use App\Services\SiteContext;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Services;
use Tests\Support\FreshDatabase;
use Tests\Support\WithEnv;

/**
 * The counts behind the home, province and country pages are cached for
 * Config\Directory::$publicCacheSeconds (DirectoryService::remember()).
 *
 * The suite runs with 0 (phpunit.dist.xml) so every other test reads the rows
 * it just wrote; these switch the cache on.
 *
 * @internal
 */
final class PublicDataCacheTest extends CIUnitTestCase
{
    use FreshDatabase;
    use WithEnv;

    private int $category;

    protected function setUp(): void
    {
        parent::setUp();
        cache()->clean();

        $this->category = (int) (new DirectoryCategoryModel())->insert([
            'name' => 'Bakeries', 'slug' => 'bakeries', 'group_name' => 'Food & Drink', 'is_active' => 1,
        ], true);
        $this->publish('Cape Crumb Bakery', 'Western Cape', 'Cape Town', 'South Africa');
    }

    protected function tearDown(): void
    {
        $this->restoreEnv();
        Services::resetSingle('site');
        $this->resetFactories();
        cache()->clean();
        parent::tearDown();
    }

    public function testCountsAreServedFromTheCacheUntilItExpires(): void
    {
        config('Directory')->publicCacheSeconds = 60;
        $this->assertSame(['Western Cape' => 1], (new DirectoryService())->provinceCounts());

        $this->publish('Durban Dough', 'KwaZulu-Natal', 'Durban', 'South Africa');

        $this->assertSame(['Western Cape' => 1], (new DirectoryService())->provinceCounts(), 'cached for a minute');

        cache()->clean();
        $counts = (new DirectoryService())->provinceCounts();
        ksort($counts); // a tie, in whichever order MySQL groups it
        $this->assertSame(['KwaZulu-Natal' => 1, 'Western Cape' => 1], $counts);
    }

    public function testZeroSecondsReadsTheDatabaseEveryTime(): void
    {
        config('Directory')->publicCacheSeconds = 0;
        $svc = new DirectoryService();
        $this->assertSame(1, $svc->stats()['listings']);

        $this->publish('Durban Dough', 'KwaZulu-Natal', 'Durban', 'South Africa');

        $this->assertSame(2, $svc->stats()['listings']);
    }

    public function testEachSiteKeepsItsOwnCopy(): void
    {
        $this->setEnv('sites.app.enabled', 'true');
        $this->publish('Thames Loaf', '', 'London', 'United Kingdom', 'England');
        $this->publish('Mersey Muffins', '', 'Liverpool', 'United Kingdom', 'England');
        config('Directory')->publicCacheSeconds = 60;

        $this->onHost('example.com');
        $za = (new DirectoryService())->stats();

        $this->onHost('local.webscheduler.app');
        $app = (new DirectoryService())->stats();

        $this->assertSame(1, $za['listings']);
        $this->assertSame(2, $app['listings'], 'not co.za\'s cached copy');
        $this->assertSame(['England' => 2],(new DirectoryService())->regionCounts('GB'));
        $this->assertSame([], (new DirectoryService())->cityCountsForProvince('Western Cape'), '.app never counts a South African town');

        $this->onHost('example.com');
        $this->assertSame(['Cape Town' => 1], (new DirectoryService())->cityCountsForProvince('Western Cape'));
    }

    private function onHost(string $host): void
    {
        Services::injectMock('site', new SiteContext($host));
    }

    private function publish(string $name, string $province, string $city, string $country, string $region = ''): void
    {
        (new DirectoryListingModel())->insert([
            'type' => 'practice', 'display_name' => $name, 'slug' => slugify($name),
            'email' => slugify($name) . '@example.test', 'status' => 'published', 'category_id' => $this->category,
            'city' => $city, 'province' => $province, 'region' => $region, 'country' => $country,
            'published_at' => date('Y-m-d H:i:s'),
        ]);
    }
}

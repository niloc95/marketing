<?php

use App\Controllers\Directory;
use App\Libraries\ListingGeocoder;
use App\Models\DirectoryCategoryModel;
use App\Models\DirectoryListingModel;
use App\Models\DirectoryPracticeLocationModel;
use CodeIgniter\Config\Services;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * What a scraper can lift without opening a profile, after the Oct 2026
 * hardening:
 *
 * 1. **Email is not in the page.** "Show email" fetches it from
 *    /directory/{slug}/email, and a branch's only on a premium profile.
 * 2. **Map pins carry no phone and no row id.**
 * 3. **robots.txt shuts out AI training crawlers** but not the answer bots.
 *
 * Requires the `tests` database group — see the Tests section of README.md.
 *
 * @internal
 */
final class ScrapeExposureTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $namespace   = 'App';
    protected $migrate     = true;
    protected $migrateOnce = false;
    protected $refresh     = true;

    private int $listingId;

    protected function setUp(): void
    {
        parent::setUp();
        cache()->clean();
        Services::injectMock('throttler', new class () {
            public function check(string $key, int $capacity, int $seconds, int $cost = 1): bool
            {
                return true;
            }

            public function getTokenTime(): int
            {
                return 0;
            }
        });

        $categoryId = (int) (new DirectoryCategoryModel())->insert(['name' => 'Dentist', 'slug' => 'dentist', 'group_name' => 'Health & Medical', 'is_active' => 1], true);

        $this->listingId = (int) (new DirectoryListingModel())->insert([
            'type'           => 'facility',
            'display_name'   => 'Smile Co',
            'email'          => 'owner@smile.example.test',
            'phone'          => '011 555 0000',
            'category_id'    => $categoryId,
            'slug'           => 'smile-co',
            'status'         => 'published',
            'is_verified'    => 1,
            'address_line'   => '1 Main Road',
            'city'           => 'Johannesburg',
            'province'       => 'Gauteng',
            'country'        => 'South Africa',
            'latitude'       => -26.14,
            'longitude'      => 28.04,
            'verified_until' => date('Y-m-d', strtotime('+1 month')),
        ], true);
        (new ListingGeocoder())->syncPoint($this->listingId, ['latitude' => -26.14, 'longitude' => 28.04]);
    }

    protected function tearDown(): void
    {
        Services::resetSingle('throttler');
        parent::tearDown();
    }

    public function testTheProfileHoldsNoEmailInAnyForm(): void
    {
        // Decoded: esc(..., 'attr') encodes "/", and entities would hide the
        // address from a plain string check as well.
        $html = html_entity_decode((string) $this->get('directory/smile-co')->response()->getBody(), ENT_QUOTES | ENT_HTML5);

        $this->assertStringContainsString('Show email', $html);
        $this->assertStringContainsString('/directory/smile-co/email', $html);
        $this->assertStringNotContainsString('owner@smile.example.test', $html);
        $this->assertStringNotContainsString(strrev('owner@smile.example.test'), $html);
    }

    public function testTheEmailEndpointGivesTheAddressAndIsNotIndexed(): void
    {
        $result = $this->get('directory/smile-co/email');

        $result->assertStatus(200);
        $this->assertSame('owner@smile.example.test', json_decode($result->getJSON(), true)['email']);
        $this->assertSame('noindex', $result->response()->getHeaderLine('X-Robots-Tag'));

        $this->get('directory/no-such-place/email')->assertStatus(404);
    }

    public function testABranchEmailNeedsAPremiumProfileAndItsOwnBranch(): void
    {
        $branchId = (int) (new DirectoryPracticeLocationModel())->insert([
            'listing_id' => $this->listingId,
            'name'       => 'Rosebank rooms',
            'email'      => 'branch@smile.example.test',
            'city'       => 'Johannesburg',
            'province'   => 'Gauteng',
            'country'    => 'South Africa',
            'sort_order' => 0,
        ], true);

        $this->assertSame('branch@smile.example.test', json_decode($this->get('directory/smile-co/email?branch=' . $branchId)->getJSON(), true)['email']);
        $this->get('directory/smile-co/email?branch=' . ($branchId + 99))->assertStatus(404);

        // The badge lapses: the branches leave the profile, and so do their emails.
        (new DirectoryListingModel())->update($this->listingId, ['verified_until' => date('Y-m-d', strtotime('-1 day'))]);
        $this->get('directory/smile-co/email?branch=' . $branchId)->assertStatus(404);
    }

    public function testMapPinsCarryNoPhoneOrRowId(): void
    {
        $items = json_decode($this->get('directory/map?bounds=-27,27,-25,29')->getJSON(), true)['items'];

        $this->assertCount(1, $items);
        $this->assertArrayNotHasKey('phone', $items[0]);
        $this->assertArrayNotHasKey('id', $items[0]);
        $this->assertStringEndsWith('/directory/smile-co', $items[0]['url']);
    }

    public function testResultsPagesListNoPhonesInTheirSchema(): void
    {
        $html = (string) $this->get('directory?q=smile')->response()->getBody();

        $this->assertStringContainsString('Smile Co', $html);
        $this->assertStringNotContainsString('+27115550000', $html);
    }

    public function testRobotsShutsOutTrainingCrawlersOnly(): void
    {
        $body = (string) $this->get('robots.txt')->response()->getBody();

        foreach (Directory::AI_TRAINING_BOTS as $bot) {
            $this->assertStringContainsString("User-agent: {$bot}\nDisallow: /\n", $body);
        }
        foreach (['OAI-SearchBot', 'ChatGPT-User', 'PerplexityBot', 'Googlebot'] as $bot) {
            $this->assertStringNotContainsString($bot, $body);
        }
        $this->assertStringContainsString("User-agent: *\nAllow: /\n", $body);
        $this->assertStringContainsString('Disallow: /directory/map', $body);
        $this->assertStringContainsString('Sitemap: ', $body);
    }
}

<?php

use App\Models\DirectoryCategoryModel;
use App\Models\DirectoryListingModel;
use App\Models\DirectoryListingServiceModel;
use App\Models\DirectoryPracticeLocationModel;
use App\Models\DirectoryTagModel;
use App\Services\DirectoryService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use Tests\Support\FreshDatabase;

/**
 * A business is discovered where its branches are, and by what it does.
 *
 * Shaped on two real profiles (26 Oct 2026): PinkDrive, based in Randburg with
 * a Cape Town office, missing from the Western Cape page; and the typeahead
 * offering nothing for "cervical", one of its programmes.
 *
 * Branches carry the same gate as the team: they only render while the badge
 * (or Plus) is live, so they only count while it is.
 *
 * Requires the `tests` database group — see the Tests section of README.md.
 *
 * @internal
 */
final class BranchDiscoveryTest extends CIUnitTestCase
{
    use FreshDatabase;
    use FeatureTestTrait;

    private int $categoryId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->categoryId = (int) (new DirectoryCategoryModel())->insert([
            'name'       => 'Health & HIV Support',
            'slug'       => 'health-hiv-support',
            'group_name' => 'Community & Nonprofit',
            'is_active'  => 1,
        ], true);
    }

    public function testABranchPutsTheBusinessOnThatProvincesPage(): void
    {
        $this->pinkDrive(live: true);

        $items = (new DirectoryService())->browse(['province' => 'Western Cape'])['items'];
        $this->assertSame(['pinkdrive'], array_column($items, 'slug'));
        $this->assertSame('Rondebosch, Cape Town', $items[0]['branch_place']);

        // At home it is simply there, with no "also in" line.
        $home = (new DirectoryService())->browse(['province' => 'Gauteng'])['items'];
        $this->assertSame(['pinkdrive'], array_column($home, 'slug'));
        $this->assertNull($home[0]['branch_place']);
    }

    public function testTheProvincePageCardSaysWhichBranch(): void
    {
        $this->pinkDrive(live: true);

        $result = $this->get('directory/province/western-cape');
        $result->assertOK();
        $this->assertStringContainsString('directory/pinkdrive', $result->getBody());
        $this->assertStringContainsString('Also in Rondebosch, Cape Town', $result->getBody());
    }

    public function testABranchCountsInTheTalliesBesideThePage(): void
    {
        $this->pinkDrive(live: true);
        $svc = new DirectoryService();

        $this->assertSame(1, $svc->provinceCounts()['Western Cape'] ?? 0);
        $this->assertSame(1, $svc->provinceCounts()['Gauteng'] ?? 0);
        $this->assertSame(['Cape Town' => 1], $svc->cityCountsForProvince('Western Cape'));
        $this->assertSame([1], array_column($svc->topCategoriesInProvince('Western Cape'), 'listing_count'));
        $this->assertSame(['Gauteng' => 1, 'Western Cape' => 1], $this->sorted($svc->provinceCountsForCategory($this->categoryId)));
    }

    public function testATownFilterAndAnInterpretedPlaceFindTheBranch(): void
    {
        $this->pinkDrive(live: true);
        $svc = new DirectoryService();

        $this->assertSame(['pinkdrive'], array_column($svc->browse(['city' => 'Cape Town'])['items'], 'slug'));
        $this->assertSame(['pinkdrive'], array_column($svc->browse(['place' => 'Rondebosch'])['items'], 'slug'));
    }

    public function testALapsedBadgeTakesTheBranchOutOfDiscovery(): void
    {
        $this->pinkDrive(live: false);
        $svc = new DirectoryService();

        $this->assertSame([], $svc->browse(['province' => 'Western Cape'])['items']);
        $this->assertSame([], $svc->browse(['city' => 'Cape Town'])['items']);
        $this->assertArrayNotHasKey('Western Cape', $svc->provinceCounts());
        $this->assertSame([], $svc->cityCountsForProvince('Western Cape'));
        // Its own province is untouched.
        $this->assertSame(['pinkdrive'], array_column($svc->browse(['province' => 'Gauteng'])['items'], 'slug'));
    }

    public function testTheMapKeepsToTheProfilesOwnPin(): void
    {
        $this->pinkDrive(live: true);

        $this->assertSame([], (new DirectoryService())->mapPoints(['province' => 'Western Cape']));
    }

    public function testTheTypeaheadOffersABusinessByWhatItDoes(): void
    {
        $id = $this->pinkDrive(live: false);
        (new DirectoryListingServiceModel())->insert(['listing_id' => $id, 'name' => 'Cervical Cancer', 'sort_order' => 0]);
        $tagId = (new DirectoryTagModel())->insert(['name' => 'Mammograms', 'slug' => 'mammograms'], true);
        db_connect()->table('xs_directory_listing_tags')->insert(['listing_id' => $id, 'tag_id' => $tagId]);

        foreach (['cerv' => 'Cervical Cancer', 'cervical' => 'Cervical Cancer', 'mammo' => 'Mammograms'] as $q => $topic) {
            $rows = array_values(array_filter($this->suggest($q), static fn (array $i): bool => $i['type'] === 'listing'));
            $this->assertCount(1, $rows, 'typing "' . $q . '"');
            $this->assertSame('PinkDrive', $rows[0]['label']);
            $this->assertSame($topic . ' · Johannesburg, Gauteng', $rows[0]['sub']);
        }

        // Word starts only: the middle of a word is not a match.
        $this->assertSame([], array_filter($this->suggest('rvical'), static fn (array $i): bool => $i['type'] === 'listing'));
    }

    public function testANameMatchIsNotOfferedTwice(): void
    {
        $id = $this->pinkDrive(live: false);
        (new DirectoryListingServiceModel())->insert(['listing_id' => $id, 'name' => 'PinkDrive mobile units', 'sort_order' => 0]);

        $rows = array_filter($this->suggest('pinkdrive'), static fn (array $i): bool => $i['type'] === 'listing');
        $this->assertCount(1, $rows);
    }

    // -------------------------------------------------------------- helpers

    /**
     * @return list<array{type:string,label:string,sub:string,url:string}>
     */
    private function suggest(string $q): array
    {
        $result = $this->get('directory/suggest?q=' . rawurlencode($q));
        $result->assertOK();

        return json_decode($result->getJSON(), true)['items'];
    }

    /**
     * @param array<string,int> $a
     * @return array<string,int>
     */
    private function sorted(array $a): array
    {
        ksort($a);

        return $a;
    }

    private function pinkDrive(bool $live): int
    {
        $id = (int) (new DirectoryListingModel())->insert([
            'type'           => 'ngo',
            'display_name'   => 'PinkDrive',
            'email'          => 'pink-' . bin2hex(random_bytes(4)) . '@example.test',
            'category_id'    => $this->categoryId,
            'slug'           => 'pinkdrive',
            'status'         => 'published',
            'is_verified'    => 1,
            'suburb'         => 'Randburg',
            'city'           => 'Johannesburg',
            'province'       => 'Gauteng',
            'country'        => 'South Africa',
            'verified_until' => date('Y-m-d', strtotime($live ? '+1 month' : '-1 day')),
        ], true);

        (new DirectoryPracticeLocationModel())->insert([
            'listing_id' => $id,
            'name'       => 'Cape Town Office',
            'suburb'     => 'Rondebosch',
            'city'       => 'Cape Town',
            'province'   => 'Western Cape',
            'country'    => 'South Africa',
            'sort_order' => 0,
        ]);

        return $id;
    }
}

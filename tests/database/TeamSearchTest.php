<?php

use App\Models\DirectoryCategoryModel;
use App\Models\DirectoryListingModel;
use App\Models\DirectoryListingTeamModel;
use App\Services\DirectoryService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * Finding a business by the people in it — search results and the typeahead.
 *
 * Both halves share one rule worth pinning: a team member only counts while the
 * badge that publishes the team panel is live. Without it a search would return
 * a business on the strength of a person the profile no longer shows.
 *
 * Requires the `tests` database group — see the Tests section of README.md.
 *
 * @internal
 */
final class TeamSearchTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $namespace   = 'App';
    protected $migrate     = true;
    protected $migrateOnce = false;
    protected $refresh     = true;

    private DirectoryListingModel $listings;
    private int $categoryId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->listings   = new DirectoryListingModel();
        $this->categoryId = (int) (new DirectoryCategoryModel())->insert([
            'name'       => 'Dentists',
            'slug'       => 'dentists',
            'group_name' => 'Health & Medical',
            'is_active'  => 1,
        ], true);
    }

    public function testSearchingAMembersNameRoleOrQualificationFindsTheBusiness(): void
    {
        $this->practiceWith(['name' => 'Thandiwe Nkosi', 'role' => 'Dental hygienist', 'credentials' => 'BOH (UWC)'], live: true);

        foreach (['Thandiwe', 'hygienist', 'BOH (UWC)'] as $q) {
            $slugs = array_column((new DirectoryService())->browse(['q' => $q])['items'], 'slug');
            $this->assertSame(['bright-smiles'], $slugs, 'searching "' . $q . '"');
        }
    }

    public function testALapsedBadgeTakesTheTeamOutOfSearch(): void
    {
        $this->practiceWith(['name' => 'Thandiwe Nkosi', 'role' => 'Dental hygienist'], live: false);

        $this->assertSame([], (new DirectoryService())->browse(['q' => 'Thandiwe'])['items']);
        $this->assertSame([], (new DirectoryService())->browse(['q' => 'hygienist'])['items']);
    }

    public function testTheTypeaheadOffersAMemberAndLinksToTheirCard(): void
    {
        $this->practiceWith(['name' => 'Thandiwe Nkosi', 'slug' => 'thandiwe-nkosi', 'role' => 'Dental hygienist'], live: true);

        $person = null;
        foreach ($this->suggest('thandi') as $item) {
            if ($item['type'] === 'person') {
                $person = $item;
            }
        }

        $this->assertNotNull($person, 'no person row offered');
        $this->assertSame('Thandiwe Nkosi', $person['label']);
        $this->assertSame('Dental hygienist · Bright Smiles', $person['sub']);
        $this->assertStringEndsWith('/directory/bright-smiles#team-thandiwe-nkosi', $person['url']);
    }

    public function testTheTypeaheadLeavesOutTheTeamOfALapsedBadge(): void
    {
        $this->practiceWith(['name' => 'Thandiwe Nkosi', 'slug' => 'thandiwe-nkosi'], live: false);

        $this->assertSame([], array_values(array_filter(
            $this->suggest('thandi'),
            static fn (array $i): bool => $i['type'] === 'person'
        )));
    }

    public function testTheProfileCardCarriesTheAnchorTheTypeaheadLinksTo(): void
    {
        $this->practiceWith(['name' => 'Thandiwe Nkosi', 'slug' => 'thandiwe-nkosi'], live: true);

        $result = $this->get('directory/bright-smiles');
        $result->assertOK();
        $body = $result->getBody();
        $this->assertStringContainsString('id="team-thandiwe-nkosi"', $body);
        // ...and the JSON-LD Person points at the same place, and back at the business.
        $this->assertStringContainsString('bright-smiles#team-thandiwe-nkosi', $body);
        $this->assertStringContainsString('"worksFor"', $body);
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

    /** @param array<string,mixed> $member */
    private function practiceWith(array $member, bool $live): int
    {
        $id = (int) $this->listings->insert([
            'type'           => 'practice',
            'display_name'   => 'Bright Smiles',
            'email'          => 'team-' . bin2hex(random_bytes(4)) . '@example.test',
            'category_id'    => $this->categoryId,
            'slug'           => 'bright-smiles',
            'status'         => 'published',
            'is_verified'    => 1,
            'city'           => 'Cape Town',
            'province'       => 'Western Cape',
            'verified_until' => date('Y-m-d', strtotime($live ? '+1 month' : '-1 day')),
        ], true);

        (new DirectoryListingTeamModel())->insert($member + [
            'listing_id' => $id,
            'slug'       => 'member-' . bin2hex(random_bytes(3)),
            'sort_order' => 0,
        ]);

        return $id;
    }
}

<?php

use App\Controllers\Manage;
use App\Models\DirectoryCategoryModel;
use App\Models\DirectoryListingModel;
use App\Models\DirectoryListingTeamModel;
use App\Models\DirectoryPracticeLocationModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Tests\Support\ValidListingInput;

/**
 * A signed in owner who hand crafts the manage/edit POST.
 *
 * Anyone can create a profile and redeem its manage link, so the edit form is
 * reachable by an attacker with a real session. What this pins, end to end
 * through the controller:
 *
 *  - the listing written is the one in the session, whatever id the POST names;
 *  - nothing outside OWNER_EDITABLE is written, however the POST is padded;
 *  - SQL in a field is stored as text, not run;
 *  - a team member or branch id belonging to another listing never updates it.
 *
 * Requires the `tests` database group — see the Tests section of README.md.
 *
 * @internal
 */
final class OwnerEditTamperTest extends CIUnitTestCase
{
    use ValidListingInput;
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $namespace   = 'App';
    protected $migrate     = true;
    protected $migrateOnce = false;
    protected $refresh     = true;

    /** Columns an owner must never be able to set, with the forged value posted for each. */
    private const FORGED = [
        'status'          => 'draft',
        'is_verified'     => '1',
        'verified_until'  => '2099-12-31',
        'is_featured'     => '1',
        'slug'            => 'hijacked-slug',
        'email'           => 'attacker@evil.test',
        'country'         => 'Germany',
        'manage_token'    => 'forged-token',
        'reviews_enabled' => '0',
        'venue_id'        => '999',
    ];

    private DirectoryListingModel $listings;
    private int $categoryId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->listings   = new DirectoryListingModel();
        $this->categoryId = (int) (new DirectoryCategoryModel())->insert([
            'name' => 'Law Firms', 'slug' => 'law-firms',
            'group_name' => 'Professional', 'is_active' => 1,
        ], true);

        // Not what is under test, and it cannot be satisfied from here — the
        // token is cookie-bound. Same reasoning as PhotoDeleteAjaxTest.
        $filters = config(\Config\Filters::class);
        unset($filters->globals['before']['csrf']);
        \CodeIgniter\Config\Factories::injectMock('config', 'Filters', $filters);
    }

    public function testPaddedPostWritesOnlyOwnerEditableColumns(): void
    {
        $mine   = $this->listing();
        $before = $this->listings->find($mine);

        $this->edit($mine, ['display_name' => 'Renamed Attorneys'] + self::FORGED + [
            'description_text' => 'forged plain text',
            // Recomputed by the server after every save, so it may move, just
            // never to what was posted.
            'quality_score'    => '100',
        ]);

        $after = $this->listings->find($mine);
        $this->assertSame('Renamed Attorneys', $after['display_name'], 'Guard: the save should have gone through.');
        foreach (array_keys(self::FORGED) as $column) {
            $this->assertSame($before[$column], $after[$column], "A posted {$column} was written.");
        }
        $this->assertNotSame('forged plain text', $after['description_text']);
        $this->assertNotSame('100', (string) $after['quality_score']);
    }

    public function testAPostedIdCannotRedirectTheSaveToAnotherListing(): void
    {
        $mine   = $this->listing();
        $theirs = $this->listing(['display_name' => 'Victim Attorneys', 'slug' => 'victim', 'email' => 'victim@example.test']);
        $before = $this->listings->find($theirs);

        $this->edit($mine, [
            'display_name' => 'Renamed Attorneys',
            'id'           => (string) $theirs,
            'listing_id'   => (string) $theirs,
        ]);

        $this->assertSame('Renamed Attorneys', $this->listings->find($mine)['display_name']);
        $this->assertSame($before, $this->listings->find($theirs));
    }

    public function testSqlInAFieldIsStoredAsText(): void
    {
        $mine     = $this->listing();
        $theirs   = $this->listing(['display_name' => 'Victim Attorneys', 'slug' => 'victim', 'email' => 'victim@example.test']);
        $payload  = "1' OR '1'='1'; DROP TABLE xs_directory_listings; --";
        $count    = $this->listings->countAllResults();

        $this->edit($mine, ['address_line_2' => $payload]);

        $this->assertSame($payload, $this->listings->find($mine)['address_line_2']);
        $this->assertSame($count, $this->listings->countAllResults());
        $this->assertSame('', (string) $this->listings->find($theirs)['address_line_2']);
    }

    public function testAnotherListingsTeamMemberAndBranchIdsAreNeverUpdated(): void
    {
        // Verified, so the team and branch syncs actually run.
        $mine   = $this->listing(['verified_until' => date('Y-m-d', strtotime('+1 year'))]);
        $theirs = $this->listing([
            'display_name' => 'Victim Attorneys', 'slug' => 'victim', 'email' => 'victim@example.test',
            'verified_until' => date('Y-m-d', strtotime('+1 year')),
        ]);

        $team      = new DirectoryListingTeamModel();
        $locations = new DirectoryPracticeLocationModel();
        $member    = (int) $team->insert(['listing_id' => $theirs, 'name' => 'Victim Partner', 'slug' => 'victim-partner'], true);
        $branch    = (int) $locations->insert(['listing_id' => $theirs, 'name' => 'Victim Branch', 'city' => 'Durban'], true);
        $memberBefore = $team->find($member);
        $branchBefore = $locations->find($branch);

        $this->edit($mine, [
            'team'      => [['id' => (string) $member, 'name' => 'Hijacked Name']],
            'locations' => [['id' => (string) $branch, 'name' => 'Hijacked Branch', 'city' => 'Cape Town']],
        ]);

        $this->assertSame($memberBefore, $team->find($member));
        $this->assertSame($branchBefore, $locations->find($branch));
        // The rows the attacker typed land on their own listing, as new rows.
        $this->assertSame(['Hijacked Name'], array_column($team->where('listing_id', $mine)->findAll(), 'name'));
        $this->assertSame(['Hijacked Branch'], array_column($locations->where('listing_id', $mine)->findAll(), 'name'));
    }

    public function testNoSessionMeansNoWrite(): void
    {
        $theirs = $this->listing();
        $before = $this->listings->find($theirs);

        $this->post('manage/edit', $this->form(['display_name' => 'Hijacked', 'id' => (string) $theirs]))
            ->assertRedirectTo(base_url('manage'));

        $this->assertSame($before, $this->listings->find($theirs));
    }

    // -------------------------------------------------------------- fixtures

    /** @param array<string,mixed> $overrides */
    private function listing(array $overrides = []): int
    {
        return (int) $this->listings->insert($overrides + [
            'type'           => 'practice',
            'display_name'   => 'Hands & Co Attorneys',
            'email'          => 'hands@example.test',
            'slug'           => 'hands-co',
            'status'         => 'published',
            'country'        => 'South Africa',
            'category_id'    => $this->categoryId,
            'quality_score'  => 42,
            // A stored logo: an owner save needs a picture — see
            // HandlesListingUploads::hasPhoto().
            'logo_path'      => 'assets/listings/existing-logo.webp',
        ], true);
    }

    /** @param array<string,mixed> $fields */
    private function edit(int $listingId, array $fields): void
    {
        $this->withSession([Manage::SESSION_KEY => $listingId])
            ->post('manage/edit', $this->form($fields))
            ->assertRedirectTo(base_url('manage/edit'));
    }

    /**
     * A valid owner submission. The coordinates stop ListingGeocoder reaching
     * Nominatim over the network — see SignupDuplicateTest::input().
     *
     * @param array<string,mixed> $fields
     *
     * @return array<string,mixed>
     */
    private function form(array $fields): array
    {
        return $this->withRequiredSections($fields + [
            'display_name'   => 'Hands & Co Attorneys',
            'contact_person' => 'Jane Hands',
            'title'          => 'Ms',
            'category_id'    => $this->categoryId,
            'latitude'       => '-33.9249',
            'longitude'      => '18.4241',
        ]);
    }
}

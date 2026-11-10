<?php

use App\Models\Bookings\BookingProviderModel;
use App\Models\DirectoryCategoryModel;
use App\Models\DirectoryListingModel;
use App\Models\DirectoryListingTeamModel;
use App\Services\Bookings\BookingSetupService;
use App\Services\Bookings\BusinessSession;
use App\Services\Bookings\PublicBookingService;
use App\Services\Bookings\SetupCheck;
use App\Services\Bookings\TeamService;
use App\Services\DirectoryListingMutationService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use Tests\Support\FreshDatabase;
use Tests\Support\WithEnv;

/**
 * One person, one record (decision T31, owner 10 Nov 2026): the profile's
 * team card is the person, and a booking provider is that card plus the
 * booking parts.
 *
 * Pinned:
 *
 * 1. **Bookings is never free**: a profile without the badge (or Plus)
 *    cannot turn it on, and a lapsed badge stops public booking but keeps
 *    every row.
 * 2. **Adding someone in Bookings adds their card**, hidden from the profile
 *    until ticked, with the photo on the card.
 * 3. **The profile form reaches Bookings**: a rename is the provider's new
 *    name, "Takes bookings" adds or pauses a person, and Remove on someone
 *    Bookings still has only hides their card.
 * 4. **One provider per card**, and never another business's card.
 * 5. **Turning on links the owner's own card** instead of making a second
 *    person.
 *
 * @internal
 */
final class TeamPeopleTest extends CIUnitTestCase
{
    use FreshDatabase;
    use FeatureTestTrait;
    use WithEnv;

    private DirectoryListingModel $listings;

    private int $categoryId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setEnv('bookings.enabled', 'true');
        $this->listings   = new DirectoryListingModel();
        $this->categoryId = (int) (new DirectoryCategoryModel())->insert([
            'name' => 'Hair Salon', 'slug' => 'hair-salon', 'group_name' => 'Beauty & Wellness', 'is_active' => 1,
        ], true);
    }

    public function testAFreeProfileCannotTurnBookingsOn(): void
    {
        $id      = $this->listing('free-salon', ['verified_until' => null]);
        $listing = $this->listings->find($id);

        $this->assertSame('Online bookings come with the Verified Business badge.', (new BookingSetupService())->whyNot($listing));
        $this->expectException(DomainException::class);
        (new BookingSetupService())->turnOn($listing);
    }

    public function testALapsedBadgeStopsBookingsAndKeepsEverything(): void
    {
        $id     = $this->business('salon-lumi');
        $before = BookingProviderModel::forBusiness($id)->countAllResults();
        $this->listings->update($id, ['verified_until' => date('Y-m-d', strtotime('-1 day'))]);
        $listing = $this->listings->find($id);

        $this->assertSame('', PublicBookingService::bookUrl($listing));
        $this->assertSame($before, BookingProviderModel::forBusiness($id)->countAllResults(), 'nothing deleted');
        $this->assertContains('premium_ended', array_column(SetupCheck::problems($id), 'code'));
    }

    public function testAddingSomeoneInBookingsAddsAHiddenCardWithTheirPhoto(): void
    {
        $id     = $this->business('salon-lumi');
        $result = (new TeamService($id))->saveProvider(null, [
            'name' => 'Paul Smith', 'role' => 'Front office', 'photo_path' => 'assets/listings/team/paul.webp', 'bookable' => 0,
        ], null);

        $provider = (new TeamService($id))->provider((int) $result['id']);
        $card     = (new DirectoryListingTeamModel())->find((int) $provider['team_member_id']);
        $this->assertSame('Paul Smith', $card['name']);
        $this->assertSame('Front office', $card['role']);
        $this->assertSame('Front office', $provider['title'], 'the provider keeps a copy');
        $this->assertSame('assets/listings/team/paul.webp', $card['photo_path']);
        $this->assertSame('0', (string) $card['show_on_profile'], 'hidden until ticked');
        $this->assertNotContains('Paul Smith', array_column((new DirectoryListingTeamModel())->forProfile($id), 'name'));
    }

    public function testThePersonPageEditsTheCard(): void
    {
        [$id, $owner] = $this->businessWithOwner('salon-lumi');
        $providerId   = (int) (new TeamService($id))->providers()[0]['id'];

        $this->signedIn($owner, $id)->post('bookings/team/' . $providerId, [
            csrf_token() => csrf_hash(), 'name' => 'Thandi Mokoena', 'role' => 'Senior stylist', 'credentials' => 'Diploma in Hairdressing',
            'bio' => 'Colour and cuts.', 'show_present' => '1', 'show_on_profile' => '1', 'color' => 'teal', 'bookable' => '1',
        ])->assertRedirect();

        $provider = (new TeamService($id))->provider($providerId);
        $card     = (new DirectoryListingTeamModel())->find((int) $provider['team_member_id']);
        $this->assertSame('Diploma in Hairdressing', $card['credentials']);
        $this->assertSame('1', (string) $card['show_on_profile']);
        $this->assertSame('Senior stylist', $provider['title']);
        $this->assertSame('teal', $provider['color']);
    }

    public function testTheProfileFormRenamesThePersonInBookings(): void
    {
        $id       = $this->business('salon-lumi');
        $provider = (new TeamService($id))->providers()[0];

        $this->syncTeam($id, [['id' => $provider['team_member_id'], 'name' => 'Thandi M. Mokoena', 'role' => 'Owner']]);

        $after = (new TeamService($id))->provider((int) $provider['id']);
        $this->assertSame('Thandi M. Mokoena', $after['name']);
        $this->assertSame('Owner', $after['title']);
    }

    public function testTakesBookingsOnTheProfileFormAddsAndPausesAPerson(): void
    {
        $id = $this->business('salon-lumi');

        $ids  = $this->syncTeam($id, [['name' => 'Lerato Dube', 'role' => 'Stylist', 'bookings_present' => '1', 'takes_bookings' => '1']]);
        $team = new TeamService($id);
        $lerato = $team->providerForCard((int) $ids[0]);
        $this->assertNotNull($lerato, 'ticked: now in bookings');
        $this->assertSame('1', (string) $lerato['bookable']);

        $this->syncTeam($id, [['id' => $ids[0], 'name' => 'Lerato Dube', 'bookings_present' => '1']]);
        $this->assertSame('0', (string) $team->providerForCard((int) $ids[0])['bookable'], 'unticked: kept, not bookable');
    }

    public function testRemoveOnTheProfileOnlyHidesSomeoneBookingsStillHas(): void
    {
        $id     = $this->business('salon-lumi');
        $result = (new TeamService($id))->saveProvider(null, ['name' => 'Ben Dlamini', 'show_present' => '1', 'show_on_profile' => '1'], null);
        $cardId = (int) (new TeamService($id))->provider((int) $result['id'])['team_member_id'];

        $this->syncTeam($id, [['id' => $cardId, 'name' => 'Ben Dlamini', '_remove' => '1']]);

        $card = (new DirectoryListingTeamModel())->find($cardId);
        $this->assertNotNull($card, 'still there');
        $this->assertSame('0', (string) $card['show_on_profile']);
        $this->assertSame($cardId, (int) (new TeamService($id))->provider((int) $result['id'])['team_member_id']);
    }

    public function testOneProviderPerCardAndNeverAnotherBusinesssCard(): void
    {
        $id    = $this->business('salon-lumi');
        $other = $this->business('other-salon');
        $taken = (int) (new TeamService($id))->providers()[0]['team_member_id'];
        $theirs = (int) (new TeamService($other))->providers()[0]['team_member_id'];

        $again = (new TeamService($id))->saveProvider(null, ['team_member_id' => $taken], null);
        $this->assertArrayHasKey('team_member_id', $again['errors']);

        $foreign = (new TeamService($id))->saveProvider(null, ['team_member_id' => $theirs], null);
        $this->assertArrayHasKey('team_member_id', $foreign['errors']);
        $this->assertSame(1, BookingProviderModel::forBusiness($other)->where('team_member_id', $theirs)->countAllResults());
    }

    public function testTurningOnLinksTheOwnersCard(): void
    {
        $id   = $this->listing('salon-lumi');
        $card = (int) (new DirectoryListingTeamModel())->insert([
            'listing_id' => $id, 'name' => 'Thandi Mokoena', 'slug' => 'thandi-mokoena', 'role' => 'Owner', 'sort_order' => 0,
        ], true);
        (new BookingSetupService())->turnOn($this->listings->find($id));

        $providers = (new TeamService($id))->providers();
        $this->assertCount(1, $providers);
        $this->assertSame($card, (int) $providers[0]['team_member_id']);
        $this->assertSame(1, (new DirectoryListingTeamModel())->where('listing_id', $id)->countAllResults(), 'no second card');
    }

    public function testThePagesShowOnePersonForm(): void
    {
        [$id, $owner] = $this->businessWithOwner('salon-lumi');
        $card         = (int) (new DirectoryListingTeamModel())->insert([
            'listing_id' => $id, 'name' => 'Bernice Green', 'slug' => 'bernice-green', 'role' => 'Reception', 'sort_order' => 1,
        ], true);
        $providerId = (int) (new TeamService($id))->providers()[0]['id'];

        $team = $this->signedIn($owner, $id)->get('bookings/team');
        $team->assertOK();
        $team->assertSee('Add a person');
        $team->assertSee('On your profile, not in bookings');
        $this->assertStringContainsString('name="team_member_id" value="' . $card . '"', $team->getBody());

        $person = $this->signedIn($owner, $id)->get('bookings/team/' . $providerId);
        $person->assertOK();
        $person->assertSee('Show on my profile');
        $person->assertSee('Qualifications');
        $this->assertStringContainsString('enctype="multipart/form-data"', $person->getBody());

        $edit = $this->withSession([App\Controllers\Manage::SESSION_KEY => $id])->get('manage/edit')->getBody();
        $this->assertStringContainsString('team[0][takes_bookings]', $edit);
        $this->assertStringContainsString('Show on my profile', $edit);
    }

    // Fixtures

    /**
     * The profile form's team save, as Manage::update() runs it.
     *
     * @param list<array<string,mixed>> $rows
     *
     * @return array<int|string,int> form index => card id
     */
    private function syncTeam(int $id, array $rows): array
    {
        $mutations = new DirectoryListingMutationService();
        $result    = $mutations->syncChildRows($id, $this->listings->find($id), ['team' => $rows]);
        $this->assertSame([], $result['errors']);

        $byName = array_column((new DirectoryListingTeamModel())->forListing($id), 'id', 'name');

        return array_map(static fn (array $r): int => (int) ($byName[$r['name']] ?? 0), $rows);
    }

    /** @param array<string,mixed> $overrides */
    private function listing(string $slug, array $overrides = []): int
    {
        return (int) $this->listings->insert($overrides + [
            'type'             => 'practice',
            'display_name'     => ucwords(str_replace('-', ' ', $slug)),
            'contact_person'   => 'Thandi Mokoena',
            'email'            => $slug . '@example.test',
            'slug'             => $slug,
            'status'           => 'published',
            'verified_until'   => '2099-12-31',
            'country'          => 'South Africa',
            'category_id'      => $this->categoryId,
            'description'      => '<p>A friendly hair salon in the heart of Rosebank, open six days a week.</p>',
            'description_text' => 'A friendly hair salon in the heart of Rosebank, open six days a week.',
        ], true);
    }

    private function business(string $slug): int
    {
        return $this->businessWithOwner($slug)[0];
    }

    /** @return array{0:int,1:int} listing id, owner account id */
    private function businessWithOwner(string $slug): array
    {
        $id      = $this->listing($slug);
        $account = (new BookingSetupService())->turnOn($this->listings->find($id));

        return [$id, (int) $account['id']];
    }

    private function signedIn(int $accountId, int $listingId): self
    {
        return $this->withSession([
            BusinessSession::ACCOUNT => $accountId,
            BusinessSession::LISTING => $listingId,
            BusinessSession::SEEN    => time(),
        ]);
    }
}

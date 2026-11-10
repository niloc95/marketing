<?php

use App\Models\Bookings\BookingBlockedTimeModel;
use App\Models\Bookings\BookingProviderModel;
use App\Models\Bookings\BookingServiceModel;
use App\Models\Bookings\BusinessMemberModel;
use App\Models\DirectoryCategoryModel;
use App\Models\DirectoryListingModel;
use App\Models\DirectoryPracticeLocationModel;
use App\Services\Bookings\AccountService;
use App\Services\Bookings\BookingSetupService;
use App\Services\Bookings\BusinessSession;
use App\Services\Bookings\CatalogueService;
use App\Services\Bookings\SetupCheck;
use App\Services\Bookings\TeamService;
use App\Services\Bookings\TimeOffService;
use CodeIgniter\Events\Events;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;
use Tests\Support\FreshDatabase;
use Tests\Support\WithEnv;

/**
 * Phase 2 of online bookings: services, the team, working hours, time off
 * and invitations (docs/bookings/).
 *
 * Pinned:
 *
 * 1. **A one person business is ready at once**: turning bookings on creates
 *    a provider named after the business, on the profile's opening hours.
 * 2. **Bookings' rules, ported**: service length, price in cents, deposit
 *    needs a price; hours with breaks inside the day; nothing half saved.
 * 3. **Nothing crosses businesses**: ids from another business are refused
 *    or dropped, for services, providers, branches and time off.
 * 4. **Time off is entered in the business's time and stored in UTC.**
 * 5. **Invitations** are hashed, one use, joined only by the form, and the
 *    last admin cannot be demoted or removed.
 * 6. **Roles**: a team member manages only themselves; reception cannot
 *    open the team.
 *
 * @internal
 */
final class BookingCatalogueTest extends CIUnitTestCase
{
    use FreshDatabase;
    use FeatureTestTrait;
    use WithEnv;

    private DirectoryListingModel $listings;

    private int $categoryId;

    /** @var list<array<string,mixed>> */
    private array $sent = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setEnv('bookings.enabled', 'true');
        $this->listings   = new DirectoryListingModel();
        $this->categoryId = (int) (new DirectoryCategoryModel())->insert([
            'name' => 'Hair Salon', 'slug' => 'hair-salon', 'group_name' => 'Beauty & Wellness', 'is_active' => 1,
        ], true);
        $this->sent = [];
        Events::on('email', function (array $archive): void {
            $this->sent[] = $archive;
        });
    }

    protected function tearDown(): void
    {
        Events::removeAllListeners('email');
        Services::reset(true);
        $this->restoreEnv();
        parent::tearDown();
    }

    // 1. Ready at once

    public function testTurningOnCreatesAProviderOnTheProfileHours(): void
    {
        [$id, $accountId] = $this->business();

        $providers = (new TeamService($id))->providers();
        $this->assertCount(1, $providers);
        $this->assertSame('Salon Lumi', $providers[0]['name']);

        $owner = BusinessMemberModel::forBusiness($id)->where('account_id', $accountId)->first();
        $this->assertSame((int) $owner['id'], (int) $providers[0]['member_id']);

        $week = (new TeamService($id))->hours((int) $providers[0]['id']);
        $this->assertTrue($week[1]['active']);
        $this->assertSame('08:30', $week[1]['start']);
        $this->assertSame('17:00', $week[1]['end']);
        $this->assertFalse($week[7]['active'], 'Closed on Sunday in the profile');
    }

    // 2. Rules

    public function testServiceRules(): void
    {
        [$id] = $this->business();
        $catalog = new CatalogueService($id);

        $bad = $catalog->saveService(null, ['name' => 'Cut', 'duration_min' => 7, 'price' => 'cheap', 'deposit_pct' => 10, 'delivery_modes' => []], null);
        $this->assertArrayHasKey('duration_min', $bad['errors']);
        $this->assertArrayHasKey('price', $bad['errors']);
        $this->assertArrayHasKey('delivery_modes', $bad['errors']);

        $noPrice = $catalog->saveService(null, ['name' => 'Cut', 'duration_min' => 30, 'deposit_pct' => 20, 'delivery_modes' => ['onsite']], null);
        $this->assertSame('A deposit needs a price.', $noPrice['errors']['deposit_pct']);

        $ok = $catalog->saveService(null, ['name' => 'Cut and blow dry', 'duration_min' => 45, 'price' => '350,50', 'delivery_modes' => ['onsite'], 'buffer_after' => 10], null);
        $this->assertSame([], $ok['errors']);
        $row = $catalog->service((int) $ok['id']);
        $this->assertSame(35050, (int) $row['price_cents']);
        $this->assertSame('cut-and-blow-dry', $row['slug']);
        $this->assertSame(10, (int) $row['buffer_after']);

        $again = $catalog->saveService(null, ['name' => 'Cut and blow dry', 'duration_min' => 45, 'delivery_modes' => ['onsite']], null);
        $this->assertSame('cut-and-blow-dry-2', $catalog->service((int) $again['id'])['slug']);
    }

    public function testArchivedServicesStayButLeaveTheList(): void
    {
        [$id] = $this->business();
        $catalog = new CatalogueService($id);
        $sid     = (int) $catalog->saveService(null, ['name' => 'Colour', 'duration_min' => 90, 'delivery_modes' => ['onsite']], null)['id'];

        $catalog->archiveService($sid, null);
        $this->assertSame([], $catalog->services());
        $this->assertNotNull($catalog->service($sid));
    }

    public function testHoursRulesSaveAllOrNothing(): void
    {
        [$id] = $this->business();
        $team       = new TeamService($id);
        $providerId = (int) $team->providers()[0]['id'];
        $before     = $team->hours($providerId);

        $errors = $team->saveHours($providerId, [
            1 => ['active' => 1, 'start' => '09:00', 'end' => '17:00', 'break_start' => '12:00', 'break_end' => '13:00'],
            2 => ['active' => 1, 'start' => '17:00', 'end' => '09:00'],
            3 => ['active' => 1, 'start' => '09:00', 'end' => '17:00', 'break_start' => '08:00', 'break_end' => '08:30'],
            4 => ['active' => 1, 'start' => '09:00', 'end' => '17:00', 'break_start' => '12:00'],
        ], null);

        $this->assertSame([2, 3, 4], array_keys($errors));
        $this->assertSame($before, $team->hours($providerId), 'Nothing saved while one day is wrong');

        $this->assertSame([], $team->saveHours($providerId, [
            1 => ['active' => 1, 'start' => '9:00', 'end' => '17:00', 'break_start' => '12:00', 'break_end' => '13:00'],
        ], null));
        $week = $team->hours($providerId);
        $this->assertSame(['09:00', '12:00', '13:00'], [$week[1]['start'], $week[1]['break_start'], $week[1]['break_end']]);
        $this->assertFalse($week[2]['active']);
    }

    // 3. Nothing crosses businesses

    public function testIdsFromAnotherBusinessAreRefusedOrDropped(): void
    {
        [$a] = $this->business();
        [$b] = $this->business(['slug' => 'other-salon', 'email' => 'b@example.test', 'display_name' => 'Other Salon']);

        $bProvider = (int) (new TeamService($b))->providers()[0]['id'];
        $bService  = (int) (new CatalogueService($b))->saveService(null, ['name' => 'Cut', 'duration_min' => 30, 'delivery_modes' => ['onsite']], null)['id'];
        $bBranch   = (int) (new DirectoryPracticeLocationModel())->insert(['listing_id' => $b, 'name' => 'Sandton', 'country' => 'South Africa']);

        $catalogA = new CatalogueService($a);
        $this->assertNotSame([], $catalogA->saveService($bService, ['name' => 'Stolen', 'duration_min' => 30, 'delivery_modes' => ['onsite']], null)['errors']);
        $this->assertSame('Cut', BookingServiceModel::forBusiness($b)->find($bService)['name']);

        $own = (int) $catalogA->saveService(null, ['name' => 'Cut', 'duration_min' => 30, 'delivery_modes' => ['onsite'], 'provider_ids' => [$bProvider]], null)['id'];
        $this->assertSame([], $catalogA->service($own)['provider_ids'], "Another business's provider is dropped");
        $this->assertSame('cut', $catalogA->service($own)['slug'], 'Slugs are unique per business, not across them');

        $teamA = new TeamService($a);
        $aProvider = (int) $teamA->providers()[0]['id'];
        $this->assertNotSame([], $teamA->saveHours($aProvider, [1 => ['active' => 1, 'start' => '09:00', 'end' => '17:00', 'location_id' => $bBranch]], null));
        $this->assertNotSame([], $teamA->saveHours($bProvider, [1 => ['active' => 1, 'start' => '09:00', 'end' => '17:00']], null));

        $timeOff = new TimeOffService($a, 'Africa/Johannesburg');
        $this->assertArrayHasKey('provider_id', $timeOff->add($bProvider, '2030-01-01T09:00', '2030-01-01T10:00', '', null)['errors']);
    }

    // 4. Time off

    public function testTimeOffIsStoredInUtc(): void
    {
        [$id] = $this->business();
        $timeOff = new TimeOffService($id, 'Africa/Johannesburg');

        $this->assertArrayHasKey('start', $timeOff->add(null, '2030-12-24T13:00', '2030-12-24T12:00', '', null)['errors']);

        $added = $timeOff->add(null, '2030-12-24T13:00', '2030-12-26T08:00', 'Christmas', null);
        $row   = BookingBlockedTimeModel::forBusiness($id)->find((int) $added['id']);
        $this->assertSame('2030-12-24 11:00:00', $row['start_at']);
        $this->assertNull($row['provider_id']);
        $this->assertSame('2030-12-24 13:00', $timeOff->upcoming()[0]['start_local']);
    }

    // 5. Invitations

    public function testAnInvitationIsJoinedOnlyByItsForm(): void
    {
        [$id, $ownerId] = $this->business();
        $providerId = (int) (new TeamService($id))->saveProvider(null, ['name' => 'Lerato'], null)['id'];

        $this->signedIn($ownerId, $id)->post('business/team/invite', [
            csrf_token() => csrf_hash(), 'name' => 'Lerato', 'email' => 'lerato@example.test', 'role' => 'provider', 'provider_id' => $providerId,
        ])->assertRedirectTo(base_url('business/team#access'));

        $this->assertCount(1, $this->sent);
        preg_match('#business/invite/([a-f0-9]{64})#', html_entity_decode((string) $this->sent[0]['body']), $m);
        $token = $m[1];

        $member = BusinessMemberModel::forBusiness($id)->where('status', 'invited')->first();
        $this->assertSame(hash('sha256', $token), $member['invite_token_hash']);
        $this->assertSame((int) $member['id'], (int) BookingProviderModel::forBusiness($id)->find($providerId)['member_id']);

        $this->get('business/invite/' . $token)->assertSee('Join Salon Lumi');
        $this->assertSame('invited', BusinessMemberModel::forBusiness($id)->find($member['id'])['status']);

        $this->post('business/invite/' . $token, [csrf_token() => csrf_hash(), 'password' => 'short'])
            ->assertRedirectTo(base_url('business/invite/' . $token));

        $joined = $this->post('business/invite/' . $token, [csrf_token() => csrf_hash(), 'password' => 'a good long password']);
        $joined->assertRedirectTo(base_url('business'));
        $joined->assertSessionHas(BusinessSession::LISTING, $id);
        $this->assertSame('active', BusinessMemberModel::forBusiness($id)->find($member['id'])['status']);

        $this->get('business/invite/' . $token)->assertSee('This invitation has expired');
    }

    public function testTheLastAdminStays(): void
    {
        [$id, $ownerId] = $this->business();
        $team  = new TeamService($id);
        $owner = BusinessMemberModel::forBusiness($id)->where('account_id', $ownerId)->first();

        $this->assertSame('The business needs at least one admin.', $team->changeRole((int) $owner['id'], 'staff', null));
        $this->assertSame('The business needs at least one admin.', $team->removeMember((int) $owner['id'], null));
    }

    // 6. Roles

    public function testATeamMemberManagesOnlyThemselves(): void
    {
        [$id] = $this->business();
        $team    = new TeamService($id);
        $account = (new AccountService())->findOrCreate('lerato@example.test', 'Lerato');
        $member  = (int) BusinessMemberModel::forBusiness($id)->insert(['account_id' => $account['id'], 'role' => 'provider', 'status' => 'active']);
        $owners  = (int) $team->providers()[0]['id'];
        $mine    = (int) $team->saveProvider(null, ['name' => 'Lerato', 'member_id' => $member], null)['id'];

        $as = $this->signedIn((int) $account['id'], $id);
        $as->get('business/team/' . $mine)->assertOK();
        $as->get('business/team/' . $owners)->assertStatus(404);
        $this->signedIn((int) $account['id'], $id)->post('business/team/invite', [csrf_token() => csrf_hash(), 'name' => 'X', 'email' => 'x@example.test', 'role' => 'admin'])->assertStatus(403);

        $this->signedIn((int) $account['id'], $id)->post('business/time-off', [
            csrf_token() => csrf_hash(), 'provider_id' => $owners, 'start' => '2030-01-01T09:00', 'end' => '2030-01-01T10:00',
        ])->assertRedirectTo(base_url('business/time-off'));
        $this->assertSame(0, BookingBlockedTimeModel::forBusiness($id)->countAllResults());
    }

    public function testReceptionCannotOpenTheTeam(): void
    {
        [$id] = $this->business();
        $account = (new AccountService())->findOrCreate('desk@example.test', 'Desk');
        BusinessMemberModel::forBusiness($id)->insert(['account_id' => $account['id'], 'role' => 'staff', 'status' => 'active']);

        $this->signedIn((int) $account['id'], $id)->get('business/team')->assertStatus(403);
        $this->signedIn((int) $account['id'], $id)->get('business/services')->assertStatus(403);
    }

    public function testTheSetupCheckSaysWhatIsMissing(): void
    {
        [$id, $ownerId] = $this->business();
        $codes = array_column(SetupCheck::problems($id), 'code');
        $this->assertSame(['no_services'], $codes);

        $catalog = new CatalogueService($id);
        $catalog->saveService(null, ['name' => 'Cut', 'duration_min' => 30, 'delivery_modes' => ['onsite'], 'provider_ids' => []], null);
        $this->assertContains('service_unoffered', array_column(SetupCheck::problems($id), 'code'));

        $this->signedIn($ownerId, $id)->get('business')->assertSee('has nobody who offers it');
    }

    public function testTheBusinessPagesRender(): void
    {
        [$id, $ownerId] = $this->business();
        $providerId = (int) (new TeamService($id))->providers()[0]['id'];
        $serviceId  = (int) (new CatalogueService($id))->saveService(null, ['name' => 'Cut', 'duration_min' => 30, 'delivery_modes' => ['onsite'], 'provider_ids' => [$providerId]], null)['id'];

        foreach (['business/services', 'business/services/new', 'business/services/' . $serviceId, 'business/team', 'business/team/' . $providerId, 'business/time-off'] as $path) {
            $this->signedIn($ownerId, $id)->get($path)->assertOK();
        }
    }

    // Fixtures

    /** @param array<string,mixed> $overrides */
    private function listing(array $overrides = []): int
    {
        $hours = ['sun' => ['closed' => 1], 'sat' => ['open' => '09:00', 'close' => '13:00']];
        foreach (['mon', 'tue', 'wed', 'thu', 'fri'] as $d) {
            $hours[$d] = ['open' => '08:30', 'close' => '17:00'];
        }

        return (int) $this->listings->insert($overrides + [
            'type'             => 'practice',
            'display_name'     => 'Salon Lumi',
            'contact_person'   => 'Thandi Mokoena',
            'email'            => 'owner@example.test',
            'slug'             => 'salon-lumi',
            'status'           => 'published',
            'verified_until'     => '2099-12-31',
            'hosting_paid_until' => '2099-12-31',
            'country'          => 'South Africa',
            'category_id'      => $this->categoryId,
            'trading_hours'    => json_encode($hours),
            'description'      => '<p>A friendly hair salon in the heart of Rosebank, open six days a week.</p>',
            'description_text' => 'A friendly hair salon in the heart of Rosebank, open six days a week.',
        ], true);
    }

    /**
     * @param array<string,mixed> $overrides
     *
     * @return array{0:int,1:int} listing id, owner account id
     */
    private function business(array $overrides = []): array
    {
        $id      = $this->listing($overrides);
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

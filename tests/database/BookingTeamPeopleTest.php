<?php

use App\Models\Bookings\AccountModel;
use App\Models\Bookings\BookingNotificationQueueModel;
use App\Models\Bookings\BookingProviderModel;
use App\Models\Bookings\BusinessMemberModel;
use App\Models\DirectoryCategoryModel;
use App\Models\DirectoryListingModel;
use App\Services\Bookings\AccountService;
use App\Services\Bookings\BookingSetupService;
use App\Services\Bookings\BusinessSession;
use App\Services\Bookings\CatalogueService;
use App\Services\Bookings\Notifications\NotificationDispatcher;
use App\Services\Bookings\PublicBookingService;
use App\Services\Bookings\TeamCover;
use App\Services\Bookings\TeamService;
use CodeIgniter\Events\Events;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;
use Tests\Support\FreshDatabase;
use Tests\Support\WithEnv;

/**
 * T36 (owner, 25 Nov 2026): the team in one place. Each person who signs in
 * chooses whose notices they get, reception looks after named people, and
 * removing or changing someone's email hands over what they covered.
 *
 * The owner's case: Dr A wants no notices, his reception does; a third
 * receptionist covers A, B and C.
 *
 * Services run on a fixed clock: Monday 7 January 2030, 08:00 Johannesburg.
 *
 * @internal
 */
final class BookingTeamPeopleTest extends CIUnitTestCase
{
    use FeatureTestTrait;
    use FreshDatabase;
    use WithEnv;

    private DirectoryListingModel $listings;

    private DateTimeImmutable $now;

    private int $categoryId;

    private int $listingId;

    private int $ownerAccount;

    private int $ownerMember;

    /** Dr A: the default provider, signed in as by a team member. */
    private int $drA;

    private int $drB;

    private int $drC;

    private int $serviceId;

    /** @var list<array<string,mixed>> */
    private array $sent = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setEnv('bookings.enabled', 'true');
        $this->now        = new DateTimeImmutable('2030-01-07 08:00', new DateTimeZone('Africa/Johannesburg'));
        $this->listings   = new DirectoryListingModel();
        $this->categoryId = (int) (new DirectoryCategoryModel())->insert([
            'name' => 'Dentist', 'slug' => 'dentist', 'group_name' => 'Medical', 'is_active' => 1,
        ], true);
        [$this->listingId, $this->ownerAccount] = $this->business('smile-dental');
        $this->ownerMember = (int) BusinessMemberModel::forBusiness($this->listingId)->where('account_id', $this->ownerAccount)->first()['id'];

        $team      = new TeamService($this->listingId);
        $this->drA = (int) $team->providers()[0]['id'];
        $this->drB = (int) $team->saveProvider(null, ['name' => 'Dr B'], null)['id'];
        $this->drC = (int) $team->saveProvider(null, ['name' => 'Dr C'], null)['id'];
        foreach ([$this->drA, $this->drB, $this->drC] as $p) {
            $team->saveHours($p, array_fill_keys(range(1, 7), ['active' => 1, 'start' => '08:00', 'end' => '18:00']), null);
        }
        $this->serviceId = (int) (new CatalogueService($this->listingId))->saveService(null, [
            'name' => 'Check up', 'duration_min' => 30, 'price' => '400', 'delivery_modes' => ['onsite'], 'provider_ids' => [$this->drA, $this->drB, $this->drC],
        ], null)['id'];

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

    // Notices per person

    public function testByDefaultEveryoneWhoWorkedWithThemStillGetsNotices(): void
    {
        $doc  = $this->member('dra@example.test', 'provider');
        $desk = $this->member('desk@example.test', 'staff');
        (new TeamService($this->listingId))->saveProvider($this->drA, ['member_id' => $doc], null);
        (new TeamService($this->listingId))->setStaff($this->drA, [$desk], null);

        $this->assertEqualsCanonicalizing([$this->ownerMember, $doc, $desk], array_keys($this->cover()->recipients($this->drA)), 'Admin, the doctor and their reception, as before T36');
        $this->assertSame([$this->ownerMember], array_keys($this->cover()->recipients($this->drB)), 'Only the admin covers Dr B');
    }

    public function testADoctorWhoUnticksGetsNothingWhileTheirReceptionStillDoes(): void
    {
        $doc  = $this->member('dra@example.test', 'provider');
        $desk = $this->member('desk@example.test', 'staff');
        (new TeamService($this->listingId))->saveProvider($this->drA, ['member_id' => $doc], null);
        $this->cover()->setLooksAfter($desk, [$this->drA], null);
        $this->cover()->setNotices($doc, [], false, null);
        $this->cover()->setNotices($this->ownerMember, [], false, null);

        $this->bookOnline($this->drA);
        $team = array_map('intval', array_column(BookingNotificationQueueModel::forBusiness($this->listingId)->where('recipient_type', 'team')->findAll(), 'member_id'));
        $this->assertSame([$desk], $team, 'Only reception gets the team email');

        (new NotificationDispatcher($this->now))->dispatch($this->listingId);
        $to = array_merge(...array_map(static fn (array $m): array => (array) $m['recipients'], $this->sent));
        $this->assertContains('desk@example.test', $to);
        $this->assertNotContains('dra@example.test', $to);
        $this->assertNotContains('smile-dental@example.test', $to);
    }

    public function testATickTakenAwayAfterQueueingStopsTheEmail(): void
    {
        $desk = $this->member('desk@example.test', 'staff');
        $this->cover()->setLooksAfter($desk, [$this->drA], null);
        $this->cover()->setNotices($this->ownerMember, [], false, null);
        $this->bookOnline($this->drA);

        $this->cover()->setNotices($desk, [], false, null);
        (new NotificationDispatcher($this->now))->dispatch($this->listingId);
        $row = BookingNotificationQueueModel::forBusiness($this->listingId)->where('recipient_type', 'team')->first();
        $this->assertSame('cancelled', $row['status']);
    }

    public function testAThirdReceptionistCoversThreeDoctors(): void
    {
        $third = $this->member('third@example.test', 'staff');
        $this->cover()->setLooksAfter($third, [$this->drA, $this->drB, $this->drC], null);

        $this->assertEqualsCanonicalizing([$this->drA, $this->drB, $this->drC], $this->cover()->noticesFor($third), 'Newly looked after means notices too');
        foreach ([$this->drA, $this->drB, $this->drC] as $p) {
            $this->assertArrayHasKey($third, $this->cover()->recipients($p));
        }
    }

    public function testNoticesOnlyForBookingsTheyMaySee(): void
    {
        $doc  = $this->member('dra@example.test', 'provider');
        $desk = $this->member('desk@example.test', 'staff');
        (new TeamService($this->listingId))->saveProvider($this->drA, ['member_id' => $doc], null);
        $this->cover()->setLooksAfter($desk, [$this->drA], null);

        $this->cover()->setNotices($doc, [$this->drA, $this->drB], true, null);
        $this->cover()->setNotices($desk, [$this->drA, $this->drC], false, null);
        $this->assertSame([$this->drA], $this->cover()->noticesFor($doc), 'A team member: their own only');
        $this->assertSame(0, (int) BusinessMemberModel::forBusiness($this->listingId)->find($doc)['notices_all'], 'Everyone is for admins');
        $this->assertSame([$this->drA], $this->cover()->noticesFor($desk), 'Reception: those they look after');
    }

    public function testAnAdminWithEveryoneOffGetsOnlyTheirTicks(): void
    {
        $this->cover()->setNotices($this->ownerMember, [$this->drB], false, null);

        $this->assertArrayNotHasKey($this->ownerMember, $this->cover()->recipients($this->drA));
        $this->assertArrayHasKey($this->ownerMember, $this->cover()->recipients($this->drB));
    }

    public function testTheGridSavesLooksAfterAndNoticesTogether(): void
    {
        $desk = $this->member('desk@example.test', 'staff');
        $this->cover()->saveGrid(
            [$desk => [$this->drA]],
            [$desk => [$this->drB], $this->ownerMember => []],
            [],
            [$desk, $this->ownerMember],
            null,
        );

        $this->assertEqualsCanonicalizing([$this->drA, $this->drB], $this->cover()->looksAfter($desk), 'A notice tick for reception also means looking after');
        $this->assertSame([$this->drB], $this->cover()->noticesFor($desk));
        $this->assertSame(0, (int) BusinessMemberModel::forBusiness($this->listingId)->find($this->ownerMember)['notices_all']);
    }

    // Hand over and remove

    public function testHandOverMovesEverythingOnceAndLeavesTheSignIn(): void
    {
        $one = $this->member('one@example.test', 'staff');
        $two = $this->member('two@example.test', 'staff');
        $this->cover()->setLooksAfter($one, [$this->drA, $this->drB], null);
        $this->cover()->setLooksAfter($two, [$this->drB], null);

        $moved = $this->cover()->handOver($one, $two, null);
        $this->assertNull($moved['error']);
        $this->assertEqualsCanonicalizing([$this->drA, $this->drB], $this->cover()->looksAfter($two));
        $this->assertEqualsCanonicalizing([$this->drA, $this->drB], $this->cover()->noticesFor($two));
        $this->assertSame([], $this->cover()->looksAfter($one));
        $this->assertSame('active', BusinessMemberModel::forBusiness($this->listingId)->find($one)['status'], 'On leave, not removed');
    }

    public function testRemovingWithAHandOverClearsTheirSignIn(): void
    {
        $doc  = $this->member('dra@example.test', 'provider');
        $desk = $this->member('desk@example.test', 'staff');
        $team = new TeamService($this->listingId);
        $team->saveProvider($this->drA, ['member_id' => $doc], null);

        $this->assertSame('Hand over to an admin or to reception.', $team->removeMember($desk, null, $doc));
        $this->assertNull($team->removeMember($doc, null, $desk));
        $this->assertNull(BookingProviderModel::forBusiness($this->listingId)->find($this->drA)['member_id'], 'Nobody signs in as Dr A now');
        $this->assertSame([$this->drA], $this->cover()->looksAfter($desk));
        $this->assertArrayHasKey($desk, $this->cover()->recipients($this->drA));
        $this->assertSame('The business needs at least one admin.', $team->removeMember($this->ownerMember, null, $desk));
    }

    // Email changes

    public function testAnAdminsEmailChangeWaitsForTheLinkThenMovesEverything(): void
    {
        $desk = $this->member('desk@example.test', 'staff');
        $this->cover()->setLooksAfter($desk, [$this->drA, $this->drC], null);
        $this->cover()->setNotices($desk, [$this->drC], false, null);
        $team = new TeamService($this->listingId);

        $this->assertNull($team->changeMemberEmail($this->listing(), $desk, 'new.desk@example.test', $this->ownerAccount));
        $this->assertSame('active', BusinessMemberModel::forBusiness($this->listingId)->find($desk)['status'], 'The old email keeps working');
        $this->assertSame('desk@example.test', (new AccountModel())->find((int) BusinessMemberModel::forBusiness($this->listingId)->find($desk)['account_id'])['email'], 'The account is never rewritten');
        $mail = end($this->sent);
        $this->assertSame(['new.desk@example.test'], (array) $mail['recipients']);
        $this->assertStringStartsWith('Confirm your new email for', $mail['subject']);

        preg_match('#business/invite/([a-f0-9]{64})#', (string) $mail['body'], $m);
        $result = TeamService::acceptInvitation($m[1], 'a long enough password 1');
        $this->assertNull($result['error']);
        $new = (int) BusinessMemberModel::forBusiness($this->listingId)->where('account_id', $result['account_id'])->first()['id'];

        $this->assertSame('removed', BusinessMemberModel::forBusiness($this->listingId)->find($desk)['status']);
        $this->assertSame('staff', BusinessMemberModel::forBusiness($this->listingId)->find($new)['role']);
        $this->assertEqualsCanonicalizing([$this->drA, $this->drC], $this->cover()->looksAfter($new));
        $this->assertSame([$this->drC], $this->cover()->noticesFor($new));
    }

    public function testTheirOwnEmailChangeNeedsTheLinkAndAFreeAddress(): void
    {
        $accounts = new AccountService();
        (new AccountService())->findOrCreate('taken@example.test', 'Someone');

        $this->assertStringContainsString('already has', (string) $accounts->requestEmailChange($this->ownerAccount, 'taken@example.test'));
        $this->assertNull($accounts->requestEmailChange($this->ownerAccount, 'Owner.New@example.test'));
        $this->assertSame('smile-dental@example.test', $accounts->find($this->ownerAccount)['email'], 'Nothing changes before the link');

        $mail = end($this->sent);
        $this->assertSame(['owner.new@example.test'], (array) $mail['recipients']);
        preg_match('#business/account/email/([a-f0-9]{64})#', (string) $mail['body'], $m);
        $this->assertNull($accounts->confirmEmailChange(str_repeat('a', 64)));
        $this->assertSame('owner.new@example.test', $accounts->confirmEmailChange($m[1])['email']);
        $this->assertSame(['smile-dental@example.test'], (array) end($this->sent)['recipients'], 'The old address is told');
        $this->assertNull($accounts->confirmEmailChange($m[1]), 'One use');
    }

    // Invitations

    public function testResendingAnInvitationReplacesTheLinkAndCancellingStopsIt(): void
    {
        $team = new TeamService($this->listingId);
        $id   = (int) $team->invite($this->listing(), 'late@example.test', 'Late Comer', 'staff', null, $this->ownerAccount)['member_id'];
        $first = $this->tokenIn(end($this->sent));

        $this->assertNull($team->resendInvite($this->listing(), $id, $this->ownerAccount));
        $second = $this->tokenIn(end($this->sent));
        $this->assertNotSame($first, $second);
        $this->assertNull(TeamService::invitationFor($first));
        $this->assertNotNull(TeamService::invitationFor($second));

        $this->assertNull($team->cancelInvite($id, $this->ownerAccount));
        $this->assertNull(TeamService::invitationFor($second));
    }

    public function testAnInvitedAdminGetsEveryonesNotices(): void
    {
        $id = (int) (new TeamService($this->listingId))->invite($this->listing(), 'pm@example.test', 'Practice Manager', 'admin', null, $this->ownerAccount)['member_id'];

        $this->assertSame(1, (int) BusinessMemberModel::forBusiness($this->listingId)->find($id)['notices_all']);
    }

    // Two businesses

    public function testAnotherBusinessesPeopleAreRefused(): void
    {
        [$otherId, $otherAccount] = $this->business('other-dental');
        $theirs  = (int) (new TeamService($otherId))->providers()[0]['id'];
        $theirMember = (int) BusinessMemberModel::forBusiness($otherId)->where('account_id', $otherAccount)->first()['id'];

        $this->cover()->setNotices($this->ownerMember, [$theirs], false, null);
        $this->assertSame([], $this->cover()->noticesFor($this->ownerMember));
        $this->assertSame('Choose someone else who is part of this business.', $this->cover()->handOver($this->ownerMember, $theirMember, null)['error']);
        $this->signedIn($this->ownerAccount)->get('business/team/people/' . $theirMember)->assertStatus(404);
        $this->signedIn($this->ownerAccount)->post('business/team/people/' . $theirMember . '/notices', [csrf_token() => csrf_hash()])->assertStatus(404);
    }

    // Pages

    public function testTheTeamPageShowsEveryoneInOnePlace(): void
    {
        $desk = $this->member('desk@example.test', 'staff', 'Nomsa Desk');
        $this->cover()->setLooksAfter($desk, [$this->drB], null);

        $page = $this->signedIn($this->ownerAccount)->get('business/team');
        $page->assertOK();
        $page->assertSee('Nomsa Desk');
        $page->assertSee('Not signing in');
        $page->assertSee('Who covers whom');
        $page->assertSee('business/team/people/' . $desk);

        $person = $this->signedIn($this->ownerAccount)->get('business/team/people/' . $desk);
        $person->assertOK();
        $person->assertSee('Looks after');
        $person->assertSee('Hand over to');
        $person->assertSee('Change their email');

        // Someone customers book has one page: their practitioner page.
        $this->signedIn($this->ownerAccount)->get('business/team/people/' . $this->ownerMember)
            ->assertRedirectTo(base_url('business/team/' . $this->drA . '#access'));
    }

    public function testAddingReceptionSendsAnInvitationWithoutAPractitioner(): void
    {
        $before = count((new TeamService($this->listingId))->providers(true));
        $this->signedIn($this->ownerAccount)->post('business/team', [
            csrf_token() => csrf_hash(), 'name' => 'Front Desk', 'email' => 'front@example.test', 'member_role' => 'staff',
        ]);

        $this->assertCount($before, (new TeamService($this->listingId))->providers(true), 'Reception is not someone customers book');
        $this->assertSame(['front@example.test'], (array) end($this->sent)['recipients']);
    }

    public function testATeamMemberUnticksThemselvesOnNotices(): void
    {
        $doc = $this->member('dra@example.test', 'provider');
        (new TeamService($this->listingId))->saveProvider($this->drA, ['member_id' => $doc], null);
        $account = (int) BusinessMemberModel::forBusiness($this->listingId)->find($doc)['account_id'];

        $this->signedIn($account)->get('business/notifications')->assertSee('Get notices for');
        $this->signedIn($account)->post('business/notifications/preferences', [csrf_token() => csrf_hash(), 'routes_shown' => '1'])
            ->assertRedirectTo(base_url('business/notifications'));
        $this->assertSame([], $this->cover()->noticesFor($doc));
        $this->assertArrayNotHasKey($doc, $this->cover()->recipients($this->drA));
    }

    // Helpers

    private function cover(): TeamCover
    {
        return new TeamCover($this->listingId);
    }

    private function member(string $email, string $role, string $name = 'Team Person'): int
    {
        $account = (new AccountService())->findOrCreate($email, $name);

        return (int) BusinessMemberModel::forBusiness($this->listingId)->insert(['account_id' => $account['id'], 'role' => $role, 'status' => 'active']);
    }

    private function bookOnline(int $providerId): void
    {
        $result = (new PublicBookingService($this->listing(), $this->now))->book([
            'service_id' => $this->serviceId, 'provider_id' => $providerId, 'start' => $this->utc('2030-01-10', '10:00'),
            'first_name' => 'Alice', 'surname' => 'Johnson', 'last_name' => 'Johnson', 'email' => 'alice@example.test', 'phone' => '082 555 1234',
        ]);
        $this->assertSame([], $result['errors']);
    }

    /** @param array<string,mixed> $mail */
    private function tokenIn(array $mail): string
    {
        preg_match('#business/invite/([a-f0-9]{64})#', (string) $mail['body'], $m);

        return $m[1];
    }

    /** @return array<string,mixed> */
    private function listing(): array
    {
        return $this->listings->find($this->listingId);
    }

    private function signedIn(int $accountId): self
    {
        return $this->withSession([
            BusinessSession::ACCOUNT => $accountId,
            BusinessSession::LISTING => $this->listingId,
            BusinessSession::SEEN    => time(),
        ]);
    }

    private function utc(string $date, string $time): string
    {
        return (new DateTimeImmutable($date . ' ' . $time, new DateTimeZone('Africa/Johannesburg')))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    /** @return array{0:int,1:int} listing id, owner account id */
    private function business(string $slug): array
    {
        $id = (int) $this->listings->insert([
            'type'               => 'practice',
            'display_name'       => ucwords(str_replace('-', ' ', $slug)),
            'contact_person'     => 'Thandi Mokoena',
            'email'              => $slug . '@example.test',
            'slug'               => $slug,
            'status'             => 'published',
            'verified_until'     => '2099-12-31',
            'hosting_paid_until' => '2099-12-31',
            'is_verified'        => 1,
            'country'            => 'South Africa',
            'phone'              => '011 555 0000',
            'category_id'        => $this->categoryId,
            'description'        => '<p>A friendly dental practice in the heart of Rosebank, open six days a week.</p>',
            'description_text'   => 'A friendly dental practice in the heart of Rosebank, open six days a week.',
        ], true);
        $account = (new BookingSetupService())->turnOn($this->listings->find($id));

        return [$id, (int) $account['id']];
    }
}

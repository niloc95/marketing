<?php

use App\Models\Bookings\AccountModel;
use App\Models\Bookings\BusinessMemberModel;
use App\Models\DirectoryCategoryModel;
use App\Models\DirectoryListingModel;
use App\Services\Bookings\AccountService;
use App\Services\Bookings\BookingSetupService;
use App\Services\Bookings\BusinessSession;
use App\Services\DirectoryListingMutationService;
use CodeIgniter\Events\Events;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;
use Tests\Support\FreshDatabase;
use Tests\Support\WithEnv;

/**
 * One owner area at /business (owner, 10 Nov 2026): the profile and online
 * bookings in one place, for every owner, Free included.
 *
 * Pinned:
 *
 * 1. **One account each.** An emailed sign in link to a profile's email
 *    creates the account and makes it an admin of every profile on that
 *    email; an old /manage link signs in the same way.
 * 2. **Two gate levels.** A Free profile's owner gets /business; bookings
 *    pages show what opens them and refuse writes. A lapsed badge with
 *    bookings on is locked the same way, with nothing deleted and no loop.
 * 3. **Old paths move.** /bookings/... GETs 301 and POSTs 307 to
 *    /business/...
 * 4. **The profile is the admins'.** Team members and reception never get
 *    the profile pages.
 *
 * @internal
 */
final class OwnerAreaTest extends CIUnitTestCase
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
        $this->listings   = new DirectoryListingModel();
        $this->categoryId = (int) (new DirectoryCategoryModel())->insert([
            'name' => 'Hair Salon', 'slug' => 'hair-salon', 'group_name' => 'Beauty & Wellness', 'is_active' => 1,
        ], true);
        cache()->deleteMatching('biz-*');
        $this->setEnv('bookings.enabled', 'true');

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

    // 1. One account each

    public function testASignInLinkToAProfilesEmailMakesItsOwnerAnAdmin(): void
    {
        $first  = $this->listing();
        $second = $this->listing(['slug' => 'salon-lumi-two', 'display_name' => 'Salon Lumi Two']);
        $this->assertSame(0, (new AccountModel())->countAllResults(), 'no account before the first sign in');

        $this->post('business/sign-in/link', [csrf_token() => csrf_hash(), 'email' => 'owner@example.test'])
            ->assertRedirectTo(base_url('business/sign-in'));
        $this->assertCount(1, $this->sent);
        $token = $this->tokenFrom($this->sent[0], 'business/sign-in/');

        $this->post('business/sign-in/' . $token, [csrf_token() => csrf_hash()])->assertRedirectTo(base_url('business'));
        $account = (new AccountModel())->findByEmail('owner@example.test');
        foreach ([$first, $second] as $id) {
            $member = BusinessMemberModel::forBusiness($id)->where('account_id', $account['id'])->first();
            $this->assertSame('admin', $member['role'] ?? null, 'admin of every profile on the email');
        }

        // Two businesses: the picker lists both, bookings on or not.
        $choose = $this->withSession([BusinessSession::ACCOUNT => (int) $account['id'], BusinessSession::SEEN => time()])->get('business/choose');
        $choose->assertSee('Salon Lumi');
        $choose->assertSee('Salon Lumi Two');
    }

    public function testAnEmailWithoutAProfileGetsNothing(): void
    {
        $this->listing();
        $this->post('business/sign-in/link', [csrf_token() => csrf_hash(), 'email' => 'stranger@example.test'])
            ->assertRedirectTo(base_url('business/sign-in'));

        $this->assertSame([], $this->sent);
        $this->assertSame(0, (new AccountModel())->countAllResults());
    }

    public function testAnOldManageLinkSignsInToTheBusinessArea(): void
    {
        $id    = $this->listing();
        $token = (new DirectoryListingMutationService())->mintManageToken($id);

        $this->get('manage/' . $token)->assertRedirect();
        $this->assertSame($id, (int) session(BusinessSession::LISTING));
        $account = (new AccountModel())->find((int) session(BusinessSession::ACCOUNT));
        $this->assertSame('owner@example.test', $account['email']);
    }

    // 2. Two gate levels

    public function testAFreeOwnerGetsTheProfileButBookingsAreLocked(): void
    {
        $id      = $this->listing(['verified_until' => null, 'hosting_paid_until' => null]);
        $session = \Tests\Support\OwnerSession::for($id);

        $home = $this->withSession($session)->get('business');
        $home->assertOK();
        $home->assertSee('Salon Lumi');
        $home->assertSee('Online bookings come with the Verified Business badge.');
        $home->assertDontSee('Turn on online bookings');

        $locked = $this->withSession($session)->get('business/calendar');
        $locked->assertStatus(403);
        $locked->assertSee('Get the Verified Business badge');

        $this->withSession($session)->post('business/bookings/turn-on', [csrf_token() => csrf_hash()])->assertRedirectTo(base_url('business/calendar'));
        $this->assertSame(0, (int) $this->listings->find($id)['bookings_enabled']);
    }

    public function testALapsedBadgeLocksBookingsAndKeepsEverything(): void
    {
        $id      = $this->listing();
        $account = (new BookingSetupService())->turnOn($this->listings->find($id));
        $session = [BusinessSession::ACCOUNT => (int) $account['id'], BusinessSession::LISTING => $id, BusinessSession::SEEN => time()];
        $this->withSession($session)->get('business/calendar')->assertOK();

        $this->listings->update($id, ['verified_until' => '2020-01-01']);
        $providers = db_connect()->table('xs_booking_providers')->where('listing_id', $id)->countAllResults();

        // No loop through the business picker: the overview, and a page that says why.
        $home = $this->withSession($session)->get('business');
        $home->assertOK();
        $home->assertSee('kept and come back when you renew');
        $this->withSession($session)->get('business/calendar')->assertStatus(403);
        $this->withSession($session)->post('business/api/appointments', [csrf_token() => csrf_hash()])->assertStatus(403);

        $this->assertSame(1, (int) $this->listings->find($id)['bookings_enabled']);
        $this->assertSame($providers, db_connect()->table('xs_booking_providers')->where('listing_id', $id)->countAllResults());
    }

    // 3. Old paths move

    public function testOldBookingsPathsMoveToBusiness(): void
    {
        $this->get('bookings/calendar?date=2030-01-07')->assertRedirectTo(base_url('business/calendar?date=2030-01-07'));
        $this->assertSame(301, $this->get('bookings')->response()->getStatusCode());

        $post = $this->post('bookings/settings/booking', [csrf_token() => csrf_hash()]);
        $this->assertSame(307, $post->response()->getStatusCode(), 'a form still open keeps its method');
        $this->assertSame(base_url('business/settings/booking'), $post->response()->getHeaderLine('Location'));
    }

    // 4. The profile is the admins'

    public function testReceptionDoesNotGetTheProfile(): void
    {
        $id   = $this->listing();
        (new BookingSetupService())->turnOn($this->listings->find($id));
        $desk = (new AccountService())->findOrCreate('desk@example.test', 'Desk');
        BusinessMemberModel::forBusiness($id)->insert(['account_id' => $desk['id'], 'role' => 'staff', 'status' => 'active']);
        $session = [BusinessSession::ACCOUNT => (int) $desk['id'], BusinessSession::LISTING => $id, BusinessSession::SEEN => time()];

        $home = (string) $this->withSession($session)->get('business')->response()->getBody();
        $this->assertStringNotContainsString('<span class="shell-text">Details</span>', $home);
        $this->assertStringContainsString('<span class="shell-text">Calendar</span>', $home);
    }

    // Helpers

    /** @param array<string,mixed> $overrides */
    private function listing(array $overrides = []): int
    {
        return (int) $this->listings->insert($overrides + [
            'type'               => 'practice',
            'display_name'       => 'Salon Lumi',
            'contact_person'     => 'Thandi Mokoena',
            'email'              => 'owner@example.test',
            'slug'               => 'salon-lumi',
            'status'             => 'published',
            'verified_until'     => '2099-12-31',
            'hosting_paid_until' => '2099-12-31',
            'country'            => 'South Africa',
            'category_id'        => $this->categoryId,
            'description'        => '<p>A friendly hair salon in the heart of Rosebank, open six days a week.</p>',
            'description_text'   => 'A friendly hair salon in the heart of Rosebank, open six days a week.',
        ], true);
    }

    /** @param array<string,mixed> $archive */
    private function tokenFrom(array $archive, string $path): string
    {
        $body = html_entity_decode((string) ($archive['body'] ?? ''));
        $this->assertMatchesRegularExpression('#' . preg_quote($path, '#') . '([a-f0-9]{64})#', $body);
        preg_match('#' . preg_quote($path, '#') . '([a-f0-9]{64})#', $body, $m);

        return $m[1];
    }
}

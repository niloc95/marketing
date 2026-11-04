<?php

use App\Controllers\Manage;
use App\Models\Bookings\AccountModel;
use App\Models\Bookings\BookingAuditLogModel;
use App\Models\Bookings\BookingSettingModel;
use App\Models\Bookings\BusinessMemberModel;
use App\Models\DirectoryCategoryModel;
use App\Models\DirectoryListingModel;
use App\Services\Bookings\AccountService;
use App\Services\Bookings\BookingSettings;
use App\Services\Bookings\BookingSetupService;
use App\Services\Bookings\BusinessSession;
use CodeIgniter\Events\Events;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;
use Tests\Support\FreshDatabase;
use Tests\Support\WithEnv;

/**
 * Phase 1 of online bookings (docs/bookings/): accounts, memberships, the
 * /business gate, per business settings and the audit log.
 *
 * Pinned:
 *
 * 1. **Dark until switched on.** With bookings.enabled off every /business
 *    route and /manage/bookings is a 404 and the owner dashboard shows nothing.
 * 2. **One business never sees another's rows.** A scoped model refuses to
 *    run unscoped, and every read, count, update and insert stays inside its
 *    business. Two business fixtures throughout.
 * 3. **Turning on** needs the owner's /manage session and a live South
 *    African profile; it makes the owner an admin and signs them in.
 * 4. **Sign in** is locked out per IP and per account, and every emailed
 *    link is stored hashed, works once, and is not used up by opening it.
 * 5. **The gate checks the database on every request**: a removed member or a
 *    profile with bookings off is out at once, and roles limit pages.
 *
 * @internal
 */
final class BookingFoundationTest extends CIUnitTestCase
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

    // 1. Dark until switched on

    public function testEverythingIsHiddenWhileBookingsAreOff(): void
    {
        $id = $this->listing();

        $this->get('business/sign-in')->assertStatus(404);
        $this->get('business')->assertStatus(404);
        $this->withSession([Manage::SESSION_KEY => $id])->get('manage/bookings')->assertStatus(404);

        $panel = view('directory/_bookings_panel', ['listing' => $this->listings->find($id)], ['saveData' => false]);
        $this->assertStringNotContainsString('Online bookings', $panel);
    }

    // 2. Scoping

    public function testAScopedModelRefusesToRunWithoutABusiness(): void
    {
        $this->expectException(LogicException::class);
        (new BookingSettingModel())->findAll();
    }

    public function testScopedReadsCountsAndWritesStayInsideTheirBusiness(): void
    {
        [$a, $b] = [$this->listing(), $this->listing(['slug' => 'other-salon', 'email' => 'b@example.test'])];

        BookingSettingModel::forBusiness($a)->insert(['name' => 'localization.first_day', 'value' => 'sunday']);
        $bRow = (int) BookingSettingModel::forBusiness($b)->insert(['name' => 'localization.first_day', 'value' => 'monday']);

        $this->assertCount(1, BookingSettingModel::forBusiness($a)->findAll());
        $this->assertSame(1, BookingSettingModel::forBusiness($a)->countAllResults());
        // A's model cannot find, update or delete B's row by id.
        $this->assertNull(BookingSettingModel::forBusiness($a)->find($bRow));
        BookingSettingModel::forBusiness($a)->update($bRow, ['value' => 'sunday']);
        BookingSettingModel::forBusiness($a)->delete($bRow);
        $this->assertSame('monday', BookingSettingModel::forBusiness($b)->find($bRow)['value']);

        $this->expectException(LogicException::class);
        BookingSettingModel::forBusiness($a)->insert(['listing_id' => $b, 'name' => 'localization.time_format', 'value' => '12h']);
    }

    public function testSettingsAreValidatedAndKeptPerBusiness(): void
    {
        [$a, $b] = [$this->listing(), $this->listing(['slug' => 'other-salon', 'email' => 'b@example.test'])];

        $refused = BookingSettings::for($a)->save(['localization.time_format' => 'sundial'], null);
        $this->assertArrayHasKey('localization.time_format', $refused['errors']);
        $this->assertArrayHasKey('nonsense.key', BookingSettings::for($a)->save(['nonsense.key' => 'x'], null)['errors']);

        BookingSettings::for($a)->save(['localization.time_format' => '12h'], null);
        $this->assertSame('12h', BookingSettings::for($a)->get('localization.time_format'));
        $this->assertSame('24h', BookingSettings::for($b)->get('localization.time_format'));
    }

    // 3. Turning on

    public function testTheOwnerTurnsBookingsOnAndBecomesAnAdmin(): void
    {
        $this->setEnv('bookings.enabled', 'true');
        $id = $this->listing();

        $page = $this->withSession([Manage::SESSION_KEY => $id])->get('manage/bookings');
        $page->assertOK();
        $page->assertSee('Turn on online bookings');

        $result = $this->withSession([Manage::SESSION_KEY => $id])
            ->post('manage/bookings', [csrf_token() => csrf_hash()]);
        $result->assertRedirectTo(base_url('business/account?welcome=1'));
        $result->assertSessionHas(BusinessSession::LISTING, $id);

        $this->assertSame(1, (int) $this->listings->find($id)['bookings_enabled']);
        $account = (new AccountModel())->findByEmail('owner@example.test');
        $this->assertNotNull($account);
        $this->assertNull($account['password_hash']);
        $member = BusinessMemberModel::forBusiness($id)->where('account_id', $account['id'])->first();
        $this->assertSame('admin', $member['role']);
        $this->assertSame(1, BookingAuditLogModel::forBusiness($id)->where('action', 'bookings.turned_on')->countAllResults());
    }

    public function testOnlyALiveSouthAfricanProfileCanTurnBookingsOn(): void
    {
        $this->setEnv('bookings.enabled', 'true');
        $setup = new BookingSetupService();

        $draft = $this->listings->find($this->listing(['status' => 'pending']));
        $this->assertStringContainsString('must be live', (string) $setup->whyNot($draft));

        $abroad = $this->listings->find($this->listing(['slug' => 'london-salon', 'email' => 'uk@example.test', 'country' => 'United Kingdom', 'country_code' => 'GB']));
        $this->assertStringContainsString('not available in your country', (string) $setup->whyNot($abroad));

        $this->withSession([Manage::SESSION_KEY => (int) $draft['id']])
            ->post('manage/bookings', [csrf_token() => csrf_hash()])
            ->assertRedirectTo(base_url('manage/bookings'));
        $this->assertSame(0, (int) $this->listings->find($draft['id'])['bookings_enabled']);
    }

    public function testTurningOnNeedsTheManageSession(): void
    {
        $this->setEnv('bookings.enabled', 'true');
        $this->listing();

        $this->post('manage/bookings', [csrf_token() => csrf_hash()])->assertRedirectTo(base_url('manage'));
        $this->assertSame(0, (new AccountModel())->countAllResults());
    }

    // 4. Sign in

    public function testSignInWithAPasswordOpensTheBusiness(): void
    {
        [$listingId, $accountId] = $this->business();
        (new AccountService())->setPassword($accountId, 'correct horse battery');

        $this->post('business/sign-in', [csrf_token() => csrf_hash(), 'email' => 'owner@example.test', 'password' => 'wrong password here'])
            ->assertRedirectTo(base_url('business/sign-in'));

        $ok = $this->post('business/sign-in', [csrf_token() => csrf_hash(), 'email' => 'OWNER@example.test ', 'password' => 'correct horse battery']);
        $ok->assertRedirectTo(base_url('business'));
        $ok->assertSessionHas(BusinessSession::ACCOUNT, $accountId);

        $home = $this->signedIn($accountId, $listingId)->get('business');
        $home->assertOK();
        $home->assertSee('Salon Lumi');
        $this->assertStringContainsString('no-store', $home->response()->getHeaderLine('Cache-Control'));
    }

    public function testRepeatedFailuresLockTheAccountWhicheverAddressTheyComeFrom(): void
    {
        [, $accountId] = $this->business();
        $service = new AccountService();
        $service->setPassword($accountId, 'correct horse battery');

        for ($i = 0; $i < config('Bookings')->accountAttempts; $i++) {
            // A new IP each time, so only the account lockout can stop it.
            $this->assertSame(AccountService::BAD_LOGIN, $service->attempt('owner@example.test', 'guess ' . $i, '10.0.0.' . $i)['result']);
        }
        $this->assertSame(AccountService::LOCKED, $service->attempt('owner@example.test', 'correct horse battery', '10.0.1.1')['result']);
    }

    public function testTheIpLockoutStopsAfterFiveAttempts(): void
    {
        $this->business();
        $service = new AccountService();
        for ($i = 0; $i < 5; $i++) {
            $service->attempt('nobody' . $i . '@example.test', 'whatever password', '10.9.9.9');
        }
        $this->assertSame(AccountService::LOCKED, $service->attempt('owner@example.test', 'whatever password', '10.9.9.9')['result']);
    }

    public function testAPasswordResetLinkIsHashedWorksOnceAndSignsIn(): void
    {
        [, $accountId] = $this->business();
        $this->setEnv('bookings.enabled', 'true');

        $this->post('business/password', [csrf_token() => csrf_hash(), 'email' => 'owner@example.test'])
            ->assertRedirectTo(base_url('business/sign-in'));
        $this->assertCount(1, $this->sent);
        $token = $this->tokenFrom($this->sent[0], 'business/password/');

        $stored = (new AccountModel())->find($accountId);
        $this->assertSame(hash('sha256', $token), $stored['reset_token_hash']);
        $this->assertStringNotContainsString($token, json_encode($stored));

        // Opening the link does not use it up.
        $this->get('business/password/' . $token)->assertSee('Choose a new password');
        $this->get('business/password/' . $token)->assertSee('Choose a new password');

        $this->post('business/password/' . $token, [csrf_token() => csrf_hash(), 'password' => 'a brand new password', 'password_confirm' => 'a brand new password'])
            ->assertRedirectTo(base_url('business'));
        $this->assertTrue(password_verify('a brand new password', (new AccountModel())->find($accountId)['password_hash']));
        $this->get('business/password/' . $token)->assertSee('This link has expired');
    }

    public function testFormsGiveTheSameAnswerForAnEmailWithNoAccount(): void
    {
        $this->business();

        $known   = $this->post('business/password', [csrf_token() => csrf_hash(), 'email' => 'owner@example.test']);
        $unknown = $this->post('business/password', [csrf_token() => csrf_hash(), 'email' => 'stranger@example.test']);

        $this->assertSame($known->response()->getHeaderLine('Location'), $unknown->response()->getHeaderLine('Location'));
        $this->assertCount(1, $this->sent);
    }

    public function testASignInLinkIsUsedUpOnlyByItsButton(): void
    {
        [$listingId, $accountId] = $this->business();

        $this->post('business/sign-in/link', [csrf_token() => csrf_hash(), 'email' => 'owner@example.test']);
        $token = $this->tokenFrom($this->sent[0], 'business/sign-in/');

        $this->get('business/sign-in/' . $token)->assertSee('Sign in to your business');
        $first = $this->post('business/sign-in/' . $token, [csrf_token() => csrf_hash()]);
        $first->assertRedirectTo(base_url('business'));
        $first->assertSessionHas(BusinessSession::ACCOUNT, $accountId);

        $this->post('business/sign-in/' . $token, [csrf_token() => csrf_hash()])
            ->assertRedirectTo(base_url('business/sign-in'));
    }

    // 5. The gate

    public function testARemovedMemberIsOutOnTheNextRequest(): void
    {
        [$listingId, $accountId] = $this->business();
        $this->signedIn($accountId, $listingId)->get('business')->assertOK();

        $member = BusinessMemberModel::forBusiness($listingId)->where('account_id', $accountId)->first();
        BusinessMemberModel::forBusiness($listingId)->update((int) $member['id'], ['status' => 'removed']);

        $this->signedIn($accountId, $listingId)->get('business')->assertRedirectTo(base_url('business/choose'));
    }

    public function testAnotherBusinessCannotBeChosenOrReached(): void
    {
        [$listingId, $accountId] = $this->business();
        [$otherId]               = $this->business(['slug' => 'other-salon', 'email' => 'b@example.test', 'display_name' => 'Other Salon']);

        // A session pointing at a business the account is not part of.
        $this->signedIn($accountId, $otherId)->get('business')->assertRedirectTo(base_url('business/choose'));

        $this->signedIn($accountId, $listingId)
            ->post('business/choose', [csrf_token() => csrf_hash(), 'listing_id' => $otherId])
            ->assertRedirectTo(base_url('business/choose'));
    }

    public function testAnIdleSessionEnds(): void
    {
        [$listingId, $accountId] = $this->business();

        $this->withSession([
            BusinessSession::ACCOUNT => $accountId,
            BusinessSession::LISTING => $listingId,
            BusinessSession::SEEN    => time() - config('Bookings')->idleSeconds - 1,
        ])->get('business')->assertRedirectTo(base_url('business/sign-in?expired=1'));
    }

    public function testRolesLimitPages(): void
    {
        [$listingId] = $this->business();
        $provider    = (new AccountService())->findOrCreate('stylist@example.test', 'Lerato');
        BusinessMemberModel::forBusiness($listingId)->insert(['account_id' => $provider['id'], 'role' => 'provider', 'status' => 'active']);

        $this->signedIn((int) $provider['id'], $listingId)->get('business')->assertOK();
        $this->signedIn((int) $provider['id'], $listingId)->get('business/settings/general')->assertStatus(403);
        $this->signedIn((int) $provider['id'], $listingId)->get('business/audit')->assertStatus(403);
    }

    public function testSavingSettingsIsAudited(): void
    {
        [$listingId, $accountId] = $this->business();

        $this->signedIn($accountId, $listingId)
            ->post('business/settings/localization', [csrf_token() => csrf_hash(), 'time_format' => '12h', 'first_day' => 'sunday'])
            ->assertRedirectTo(base_url('business/settings/localization'));

        $this->assertSame('12h', BookingSettings::for($listingId)->get('localization.time_format'));
        $log = BookingAuditLogModel::forBusiness($listingId)->where('action', 'settings.saved')->first();
        $this->assertSame($accountId, (int) $log['account_id']);
        $this->assertStringContainsString('12h', (string) $log['new_value']);

        $this->signedIn($accountId, $listingId)->get('business/audit')->assertSee('Changed settings');
    }

    public function testTurningOffKeepsEverything(): void
    {
        [$listingId, $accountId] = $this->business();
        BookingSettings::for($listingId)->save(['localization.first_day' => 'sunday'], $accountId);

        $this->signedIn($accountId, $listingId)
            ->post('business/turn-off', [csrf_token() => csrf_hash(), 'confirm' => 'yes'])
            ->assertRedirectTo(base_url('business/choose'));

        $this->assertSame(0, (int) $this->listings->find($listingId)['bookings_enabled']);
        $this->assertSame('sunday', BookingSettings::for($listingId)->get('localization.first_day'));
        $this->signedIn($accountId, $listingId)->get('business')->assertRedirectTo(base_url('business/choose'));
    }

    public function testBusinessViewsFollowTheCopyRules(): void
    {
        $files = array_merge(
            glob(APPPATH . 'Views/business/*.php') ?: [],
            [APPPATH . 'Views/layouts/business.php', APPPATH . 'Views/directory/manage_bookings.php', APPPATH . 'Views/directory/_bookings_panel.php'],
            glob(APPPATH . 'Views/emails/booking-*.php') ?: [],
        );
        foreach ($files as $file) {
            // Customer facing text only: drop PHP blocks and tag attributes.
            $text = preg_replace(['/<\?php.*?\?>/s', '/<\?=.*?\?>/s', '#<script.*?</script>#s', '/<[^>]+>/'], ' ', (string) file_get_contents($file));
            $this->assertStringNotContainsString('—', $text, basename($file));
            $this->assertDoesNotMatchRegularExpression('/\b[A-Za-z]+-[A-Za-z]+\b/', $text, basename($file) . ' has a hyphenated word');
            $this->assertDoesNotMatchRegularExpression('/\b(log ?in|log out|directory|listing)\b/i', $text, basename($file));
        }
    }

    // Fixtures

    /** @param array<string,mixed> $overrides */
    private function listing(array $overrides = []): int
    {
        return (int) $this->listings->insert($overrides + [
            'type'             => 'practice',
            'display_name'     => 'Salon Lumi',
            'contact_person'   => 'Thandi Mokoena',
            'email'            => 'owner@example.test',
            'slug'             => 'salon-lumi',
            'status'           => 'published',
            'country'          => 'South Africa',
            'category_id'      => $this->categoryId,
            'description'      => '<p>A friendly hair salon in the heart of Rosebank, open six days a week.</p>',
            'description_text' => 'A friendly hair salon in the heart of Rosebank, open six days a week.',
        ], true);
    }

    /**
     * A published profile with bookings on and its owner as admin.
     *
     * @param array<string,mixed> $overrides
     *
     * @return array{0:int,1:int} listing id, account id
     */
    private function business(array $overrides = []): array
    {
        $this->setEnv('bookings.enabled', 'true');
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

    /** @param array<string,mixed> $archive */
    private function tokenFrom(array $archive, string $path): string
    {
        $body = html_entity_decode((string) ($archive['body'] ?? ''));
        $this->assertMatchesRegularExpression('#' . preg_quote($path, '#') . '([a-f0-9]{64})#', $body);
        preg_match('#' . preg_quote($path, '#') . '([a-f0-9]{64})#', $body, $m);

        return $m[1];
    }
}

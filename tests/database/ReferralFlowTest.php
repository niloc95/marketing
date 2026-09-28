<?php

use App\Filters\AdminFilter;
use App\Libraries\TokenHash;
use App\Models\DirectoryCategoryModel;
use App\Models\DirectoryListingModel;
use App\Models\DirectoryReferralModel;
use App\Services\DirectoryListingMutationService;
use App\Services\ReferralService;
use CodeIgniter\Events\Events;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;

/**
 * "Recommend a business", end to end.
 *
 * Pinned:
 *
 * 1. **The public form never emails the business.** Only an admin's Invite
 *    does, once per business, and never to a suppressed or already-listed
 *    address.
 * 2. **The invite link pre-fills signup**, and a bad or expired token gives
 *    the empty form.
 * 3. **Confirming the listing closes the referral** and tells the referrer
 *    only if they asked.
 * 4. **"Don't contact me again" holds**, whoever refers the address next.
 * 5. **Retention** wipes contact details.
 *
 * Requires the `tests` database group — see the Tests section of README.md.
 *
 * @internal
 */
final class ReferralFlowTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $namespace   = 'App';
    protected $migrate     = true;
    protected $migrateOnce = false;
    protected $refresh     = true;

    private ReferralService $svc;
    private DirectoryReferralModel $referrals;
    private int $categoryId;

    /** @var list<array<string,mixed>> */
    private array $sent = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc       = new ReferralService();
        $this->referrals = new DirectoryReferralModel();

        $this->categoryId = (int) (new DirectoryCategoryModel())->insert([
            'name' => 'Plumber', 'slug' => 'plumber', 'group_name' => 'Home Services', 'is_active' => 1,
        ], true);

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

        $this->sent = [];
        Events::on('email', function (array $archive): void {
            $this->sent[] = $archive;
        });
    }

    protected function tearDown(): void
    {
        Events::removeAllListeners('email');
        Services::resetSingle('throttler');
        parent::tearDown();
    }

    // ------------------------------------------------------------- the form

    public function testTheFormStoresAReferralAndEmailsNobodyButTheAdmin(): void
    {
        $result = $this->withSession(['recommend_form_rendered_at' => time() - 30])
            ->post('recommend', $this->input() + $this->csrf());

        $result->assertRedirect();
        $row = $this->referrals->first();
        $this->assertSame('Drip Doctors', $row['business_name']);
        $this->assertSame(DirectoryReferralModel::STATUS_PENDING, $row['status']);
        $this->assertSame($this->categoryId, (int) $row['category_id']);
        $this->assertNotSame('', (string) $row['ip_hash']);

        foreach ($this->sent as $mail) {
            $this->assertNotContains('info@dripdoctors.test', (array) $mail['recipients'], 'the business is never emailed from the form');
        }
    }

    public function testTheHoneypotAndTimingFloorFakeSuccessAndStoreNothing(): void
    {
        $this->withSession(['recommend_form_rendered_at' => time() - 30])
            ->post('recommend', $this->input(['company_website_hp' => 'x']) + $this->csrf())
            ->assertRedirect();
        $this->withSession(['recommend_form_rendered_at' => time()])
            ->post('recommend', $this->input() + $this->csrf())
            ->assertRedirect();

        $this->assertSame(0, $this->referrals->countAllResults());
    }

    public function testEveryFieldButTheWebsiteIsRequired(): void
    {
        $result = $this->svc->submit([], '10.0.0.1');

        $this->assertFalse($result['ok']);
        foreach (['business_name', 'business_email', 'business_phone', 'category_id', 'city', 'province', 'note', 'relationship', 'referrer_name', 'referrer_email'] as $field) {
            $this->assertArrayHasKey($field, $result['errors'], $field);
        }
        $this->assertArrayNotHasKey('website', $result['errors']);

        $this->assertTrue($this->svc->submit($this->input(['website' => '', 'notify_referrer' => '']), '10.0.0.1')['ok'], 'website and the notify box are optional');
        $this->referrals->truncate();

        $this->assertArrayHasKey('website', $this->svc->submit($this->input(['website' => 'javascript:alert(1)']), '10.0.0.1')['errors']);
        $this->assertSame(0, $this->referrals->countAllResults());
    }

    // ------------------------------------------------------------- inviting

    public function testInviteSendsOneEmailWithAPrefilledSignupLink(): void
    {
        $id = $this->referral();

        $this->assertTrue($this->svc->invite($id)['ok']);
        $this->assertCount(1, $this->sent);
        $mail = $this->sent[0];
        $this->assertContains('info@dripdoctors.test', (array) $mail['recipients']);
        $body = html_entity_decode((string) $mail['body']);
        $this->assertStringNotContainsString('lindiwe@example.test', $body, 'the referrer\'s address is never shown');
        $this->assertStringContainsString('Lindiwe', $body, 'a customer\'s first name is');
        $this->assertSame(1, preg_match('#add-listing\?invite=([a-f0-9]{64})#', $body, $m));

        $row = $this->referrals->find($id);
        $this->assertSame(DirectoryReferralModel::STATUS_INVITED, $row['status']);
        $this->assertSame(TokenHash::hash($m[1]), $row['invite_token'], 'only the hash is stored');

        $this->assertFalse($this->svc->invite($id)['ok'], 'a referral is invited once');

        $second = $this->referral();
        $this->assertFalse($this->svc->invite($second)['ok'], 'a second referral of the same business sends nothing');
        $this->assertSame([], $this->sent);

        // esc(..., 'attr') encodes spaces, so compare decoded markup.
        $form = html_entity_decode((string) $this->get('add-listing?invite=' . $m[1])->response()->getBody());
        $this->assertStringContainsString('value="Drip Doctors"', $form);
        $this->assertStringContainsString('value="info@dripdoctors.test"', $form);
        $this->assertStringContainsString('name="invite" value="' . $m[1] . '"', $form);

        $bad = html_entity_decode((string) $this->get('add-listing?invite=' . str_repeat('a', 64))->response()->getBody());
        $this->assertStringNotContainsString('Drip Doctors', $bad);
        $this->assertStringNotContainsString('name="invite"', $bad);
    }

    public function testAnExpiredInviteNoLongerPrefills(): void
    {
        $id    = $this->referral();
        $token = $this->inviteAndGetToken($id);
        $this->referrals->update($id, ['invited_at' => date('Y-m-d H:i:s', strtotime('-' . (ReferralService::INVITE_TTL_DAYS + 1) . ' days'))]);

        $this->assertSame([], $this->svc->prefillFor($token));
    }

    public function testNoInviteToAnAddressAlreadyListed(): void
    {
        (new DirectoryListingModel())->insert([
            'type' => 'practice', 'display_name' => 'Drip Doctors', 'email' => 'info@dripdoctors.test',
            'slug' => 'drip-doctors', 'status' => 'published', 'is_verified' => 1,
            'category_id' => $this->categoryId, 'city' => 'Durban', 'province' => 'KwaZulu-Natal',
        ]);
        $id = $this->referral();

        $this->assertNotNull($this->svc->likelyDuplicate($this->referrals->find($id)));
        $this->assertFalse($this->svc->invite($id)['ok']);
        $this->assertSame([], $this->sent);
    }

    public function testTheAdminQueueNeedsAnAdminAndInvites(): void
    {
        $id = $this->referral();

        $page = $this->withSession(['dir_admin' => true, AdminFilter::SEEN_KEY => time()])->get('admin/referrals');
        $page->assertOK();
        $this->assertStringContainsString('Drip Doctors', (string) $page->response()->getBody());

        $this->withSession(['dir_admin' => true, AdminFilter::SEEN_KEY => time()])
            ->post('admin/referrals/' . $id . '/invite', $this->csrf())
            ->assertRedirect();
        $this->assertSame(DirectoryReferralModel::STATUS_INVITED, $this->referrals->find($id)['status']);

        $other = $this->referral(['business_email' => 'other@example.test']);
        // withSession() is re-applied on every later request; replace it.
        $this->withSession([])->post('admin/referrals/' . $other . '/invite', $this->csrf());
        $this->assertSame(DirectoryReferralModel::STATUS_PENDING, $this->referrals->find($other)['status'], 'no invite without an admin session');
    }

    // ---------------------------------------------------------- being listed

    public function testConfirmingTheListingClosesTheReferralAndTellsTheReferrer(): void
    {
        $id    = $this->referral();
        $token = $this->inviteAndGetToken($id);

        $listings  = new DirectoryListingModel();
        $raw       = TokenHash::mint();
        $listingId = (int) $listings->insert([
            'type' => 'practice', 'display_name' => 'Drip Doctors', 'email' => 'owner@dripdoctors.test',
            'slug' => 'drip-doctors', 'status' => 'pending', 'is_verified' => 0,
            'category_id' => $this->categoryId, 'city' => 'Durban', 'province' => 'KwaZulu-Natal',
            'country' => 'ZA',
            'verify_token' => TokenHash::hash($raw), 'verify_expires' => date('Y-m-d H:i:s', time() + 3600),
        ], true);

        // Signed up with a different address than the one invited: the
        // invite token is what ties them.
        $this->svc->attachListing($token, $listingId);
        $this->sent = [];
        (new DirectoryListingMutationService())->verify($raw);

        $row = $this->referrals->find($id);
        $this->assertSame(DirectoryReferralModel::STATUS_LISTED, $row['status']);
        $this->assertSame($listingId, (int) $row['listing_id']);

        $toReferrer = array_filter($this->sent, static fn ($m) => in_array('lindiwe@example.test', (array) $m['recipients'], true));
        $this->assertCount(1, $toReferrer);
    }

    public function testAReferrerWhoDidNotAskIsNotEmailed(): void
    {
        $id = $this->referral(['notify_referrer' => 0]);

        $this->svc->markListed(['id' => $this->listingId('info@dripdoctors.test'), 'email' => 'info@dripdoctors.test', 'display_name' => 'Drip Doctors', 'slug' => 'drip-doctors']);

        $this->assertSame(DirectoryReferralModel::STATUS_LISTED, $this->referrals->find($id)['status'], 'matched by email with no invite');
        $this->assertSame([], $this->sent);
    }

    // ---------------------------------------------------------- stop / prune

    public function testDontContactMeAgainBlocksEveryLaterReferral(): void
    {
        $token = $this->inviteAndGetToken($this->referral());

        $this->get('recommend/stop/' . $token)->assertOK();
        $this->assertTrue($this->svc->suppress($token));

        $later = $this->referral(['business_email' => 'INFO@dripdoctors.test']);
        $this->assertSame('This address asked not to be contacted.', $this->svc->inviteBlocker($this->referrals->find($later)));
        $this->assertFalse($this->svc->suppress(str_repeat('b', 64)));
    }

    public function testPruneWipesContactDetailsPastRetention(): void
    {
        $old   = $this->referral();
        $fresh = $this->referral(['business_email' => 'new@example.test']);
        $this->db->table('directory_referrals')->where('id', $old)
            ->update(['created_at' => date('Y-m-d H:i:s', strtotime('-13 months'))]);

        $this->assertSame(1, $this->svc->prune());

        $wiped = $this->referrals->find($old);
        $this->assertNull($wiped['business_email']);
        $this->assertNull($wiped['referrer_email']);
        $this->assertNotNull($wiped['pruned_at']);
        $this->assertSame('new@example.test', $this->referrals->find($fresh)['business_email']);
        $this->assertSame(0, $this->svc->prune(), 'a row is wiped once');
    }

    // --------------------------------------------------------------- helpers

    /** @return array<string,mixed> */
    private function input(array $overrides = []): array
    {
        return $overrides + [
            'business_name'   => 'Drip Doctors',
            'business_email'  => 'info@dripdoctors.test',
            'business_phone'  => '031 555 0101',
            'website'         => 'dripdoctors.test',
            'category_id'     => (string) $this->categoryId,
            'city'            => 'Durban',
            'province'        => 'KwaZulu-Natal',
            'note'            => 'Fixed our geyser on a Sunday.',
            'relationship'    => 'customer',
            'referrer_name'   => 'Lindiwe Khumalo',
            'referrer_email'  => 'lindiwe@example.test',
            'notify_referrer' => '1',
        ];
    }

    private function referral(array $overrides = []): int
    {
        $result = $this->svc->submit($this->input($overrides), '10.0.0.1');
        $this->assertTrue($result['ok'], json_encode($result['errors']));
        $this->sent = [];

        return (int) $this->referrals->orderBy('id', 'DESC')->first()['id'];
    }

    private function inviteAndGetToken(int $id): string
    {
        $this->assertTrue($this->svc->invite($id)['ok']);
        preg_match('#add-listing\?invite=([a-f0-9]{64})#', html_entity_decode((string) end($this->sent)['body']), $m);
        $this->sent = [];

        return $m[1];
    }

    private function listingId(string $email): int
    {
        return (int) (new DirectoryListingModel())->insert([
            'type' => 'practice', 'display_name' => 'Drip Doctors', 'email' => $email,
            'slug' => 'drip-doctors', 'status' => 'published', 'is_verified' => 1,
            'category_id' => $this->categoryId, 'city' => 'Durban', 'province' => 'KwaZulu-Natal',
        ], true);
    }

    private function csrf(): array
    {
        return [csrf_token() => csrf_hash()];
    }
}

<?php

use App\Models\DirectoryListingModel;
use App\Models\DirectoryPartnerClickModel;
use App\Models\DirectoryPartnerCommissionModel;
use App\Models\DirectoryPartnerModel;
use App\Models\DirectoryPartnerPayoutModel;
use App\Models\DirectoryVerificationModel;
use App\Services\DirectorySettings;
use App\Services\PartnerService;
use App\Services\VerificationService;
use CodeIgniter\Events\Events;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;

/**
 * The Partner Program, end to end.
 *
 * Pinned:
 *
 * 1. **Applying** stores an application and emails only the admin; a repeat
 *    address changes nothing.
 * 2. **The link** counts people, not link previews, and only for approved
 *    partners; /p/{code} sets the cookie.
 * 3. **Attribution** credits a fresh signup, and never an old profile, the
 *    partner's own, or one already credited.
 * 4. **Commission** comes from the real recordPayment(): the right amount,
 *    once per payment, only inside the window and after the credit, and a
 *    failure never costs the payment.
 * 5. **Sweep** releases the hold and rebuilds a missed commission.
 * 6. **Payouts** only pay from the minimum, all or nothing.
 * 7. **Bank details** are stored encrypted, never as plain text.
 *
 * Requires the `tests` database group, see the Tests section of README.md.
 *
 * @internal
 */
final class PartnerProgramTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $namespace   = 'App';
    protected $migrate     = true;
    protected $migrateOnce = false;
    protected $refresh     = true;

    private PartnerService $svc;
    private DirectoryPartnerModel $partners;
    private DirectoryPartnerCommissionModel $commissions;
    private DirectoryListingModel $listings;

    /** @var list<array<string,mixed>> */
    private array $sent = [];

    private const BROWSER = 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 Mobile/15E148';

    protected function setUp(): void
    {
        parent::setUp();
        (new DirectorySettings())->forget();
        $this->svc         = new PartnerService();
        $this->partners    = new DirectoryPartnerModel();
        $this->commissions = new DirectoryPartnerCommissionModel();
        $this->listings    = new DirectoryListingModel();

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
        Services::resetSingle('encrypter');
        // The redirect response is a shared service, so a cookie set on it
        // in one test would otherwise still be on it in the next.
        Services::resetSingle('redirectresponse');
        Services::resetSingle('response');
        parent::tearDown();
    }

    // ------------------------------------------------------------- helpers

    private function csrf(): array
    {
        return [csrf_token() => csrf_hash()];
    }

    /** @return array<string,string> */
    private function application(array $over = []): array
    {
        return $over + [
            'name'        => 'Thandi Mokoena',
            'email'       => 'thandi@agency.test',
            'company'     => 'Mokoena Marketing',
            'promo_plan'  => 'I run websites for about forty small businesses in Pretoria.',
            'agree_terms' => '1',
        ];
    }

    private function approvedPartner(array $over = []): array
    {
        $this->assertTrue($this->svc->apply($this->application($over))['ok']);
        $p = $this->partners->findByEmail($over['email'] ?? 'thandi@agency.test');
        $this->assertTrue($this->svc->approve((int) $p['id'], 'admin@test')['ok']);

        return $this->partners->find((int) $p['id']);
    }

    private function listing(string $email = 'owner@plumber.test'): int
    {
        return (int) $this->listings->insert([
            'display_name' => 'Drip Doctors',
            'email'        => $email,
            'slug'         => 'drip-' . bin2hex(random_bytes(4)),
            'status'       => 'published',
        ], true);
    }

    /** An approved Verified application, ready for a payment. */
    private function verification(int $listingId): array
    {
        $model = new DirectoryVerificationModel();
        $id    = (int) $model->insert([
            'listing_id' => $listingId,
            'plan'       => DirectoryVerificationModel::PLAN_BADGE,
            'state'      => DirectoryVerificationModel::STATE_APPROVED,
            'amount'     => '30.00',
        ], true);

        return $model->find($id);
    }

    private function pay(int $verificationId, string $pfId, float $amount = 30.0): string
    {
        $v = (new DirectoryVerificationModel())->find($verificationId);

        return (new VerificationService())->recordPayment($v, $pfId, 'COMPLETE', $amount, ['pf_payment_id' => $pfId]);
    }

    private function backdate(string $table, int $id, string $column, string $when): void
    {
        db_connect()->table($table)->where('id', $id)->update([$column => date('Y-m-d H:i:s', strtotime($when))]);
    }

    /** @return list<string> every recipient of every email sent */
    private function recipients(): array
    {
        $out = [];
        foreach ($this->sent as $mail) {
            foreach ((array) $mail['recipients'] as $r) {
                $out[] = (string) $r;
            }
        }

        return $out;
    }

    // ------------------------------------------------------------- applying

    public function testApplyingStoresAnApplicationAndEmailsOnlyTheAdmin(): void
    {
        $result = $this->withSession(['partner_form_rendered_at' => time() - 30])
            ->post('partners', $this->application() + $this->csrf());

        $result->assertRedirect();
        $p = $this->partners->findByEmail('thandi@agency.test');
        $this->assertSame(DirectoryPartnerModel::STATUS_APPLIED, $p['status']);
        $this->assertSame('mokoena-marketing', $p['code']);
        $this->assertNotContains('thandi@agency.test', $this->recipients(), 'nobody but the admin is emailed on applying');
    }

    public function testAnApplicationNeedsAPlanAndTheTerms(): void
    {
        $result = $this->svc->apply($this->application(['promo_plan' => 'yes', 'agree_terms' => '']));

        $this->assertFalse($result['ok']);
        $this->assertArrayHasKey('promo_plan', $result['errors']);
        $this->assertArrayHasKey('agree_terms', $result['errors']);
        $this->assertSame(0, $this->partners->countAllResults());
    }

    public function testApplyingTwiceFromOneAddressChangesNothing(): void
    {
        $this->assertTrue($this->svc->apply($this->application())['ok']);
        $this->assertTrue($this->svc->apply($this->application(['name' => 'Someone Else']))['ok'], 'same answer, so the form finds no one');

        $this->assertSame(1, $this->partners->countAllResults());
        $this->assertSame('Thandi Mokoena', $this->partners->first()['name']);
    }

    public function testApprovalEmailsTheLinkAndASingleUseSignIn(): void
    {
        $p = $this->approvedPartner();

        $this->assertContains('thandi@agency.test', $this->recipients());
        $this->assertNotNull($p['login_token'], 'a sign in link was issued');
        $this->assertSame(DirectoryPartnerModel::STATUS_APPROVED, $p['status']);
    }

    public function testASignInLinkWorksOnce(): void
    {
        $p     = $this->approvedPartner();
        $token = str_repeat('a', 64);
        $this->partners->update((int) $p['id'], [
            'login_token'   => App\Libraries\TokenHash::hash($token),
            'login_expires' => date('Y-m-d H:i:s', time() + 600),
        ]);

        $this->assertSame((int) $p['id'], (int) $this->svc->redeemLogin($token)['id']);
        $this->assertNull($this->svc->redeemLogin($token));
    }

    // --------------------------------------------------------------- the link

    public function testTheLinkCountsPeopleButNotLinkPreviews(): void
    {
        $p = $this->approvedPartner();

        $this->assertNotNull($this->svc->track($p['code'], self::BROWSER));
        $this->assertNotNull($this->svc->track($p['code'], 'WhatsApp/2.23.20.0 A'));
        $this->assertNotNull($this->svc->track($p['code'], 'facebookexternalhit/1.1'));

        $this->assertSame(1, (new DirectoryPartnerClickModel())->total((int) $p['id']));
    }

    public function testOnlyAnApprovedPartnersLinkTracks(): void
    {
        $this->svc->apply($this->application());
        $applied = $this->partners->first();
        $this->assertNull($this->svc->track($applied['code'], self::BROWSER));

        $this->svc->approve((int) $applied['id'], 'admin@test');
        $this->svc->suspend((int) $applied['id'], 'admin@test');
        $this->assertNull($this->svc->track($applied['code'], self::BROWSER));
    }

    public function testThePartnerLinkSetsTheCookieAndLandsHome(): void
    {
        $p = $this->approvedPartner();

        $result = $this->withHeaders(['User-Agent' => self::BROWSER])->get('p/' . $p['code']);

        $result->assertRedirectTo(base_url('/'));
        $result->assertCookie(PartnerService::COOKIE, $p['code']);
    }

    public function testADeadLinkStillLandsHomeWithoutACookie(): void
    {
        $result = $this->get('p/nobody-here');

        $result->assertRedirectTo(base_url('/'));
        $result->assertCookieMissing(PartnerService::COOKIE);
    }

    // ------------------------------------------------------------ attribution

    public function testAFreshSignupIsCreditedToThePartner(): void
    {
        $p  = $this->approvedPartner();
        $id = $this->listing();

        $this->assertTrue($this->svc->attribute($id, $p['code']));
        $this->assertSame((int) $p['id'], (int) $this->listings->find($id)['partner_id']);
    }

    public function testAnOldProfileIsNeverCredited(): void
    {
        $p  = $this->approvedPartner();
        $id = $this->listing();
        $this->backdate('xs_directory_listings', $id, 'created_at', '-2 days');

        $this->assertFalse($this->svc->attribute($id, $p['code']), 'submitPublic() returns an existing profile for a known email');
        $this->assertNull($this->listings->find($id)['partner_id']);
    }

    public function testAPartnerCannotCreditTheirOwnBusiness(): void
    {
        $p = $this->approvedPartner();

        $this->assertFalse($this->svc->attribute($this->listing('thandi@agency.test'), $p['code']));
    }

    public function testTheFirstCreditStands(): void
    {
        $first  = $this->approvedPartner();
        $second = $this->approvedPartner(['email' => 'sipho@other.test', 'company' => 'Other Co']);
        $id     = $this->listing();

        $this->assertTrue($this->svc->attribute($id, $first['code']));
        $this->assertFalse($this->svc->attribute($id, $second['code']));
        $this->assertSame((int) $first['id'], (int) $this->listings->find($id)['partner_id']);
    }

    public function testAnOwnerCannotSetThePartnerThroughTheModel(): void
    {
        $p  = $this->approvedPartner();
        $id = $this->listing();

        try {
            $this->listings->update($id, ['partner_id' => (int) $p['id']]);
        } catch (CodeIgniter\Database\Exceptions\DataException) {
            // "There is no data to update": the field was filtered out entirely.
        }

        $this->assertNull($this->listings->find($id)['partner_id'], 'partner_id is not in allowedFields');
    }

    // ------------------------------------------------------------- commission

    public function testAPaymentEarnsTheRateOnce(): void
    {
        $p  = $this->approvedPartner();
        $id = $this->listing();
        $this->svc->attribute($id, $p['code']);
        $v = $this->verification($id);

        $this->assertSame('applied', $this->pay((int) $v['id'], 'PF-1', 30.0));
        $this->assertSame('duplicate', $this->pay((int) $v['id'], 'PF-1', 30.0));

        $rows = $this->commissions->findAll();
        $this->assertCount(1, $rows);
        $this->assertSame('6.00', $rows[0]['amount'], '20% of R30');
        $this->assertSame(DirectoryPartnerCommissionModel::STATE_PENDING, $rows[0]['state']);
        $this->assertGreaterThan(time() + 86400 * 29, strtotime($rows[0]['available_at']), 'held 30 days');
    }

    public function testAPartnersOwnRateWins(): void
    {
        $p = $this->approvedPartner();
        $this->svc->setRate((int) $p['id'], '35');
        $id = $this->listing();
        $this->svc->attribute($id, $p['code']);

        $this->pay((int) $this->verification($id)['id'], 'PF-RATE', 30.0);

        $this->assertSame('10.50', $this->commissions->first()['amount']);
    }

    public function testNoPartnerMeansNoCommission(): void
    {
        $this->assertSame('applied', $this->pay((int) $this->verification($this->listing())['id'], 'PF-FREE'));
        $this->assertSame(0, $this->commissions->countAllResults());
    }

    public function testPaymentsAfterTheWindowEarnNothing(): void
    {
        $p  = $this->approvedPartner();
        $id = $this->listing();
        $this->svc->attribute($id, $p['code']);
        $v = $this->verification($id);
        $this->pay((int) $v['id'], 'PF-FIRST');

        // Pretend the subscription started 13 months ago, and the credit before it.
        $this->backdate('xs_directory_verifications', (int) $v['id'], 'activated_at', '-13 months');
        $this->backdate('xs_directory_listings', $id, 'partner_attributed_at', '-14 months');
        $this->pay((int) $v['id'], 'PF-LATE');

        $this->assertSame(1, $this->commissions->countAllResults());
    }

    public function testTwelveMonthlyPaymentsEarnAndTheThirteenthDoesNot(): void
    {
        $p  = $this->approvedPartner();
        $id = $this->listing();
        $this->svc->attribute($id, $p['code']);
        $v = $this->verification($id);
        $this->pay((int) $v['id'], 'PF-M1');

        // Start the subscription a year ago, at midnight, so every renewal
        // below lands later in the day than the first payment did.
        $start = strtotime(date('Y-m-d 00:00:00', strtotime('-12 months')));
        db_connect()->table('xs_directory_verifications')->where('id', (int) $v['id'])->update(['activated_at' => date('Y-m-d H:i:s', $start)]);
        $this->backdate('xs_directory_listings', $id, 'partner_attributed_at', '-13 months');

        for ($m = 1; $m <= 11; $m++) {
            $this->assertTrue($this->svc->recordCommission((int) $v['id'], 'PF-M' . ($m + 1), 30.0, date('Y-m-d 15:00:00', strtotime("+{$m} months", $start))), "payment " . ($m + 1));
        }
        $this->assertFalse($this->svc->recordCommission((int) $v['id'], 'PF-M13', 30.0, date('Y-m-d 15:00:00', strtotime('+12 months', $start))), 'the 13th payment');
    }

    public function testPaymentsBeforeTheCreditEarnNothing(): void
    {
        $p  = $this->approvedPartner();
        $id = $this->listing();
        $v  = $this->verification($id);

        $this->assertFalse($this->svc->recordCommission((int) $v['id'], 'PF-EARLY', 30.0, date('Y-m-d H:i:s', strtotime('-1 day'))), 'no partner yet');
        $this->svc->creditListing((int) $p['id'], (string) $this->listings->find($id)['slug']);
        $this->assertFalse($this->svc->recordCommission((int) $v['id'], 'PF-EARLY', 30.0, date('Y-m-d H:i:s', strtotime('-1 day'))), 'paid before the credit');
        $this->assertTrue($this->svc->recordCommission((int) $v['id'], 'PF-AFTER', 30.0));
    }

    public function testASuspendedPartnerEarnsNothing(): void
    {
        $p  = $this->approvedPartner();
        $id = $this->listing();
        $this->svc->attribute($id, $p['code']);
        $this->svc->suspend((int) $p['id'], 'admin@test');

        $this->pay((int) $this->verification($id)['id'], 'PF-SUSP');

        $this->assertSame(0, $this->commissions->countAllResults());
    }

    public function testABrokenCommissionTableNeverCostsThePayment(): void
    {
        $p  = $this->approvedPartner();
        $id = $this->listing();
        $this->svc->attribute($id, $p['code']);
        $v = $this->verification($id);

        $db = db_connect();
        $db->query('RENAME TABLE xs_directory_partner_commissions TO xs_directory_partner_commissions_off');

        try {
            $this->assertSame('applied', $this->pay((int) $v['id'], 'PF-BROKEN'));
        } finally {
            $db->query('RENAME TABLE xs_directory_partner_commissions_off TO xs_directory_partner_commissions');
        }

        $this->assertNotNull((new DirectoryVerificationModel())->find((int) $v['id'])['paid_until'], 'the badge was still paid for');
        $this->assertSame(0, $this->commissions->countAllResults());

        // ...and the sweep rebuilds what the hook missed.
        $this->assertSame(1, $this->svc->sweep()['reconciled']);
        $this->assertSame('PF-BROKEN', $this->commissions->first()['pf_payment_id']);
    }

    // ------------------------------------------------------------------ sweep

    public function testTheSweepReleasesCommissionPastItsHold(): void
    {
        $p  = $this->approvedPartner();
        $id = $this->listing();
        $this->svc->attribute($id, $p['code']);
        $this->pay((int) $this->verification($id)['id'], 'PF-HOLD');

        $this->assertSame(0, $this->svc->sweep()['released'], 'still held');

        $c = $this->commissions->first();
        $this->backdate('xs_directory_partner_commissions', (int) $c['id'], 'available_at', '-1 minute');
        $this->assertSame(1, $this->svc->sweep()['released']);
        $this->assertSame(DirectoryPartnerCommissionModel::STATE_AVAILABLE, $this->commissions->find((int) $c['id'])['state']);
    }

    public function testTheSweepDeletesDeclinedApplicationsAfterAYear(): void
    {
        $this->svc->apply($this->application());
        $p = $this->partners->first();
        $this->svc->reject((int) $p['id'], 'admin@test');
        $this->assertSame(0, $this->svc->sweep()['deleted']);

        $this->backdate('xs_directory_partners', (int) $p['id'], 'decided_at', '-13 months');
        $this->assertSame(1, $this->svc->sweep()['deleted']);
        $this->assertNull($this->partners->find((int) $p['id']));
    }

    // ---------------------------------------------------------------- payouts

    private function availableCommission(array $partner, string $pfId, float $amount): void
    {
        $this->commissions->insert([
            'partner_id'     => (int) $partner['id'],
            'pf_payment_id'  => $pfId,
            'payment_amount' => $amount * 5,
            'rate'           => 20,
            'amount'         => $amount,
            'state'          => DirectoryPartnerCommissionModel::STATE_AVAILABLE,
            'available_at'   => date('Y-m-d H:i:s', strtotime('-1 day')),
        ]);
    }

    public function testAPayoutBelowTheMinimumIsRefused(): void
    {
        $p = $this->approvedPartner();
        $this->availableCommission($p, 'PF-SMALL', 100.0);

        $this->assertSame([], $this->svc->payable());
        $this->assertFalse($this->svc->markPaid((int) $p['id'], 'EFT-1', 'admin@test')['ok']);
        $this->assertSame(0, (new DirectoryPartnerPayoutModel())->countAllResults());
    }

    public function testMarkingPaidSettlesEveryAvailableCommission(): void
    {
        $p = $this->approvedPartner();
        $this->availableCommission($p, 'PF-A', 300.0);
        $this->availableCommission($p, 'PF-B', 250.0);
        $this->sent = [];

        $this->assertCount(1, $this->svc->payable());
        $this->assertFalse($this->svc->markPaid((int) $p['id'], '', 'admin@test')['ok'], 'a reference is required');

        $result = $this->svc->markPaid((int) $p['id'], 'EFT-42', 'admin@test');

        $this->assertTrue($result['ok']);
        $payout = (new DirectoryPartnerPayoutModel())->first();
        $this->assertSame('550.00', $payout['total']);
        $this->assertSame(2, $this->commissions->where('state', DirectoryPartnerCommissionModel::STATE_PAID)->where('payout_id', (int) $payout['id'])->countAllResults());
        $this->assertContains('thandi@agency.test', $this->recipients(), 'the partner gets a statement');
        $this->assertSame([], $this->svc->payable());
    }

    public function testAPaidCommissionCannotBeVoided(): void
    {
        $p = $this->approvedPartner();
        $this->availableCommission($p, 'PF-V', 600.0);
        $c = $this->commissions->first();
        $this->svc->markPaid((int) $p['id'], 'EFT-V', 'admin@test');

        $this->assertFalse($this->svc->void((int) $c['id'], 'refund'));
    }

    // ----------------------------------------------------------- bank details

    public function testBankDetailsAreStoredEncrypted(): void
    {
        config('Encryption')->key = random_bytes(32);
        Services::resetSingle('encrypter');

        $p      = $this->approvedPartner();
        $result = $this->svc->saveBankDetails((int) $p['id'], [
            'holder' => 'T Mokoena', 'bank' => 'FNB', 'branch_code' => '250 655', 'account_number' => '6200 1234 4821', 'account_type' => 'cheque',
        ]);

        $this->assertTrue($result['ok']);
        $stored = $this->partners->find((int) $p['id']);
        $this->assertStringNotContainsString('620012344821', (string) $stored['bank_details']);
        $this->assertSame('620012344821', $this->svc->bankDetails($stored)['account_number']);
        $this->assertSame('FNB, account ending 4821', $this->svc->bankSummary($stored));
    }

    public function testWithoutAKeyBankDetailsAreRefusedNotStoredPlain(): void
    {
        config('Encryption')->key = '';
        Services::resetSingle('encrypter');

        $p      = $this->approvedPartner();
        $result = $this->svc->saveBankDetails((int) $p['id'], [
            'holder' => 'T Mokoena', 'bank' => 'FNB', 'branch_code' => '250655', 'account_number' => '62001234482',
        ]);

        $this->assertFalse($result['ok']);
        $this->assertNull($this->partners->find((int) $p['id'])['bank_details']);
    }
}

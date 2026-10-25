<?php

use App\Libraries\PayFast;
use App\Models\DirectoryCategoryModel;
use App\Models\DirectoryListingModel;
use App\Models\DirectoryVerificationModel;
use App\Services\DirectoryListingMutationService;
use App\Services\DirectorySettings;
use App\Services\VerificationService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Countries;
use Tests\Support\ValidListingInput;
use Tests\Support\WithEnv;

/**
 * The International Listing plan: a listing outside South Africa publishes
 * only while it is paid for.
 *
 * What these pin, in order of how expensive each would be to get wrong:
 *
 *  1. A South African listing is completely untouched. Free, always, and every
 *     assertion about the badge still holds. This is the promise the FAQ and
 *     the terms make in writing.
 *  2. Verifying an email and paying to be hosted are different questions.
 *     A foreign signup confirms its address — is_verified goes to 1 — and
 *     still does not publish.
 *  3. Nothing is ever deleted. A lapse unpublishes; paying again brings the
 *     listing back exactly as it was, because the alternative is a business
 *     losing its profile over a declined card.
 *  4. A listing can hold this plan AND the badge. That is what the
 *     (listing_id, plan) unique index is for, and the old single-column one
 *     would have made it impossible.
 *
 * Requires the `tests` database group — see the Tests section of README.md.
 *
 * @internal
 */
final class InternationalListingTest extends CIUnitTestCase
{
    use ValidListingInput;
    use DatabaseTestTrait;
    use \CodeIgniter\Test\FeatureTestTrait;
    use WithEnv;

    protected $namespace   = 'App';
    protected $migrate     = true;
    protected $migrateOnce = false;
    protected $refresh     = true;

    private VerificationService $svc;
    private DirectoryListingMutationService $mutations;
    private DirectoryVerificationModel $verifications;
    private DirectoryListingModel $listings;
    private int $categoryId;

    protected function setUp(): void
    {
        parent::setUp();
        // The settings map is cached for 24h in a store the DB refresh does not
        // touch, so a price or flag written by an earlier test class would
        // otherwise still be in play here.
        (new DirectorySettings())->forget();

        $this->svc           = new VerificationService();
        $this->mutations     = new DirectoryListingMutationService();
        $this->verifications = new DirectoryVerificationModel();
        $this->listings      = new DirectoryListingModel();

        $this->categoryId = (int) (new DirectoryCategoryModel())->insert([
            'name'       => 'Bakeries',
            'slug'       => 'bakeries',
            'group_name' => 'Food & Drink',
            'is_active'  => 1,
        ], true);
    }

    protected function tearDown(): void
    {
        $this->restoreEnv();
        \Config\Services::resetSingle('site');
        (new DirectorySettings())->forget();
        parent::tearDown();
    }

    // ------------------------------------------------- South Africa is free

    public function testASouthAfricanListingPublishesOnVerificationAsAlways(): void
    {
        [$id, $token] = $this->signup(local: true);

        $this->mutations->verify($token);

        $row = $this->listings->find($id);
        $this->assertSame('published', $row['status']);
        $this->assertSame(1, (int) $row['is_verified']);
        $this->assertNull($row['hosting_paid_until']);
        $this->assertFalse($this->svc->requiresSubscription($row));
    }

    // --------------------------------------------- abroad: verified, not live

    public function testAForeignListingVerifiesItsEmailButDoesNotPublish(): void
    {
        [$id, $token] = $this->signup(local: false);

        $this->mutations->verify($token);

        $row = $this->listings->find($id);
        // The address IS proven — that is all is_verified has ever meant, and
        // conflating it with payment would mean re-sending verification mail
        // to someone who has already clicked it.
        $this->assertSame(1, (int) $row['is_verified']);
        $this->assertSame('pending', $row['status']);
        $this->assertNull($row['published_at']);
        $this->assertTrue($this->svc->requiresSubscription($row));
        $this->assertFalse($this->svc->mayPublish($row));
    }

    public function testTheSubscriptionIsOpenedApprovedAndPayableWithNoReview(): void
    {
        [$id] = $this->signup(local: false);

        $row = $this->svc->ensureInternationalSubscription($id);

        $this->assertIsArray($row);
        // Straight to payable. The badge reaches this state only after an admin
        // has looked at two documents; there is nothing to look at here.
        $this->assertSame(DirectoryVerificationModel::STATE_APPROVED, $row['state']);
        $this->assertSame(DirectoryVerificationModel::PLAN_INTERNATIONAL, $row['plan']);
        $this->assertSame($this->svc->internationalAmount(), number_format((float) $row['amount'], 2, '.', ''));
    }

    public function testOpeningTheSubscriptionTwiceDoesNotCreateASecondRow(): void
    {
        [$id] = $this->signup(local: false);

        $first  = $this->svc->ensureInternationalSubscription($id);
        $second = $this->svc->ensureInternationalSubscription($id);

        $this->assertSame((int) $first['id'], (int) $second['id']);
        $this->assertSame(1, $this->verifications->where('listing_id', $id)->countAllResults());
    }

    public function testASouthAfricanListingIsNeverGivenASubscription(): void
    {
        [$id] = $this->signup(local: true);

        $this->assertNull($this->svc->ensureInternationalSubscription($id));
        $this->assertSame(0, $this->verifications->where('listing_id', $id)->countAllResults());
    }

    // ------------------------------------------------------------- payment

    public function testPayingPublishesTheListing(): void
    {
        [$id, $token] = $this->signup(local: false);
        $this->mutations->verify($token);
        $row = $this->svc->ensureInternationalSubscription($id);

        $this->assertSame('applied', $this->pay($row, 'PF-INTL-1'));

        $listing = $this->listings->find($id);
        $this->assertSame('published', $listing['status']);
        $this->assertNotNull($listing['published_at']);
        $this->assertNotNull($listing['hosting_paid_until']);
        $this->assertTrue($this->svc->subscriptionActive($listing));

        // The badge column is a different plan's and must not have moved.
        $this->assertNull($listing['verified_until']);
    }

    public function testPayingDoesNotPublishAListingWhoseEmailIsUnconfirmed(): void
    {
        // Money does not answer the question the verification email asks.
        [$id] = $this->signup(local: false);
        $row  = $this->svc->ensureInternationalSubscription($id);

        $this->pay($row, 'PF-INTL-2');

        $listing = $this->listings->find($id);
        $this->assertSame('pending', $listing['status']);
        $this->assertNotNull($listing['hosting_paid_until']);
    }

    // ---------------------------------------------------------- lapse/return

    public function testLapsingUnpublishesTheListingAndDeletesNothing(): void
    {
        [$id, $token] = $this->signup(local: false);
        $this->mutations->verify($token);
        $row = $this->svc->ensureInternationalSubscription($id);
        $this->pay($row, 'PF-INTL-3');

        // Back-date the subscription and run what the nightly sweep runs.
        $this->verifications->update((int) $row['id'], ['paid_until' => date('Y-m-d', strtotime('-1 day'))]);
        $this->assertSame(1, $this->svc->lapseExpired());

        $listing = $this->listings->find($id);
        $this->assertSame('unpublished', $listing['status']);
        $this->assertNull($listing['hosting_paid_until']);

        // Still there, in full. This is the part that matters: a declined card
        // must not cost anyone the profile they built.
        $this->assertSame('Brezel Bakery', $listing['display_name']);
        $this->assertSame('Marienplatz 1', $listing['address_line']);
        $this->assertSame('Bavaria', $listing['region']);
    }

    public function testPayingAgainAfterALapseBringsTheListingBack(): void
    {
        [$id, $token] = $this->signup(local: false);
        $this->mutations->verify($token);
        $row = $this->svc->ensureInternationalSubscription($id);
        $this->pay($row, 'PF-INTL-4');
        $this->verifications->update((int) $row['id'], ['paid_until' => date('Y-m-d', strtotime('-1 day'))]);
        $this->svc->lapseExpired();

        $lapsed = $this->verifications->find((int) $row['id']);
        $this->assertSame(DirectoryVerificationModel::STATE_LAPSED, $lapsed['state']);

        $this->pay($lapsed, 'PF-INTL-5');

        $listing = $this->listings->find($id);
        $this->assertSame('published', $listing['status']);
        $this->assertTrue($this->svc->subscriptionActive($listing));
    }

    public function testLapsingABadgeStillLeavesTheListingPublished(): void
    {
        // The two plans must not have learned each other's consequences.
        [$id, $token] = $this->signup(local: true);
        $this->mutations->verify($token);

        $badgeId = (int) $this->verifications->insert([
            'listing_id' => $id,
            'plan'       => DirectoryVerificationModel::PLAN_BADGE,
            'state'      => DirectoryVerificationModel::STATE_ACTIVE,
            'amount'     => $this->svc->monthlyAmount(),
            'paid_until' => date('Y-m-d', strtotime('-1 day')),
        ], true);
        $this->listings->update($id, ['verified_until' => date('Y-m-d', strtotime('-1 day'))]);

        $this->assertSame(1, $this->svc->lapseExpired());

        $listing = $this->listings->find($id);
        $this->assertSame('published', $listing['status']);
        $this->assertNull($listing['verified_until']);
        $this->assertSame(DirectoryVerificationModel::STATE_LAPSED, $this->verifications->find($badgeId)['state']);
    }

    // ------------------------------------------------------- both at once

    public function testAListingMayHoldBothPlans(): void
    {
        // The whole reason listing_id stopped being UNIQUE on its own. If the
        // old index is ever restored, this is the test that says so.
        [$id, $token] = $this->signup(local: false);
        $this->mutations->verify($token);

        $this->assertIsArray($this->svc->ensureInternationalSubscription($id));

        $this->verifications->insert([
            'listing_id' => $id,
            'plan'       => DirectoryVerificationModel::PLAN_BADGE,
            'state'      => DirectoryVerificationModel::STATE_SUBMITTED,
            'amount'     => $this->svc->monthlyAmount(),
        ]);

        $this->assertSame(2, $this->verifications->where('listing_id', $id)->countAllResults());
        $this->assertSame(
            DirectoryVerificationModel::PLAN_INTERNATIONAL,
            $this->svc->forListing($id, DirectoryVerificationModel::PLAN_INTERNATIONAL)['verification']['plan']
        );
        $this->assertSame(
            DirectoryVerificationModel::PLAN_BADGE,
            $this->svc->forListing($id, DirectoryVerificationModel::PLAN_BADGE)['verification']['plan']
        );
    }

    // ------------------------------------------------------- the off switch

    public function testWithThePlanSwitchedOffAForeignListingPublishesFree(): void
    {
        // The deliberate failure mode: switching the plan off must never leave
        // a listing stuck pending with no way to pay for it.
        (new DirectorySettings())->save([
            'international_price'   => '29.99',
            'international_enabled' => null,
        ], 'test');

        [$id, $token] = $this->signup(local: false);
        $this->mutations->verify($token);

        $listing = $this->listings->find($id);
        $this->assertSame('published', $listing['status']);
        $this->assertFalse((new VerificationService())->requiresSubscription($listing));
    }

    // ---------------------------------- Plus (the plan once .app is live)

    public function testWithTheAppLiveAForeignListingPublishesFreeAndPlusIsOptional(): void
    {
        $this->goLiveWithPlus();

        [$id, $token] = $this->signup(local: false);
        $this->mutations->verify($token);

        $listing = $this->listings->find($id);
        $this->assertSame('published', $listing['status']);
        $svc = new VerificationService();
        $this->assertFalse($svc->requiresSubscription($listing));
        $this->assertTrue($svc->plusAvailableFor($listing));
        $this->assertFalse(listing_has_plus($listing));
    }

    public function testConfirmingAProfileAbroadLandsOnThePlusPanel(): void
    {
        $this->goLiveWithPlus();

        [, $token] = $this->signup(local: false);
        $result = $this->get('directory/verify/' . $token);
        $result->assertRedirectTo(base_url('manage/edit') . '#get-plus');
        $this->assertStringContainsString('$2.99 a month', (string) session('success'));
        $this->assertStringNotContainsString('Verified', (string) session('success'));

        // A South African profile never hears about Plus.
        [, $token] = $this->signup(local: true);
        $result = $this->get('directory/verify/' . $token);
        $this->assertStringNotContainsString('#get-plus', (string) $result->getRedirectUrl());
    }

    public function testTheDashboardOffersPlusToAProfileAbroad(): void
    {
        $this->goLiveWithPlus();

        [$abroad, $token] = $this->signup(local: false);
        $this->mutations->verify($token);
        $html = $this->withSession([\App\Controllers\Manage::SESSION_KEY => $abroad])->get('manage/edit')->getBody();
        $this->assertStringContainsString('id="get-plus"', $html);
        $this->assertStringContainsString('manage/verification/checkout?plan=plus', $html);

        [$local, $token] = $this->signup(local: true);
        $this->mutations->verify($token);
        $html = $this->withSession([\App\Controllers\Manage::SESSION_KEY => $local])->get('manage/edit')->getBody();
        $this->assertStringNotContainsString('id="get-plus"', $html);
    }

    public function testPlusIsChargedInRandAtTheOperatorsRate(): void
    {
        $this->goLiveWithPlus();

        $this->assertSame('54.42', (new DirectorySettings())->plusRandAmount(), 'US$2.99 at R18.20');

        [$id, $token] = $this->signup(local: false);
        $this->mutations->verify($token);
        $row = (new VerificationService())->ensureInternationalSubscription($id);

        $this->assertSame('54.42', number_format((float) $row['amount'], 2, '.', ''));
        $this->assertSame(DirectoryVerificationModel::STATE_APPROVED, $row['state'], 'no document review');
    }

    public function testPayingForPlusUnlocksBranchesTeamAndJobs(): void
    {
        $this->goLiveWithPlus();
        helper('directory_ui');

        [$id, $token] = $this->signup(local: false);
        $this->mutations->verify($token);
        $before = $this->listings->find($id);
        $this->assertFalse((new \App\Services\TeamMemberService())->canManage($before));
        $this->assertFalse((new \App\Services\JobBoardService())->canUseJobsFeatures($before));

        $svc = new VerificationService();
        $this->assertSame('applied', $this->payWith($svc, $svc->ensureInternationalSubscription($id), 'PF-PLUS-1'));

        $after = $this->listings->find($id);
        $this->assertTrue(listing_has_plus($after));
        $this->assertFalse(listing_is_verified_business($after), 'Plus is not the Verified badge');
        $this->assertTrue((new \App\Services\TeamMemberService())->canManage($after));
        $this->assertTrue((new \App\Services\PracticeLocationService())->canManage($after));
        $this->assertTrue((new \App\Services\JobBoardService())->canUseJobsFeatures($after));
    }

    public function testPlusLapsingTakesTheExtrasNotTheProfile(): void
    {
        $this->goLiveWithPlus();

        [$id, $token] = $this->signup(local: false);
        $this->mutations->verify($token);
        $svc = new VerificationService();
        $this->payWith($svc, $svc->ensureInternationalSubscription($id), 'PF-PLUS-2');
        $this->verifications->where('listing_id', $id)->set(['paid_until' => date('Y-m-d', strtotime('-1 day'))])->update();

        $svc->lapseExpired();

        $listing = $this->listings->find($id);
        $this->assertSame('published', $listing['status']);
        $this->assertNull($listing['hosting_paid_until']);
        $this->assertFalse(listing_has_plus($listing));
    }

    public function testCancellingPlusKeepsTheProfileAndSaysSo(): void
    {
        $this->goLiveWithPlus();

        [$id, $token] = $this->signup(local: false);
        $this->mutations->verify($token);
        $payfast = $this->payFast(cancelSucceeds: true);
        $svc     = new VerificationService($payfast);
        $this->payWith($svc, $svc->ensureInternationalSubscription($id), 'PF-PLUS-3');

        $result = $svc->cancelSubscription($id, DirectoryVerificationModel::PLAN_INTERNATIONAL);

        $this->assertTrue($result['ok']);
        $this->assertStringContainsString('your profile stays published', $result['message']);
        $this->assertSame(['tok-intl'], $payfast->cancelled);
        $this->assertSame('published', $this->listings->find($id)['status']);
    }

    public function testAnUnpaidPlusCheckoutUsesTodaysPrice(): void
    {
        $this->goLiveWithPlus();
        $this->setEnv('directory.payfastMerchantId', '10000100');
        $this->setEnv('directory.payfastMerchantKey', '46f0cd694581a');
        $this->setEnv('directory.payfastSandbox', 'true');

        [$id, $token] = $this->signup(local: false);
        $this->mutations->verify($token);
        $row = (new VerificationService())->ensureInternationalSubscription($id);
        $this->assertSame('54.42', number_format((float) $row['amount'], 2, '.', ''));

        // The operator raises Plus to US$12.99 before the owner pays.
        (new DirectorySettings())->save([
            'international_price' => '29.99', 'international_enabled' => '1',
            'plus_price_usd' => '12.99', 'usd_zar_rate' => '18.20',
        ], 'test');

        \Config\Services::resetSingle('site');
        $page = $this->withSession([\App\Controllers\Manage::SESSION_KEY => $id])->get('manage/verification/checkout?plan=plus');
        $page->assertOK();
        // Dollars on the page, never a rand figure (it moves with the rate);
        // only that it is billed in rand. The rand charged is on the row.
        $this->assertStringContainsString('$12.99', (string) $page->getBody());
        $this->assertStringContainsString('billed in South African rand', (string) $page->getBody());
        $this->assertStringNotContainsString('R236.42', (string) $page->getBody());
        $this->assertSame('236.42', number_format((float) $this->verifications->find((int) $row['id'])['amount'], 2, '.', ''));
    }

    public function testTheSetupServiceIsSoldInDollarsOnTheApp(): void
    {
        $this->goLiveWithPlus();
        \Config\Services::injectMock('site', new \App\Services\SiteContext('local.webscheduler.app'));

        $svc = new \App\Services\ProfileSetupService();
        $this->assertSame('39.99', $svc->usdAmount());
        $this->assertSame('727.82', $svc->amount(), 'US$39.99 at R18.20');

        // co.za keeps its rand price and shows no dollars.
        \Config\Services::injectMock('site', new \App\Services\SiteContext(null));
        $this->assertNull((new \App\Services\ProfileSetupService())->usdAmount());
        $this->assertSame(config('Directory')->profileSetupAmount(), (new \App\Services\ProfileSetupService())->amount());
    }

    public function testPayFastSendsAnAppOwnerBackToTheApp(): void
    {
        $this->goLiveWithPlus();

        [$id, $token] = $this->signup(local: false);
        $this->mutations->verify($token);
        $row    = (new VerificationService())->ensureInternationalSubscription($id) + ['pf_m_payment_id' => 'il-test'];
        $fields = (new PayFast())->subscriptionFields($this->listings->find($id), $row);

        $this->assertSame('local.webscheduler.app', parse_url($fields['return_url'], PHP_URL_HOST));
        $this->assertNotSame('local.webscheduler.app', parse_url($fields['notify_url'], PHP_URL_HOST), 'the ITN stays on co.za');
        $this->assertSame('WebScheduler Local Plus', $fields['item_name']);
    }

    public function testBeforeLaunchTheInternationalPlanIsUnchanged(): void
    {
        // co.za today: a foreign listing still pays to be published, and its
        // subscription unlocks nothing else.
        [$id, $token] = $this->signup(local: false);
        $this->mutations->verify($token);

        $listing = $this->listings->find($id);
        $this->assertTrue((new VerificationService())->requiresSubscription($listing));
        $this->assertNotSame('published', $listing['status']);
        $this->assertFalse((new VerificationService())->plusOffered());
    }

    // ------------------------------------------------------------ fixtures

    /**
     * A verified-pending signup, returning its id and the raw verify token.
     *
     * Goes through submitPublic() rather than inserting a row, because the
     * point of most of these tests is what the real intake does.
     *
     * @return array{0:int,1:string}
     */
    private function signup(bool $local): array
    {
        $input = [
            'display_name' => $local ? 'Cape Bakery' : 'Brezel Bakery',
            'email'        => 'intl-' . bin2hex(random_bytes(4)) . '@example.test',
            'category_id'  => $this->categoryId,
            // The private "Your details" pair — compulsory on both write
            // paths since they became required; see validate().
            'title'          => 'Mr',
            'contact_person' => 'Test Owner',
            'consent'      => 1,
            // A new signup must ANSWER the marketing question; '0' is a
            // complete answer and is what an untouched form used to mean.
            'marketing_opt_in' => '0',
            'country'      => $local ? Countries::SOUTH_AFRICA : 'Germany',
            'address_line' => $local ? '1 Adderley Street' : 'Marienplatz 1',
            'city'         => $local ? 'Cape Town' : 'Munich',
            'postal_code'  => $local ? '8001' : '80331',
            'province'     => $local ? 'Western Cape' : '',
            'region'       => $local ? '' : 'Bavaria',
            // Keeps ListingGeocoder off the network in both cases.
            'latitude'     => $local ? '-33.9249' : '48.1351',
            'longitude'    => $local ? '18.4241' : '11.5820',
        ];

        $result = $this->mutations->submitPublic($this->withRequiredSections($input));
        $this->assertTrue($result['ok'], $result['message']);

        // submitPublic() stores only the hash; the raw token lives in the
        // emailed URL. Read it back the way the mail would have carried it.
        $token = $this->rawVerifyToken((int) $result['id']);

        return [(int) $result['id'], $token];
    }

    /**
     * There is no way to read a raw token back out of the database by design,
     * so mint a known one and store its hash the same way the service does.
     */
    private function rawVerifyToken(int $listingId): string
    {
        $raw = bin2hex(random_bytes(32));

        $this->listings->update($listingId, [
            'verify_token'   => hash('sha256', $raw),
            'verify_expires' => date('Y-m-d H:i:s', time() + 3600),
        ]);

        return $raw;
    }

    /** @param array<string,mixed> $verification */
    /** local.webscheduler.app live, with Plus priced at US$2.99 and R18.20 to the dollar. */
    private function goLiveWithPlus(): void
    {
        $this->setEnv('sites.app.enabled', 'true');
        \Config\Services::resetSingle('site');
        (new DirectorySettings())->save([
            'international_price'   => '29.99',
            'international_enabled' => '1',
            'plus_price_usd'        => '2.99',
            'usd_zar_rate'          => '18.20',
        ], 'test');
    }

    private function payWith(VerificationService $svc, array $verification, string $paymentId): string
    {
        $amount = number_format((float) $verification['amount'], 2, '.', '');

        return $svc->recordPayment($verification, $paymentId, 'COMPLETE', (float) $amount,
            ['pf_payment_id' => $paymentId, 'amount_gross' => $amount, 'token' => 'tok-intl']);
    }

    private function switchThePlanOff(): void
    {
        (new DirectorySettings())->save(['international_price' => '29.99', 'international_enabled' => null], 'test');
    }

    /** A PayFast whose subscription cancel answers $cancelSucceeds and records the token. */
    private function payFast(bool $cancelSucceeds): PayFast
    {
        return new class ($cancelSucceeds) extends PayFast {
            /** @var list<string> */
            public array $cancelled = [];

            public function __construct(private readonly bool $succeeds)
            {
                parent::__construct();
            }

            public function cancelSubscription(string $token): bool
            {
                $this->cancelled[] = $token;

                return $this->succeeds;
            }
        };
    }

    private function pay(array $verification, string $paymentId): string
    {
        $amount = number_format((float) $verification['amount'], 2, '.', '');

        return $this->svc->recordPayment(
            $verification,
            $paymentId,
            'COMPLETE',
            (float) $amount,
            ['pf_payment_id' => $paymentId, 'amount_gross' => $amount, 'token' => 'tok-intl']
        );
    }
}

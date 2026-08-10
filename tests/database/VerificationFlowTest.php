<?php

use App\Libraries\PayFast;
use App\Models\DirectoryListingModel;
use App\Models\DirectoryVerificationEventModel;
use App\Models\DirectoryVerificationModel;
use App\Services\VerificationService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Directory as DirectoryConfig;

/**
 * The Verified Business state machine and, above all, the money.
 *
 * The single most important case here is testARepeatedNotificationIsANoOp().
 * PayFast re-sends a notification until it gets a 200, and a timeout between our
 * commit and our response is enough to produce a second delivery of a payment we
 * have already banked. If that extended the subscription again, every subscriber
 * with a flaky connection would slowly accumulate free months, and nothing in
 * the UI would ever show it.
 *
 * Requires the `tests` database group — see the Tests section of README.md.
 *
 * @internal
 */
final class VerificationFlowTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $namespace   = 'App';
    protected $migrate     = true;
    protected $migrateOnce = false;
    protected $refresh     = true;

    private VerificationService $svc;
    private DirectoryVerificationModel $verifications;
    private DirectoryListingModel $listings;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc           = new VerificationService();
        $this->verifications = new DirectoryVerificationModel();
        $this->listings      = new DirectoryListingModel();
    }

    private function makeListing(): int
    {
        return (int) $this->listings->insert([
            'display_name' => 'Verification Test Co',
            'email'        => 'verif-' . bin2hex(random_bytes(4)) . '@example.test',
            'slug'         => 'verif-' . bin2hex(random_bytes(6)),
            'status'       => 'published',
        ], true);
    }

    /**
     * Document rows only — the files themselves are VerificationDocumentProcessor's
     * business and are covered by its own unit test.
     *
     * @return array<string,array{name:string,mime:string,bytes:int,original_name:string}>
     */
    private function documents(): array
    {
        return [
            'company_registration' => ['name' => 'verif_a.pdf', 'mime' => 'application/pdf', 'bytes' => 100, 'original_name' => 'cipc.pdf'],
            'owner_id'             => ['name' => 'verif_b.png', 'mime' => 'image/png', 'bytes' => 200, 'original_name' => 'id.png'],
        ];
    }

    private function applyAndApprove(int $listingId): array
    {
        $this->svc->submitApplication($listingId, $this->documents());
        $row = $this->verifications->forListing($listingId);
        $this->svc->approve((int) $row['id'], 'admin@test');

        return $this->verifications->forListing($listingId);
    }

    /**
     * A PayFast stand-in whose two outward behaviours can be dictated.
     *
     * Only `isConfigured()` and `cancelSubscription()` are overridden — the
     * signature and URL logic underneath stays real, so this cannot quietly
     * paper over a bug in either. `cancelSubscription()` in particular does a
     * live HTTP PUT in production, which is exactly what a test must not do and
     * exactly why the failure branch would otherwise be unreachable.
     */
    private function payfast(bool $configured = true, bool $cancelSucceeds = true): PayFast
    {
        $config                     = new DirectoryConfig();
        $config->payfastMerchantId  = $configured ? '10000100' : '';
        $config->payfastMerchantKey = $configured ? '46f0cd694581a' : '';

        return new class ($config, $configured, $cancelSucceeds) extends PayFast {
            public function __construct(DirectoryConfig $config, private bool $configured, private bool $cancelSucceeds)
            {
                parent::__construct($config);
            }

            public function isConfigured(): bool
            {
                return $this->configured;
            }

            public function cancelSubscription(string $token): bool
            {
                return $this->cancelSucceeds;
            }
        };
    }

    /** Defaults to the configured price, so a price change does not break the suite. */
    private function pay(array $verification, string $paymentId, ?string $amount = null, string $status = 'COMPLETE'): string
    {
        $amount ??= $this->svc->monthlyAmount();

        return $this->svc->recordPayment(
            $verification,
            $paymentId,
            $status,
            (float) $amount,
            ['pf_payment_id' => $paymentId, 'amount_gross' => $amount, 'token' => 'tok-123']
        );
    }

    // ------------------------------------------------------------ application

    public function testApplyingStoresBothDocumentsAndQueuesForReview(): void
    {
        $listingId = $this->makeListing();

        $result = $this->svc->submitApplication($listingId, $this->documents());
        $this->assertTrue($result['ok'], $result['message']);

        $found = $this->svc->forListing($listingId);
        $this->assertSame(DirectoryVerificationModel::STATE_SUBMITTED, $found['verification']['state']);
        $this->assertCount(2, $found['documents']);
        // Asserted against the configured price rather than a literal: what
        // matters is that the row captures the price at application time, not
        // what that price happens to be this quarter.
        $this->assertSame(
            $this->svc->monthlyAmount(),
            $found['verification']['amount'],
            'the price is snapshotted at application time'
        );
        $this->assertMatchesRegularExpression('/^\d+\.\d{2}$/', $found['verification']['amount'], 'PayFast needs two decimals');

        // No badge yet — documents alone buy nothing.
        $this->assertNull($this->listings->find($listingId)['verified_until']);
    }

    /** An application an admin could not act on is worse than none. */
    public function testOneDocumentIsRefused(): void
    {
        $listingId = $this->makeListing();
        $partial   = ['company_registration' => $this->documents()['company_registration']];

        $result = $this->svc->submitApplication($listingId, $partial);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('both documents', $result['message']);
        $this->assertNull($this->verifications->forListing($listingId), 'no half-application should be stored');
    }

    public function testAnActiveSubscriberCannotKnockTheirOwnBadgeBackIntoReview(): void
    {
        $listingId = $this->makeListing();
        $this->pay($this->applyAndApprove($listingId), 'PF-1');

        $result = $this->svc->submitApplication($listingId, $this->documents());

        $this->assertFalse($result['ok']);
        $this->assertSame(DirectoryVerificationModel::STATE_ACTIVE, $this->verifications->forListing($listingId)['state']);
        $this->assertNotNull($this->listings->find($listingId)['verified_until'], 'the badge must survive');
    }

    // --------------------------------------------------------------- decisions

    public function testApprovingInvitesPaymentButGrantsNothing(): void
    {
        $listingId = $this->makeListing();
        $row       = $this->applyAndApprove($listingId);

        $this->assertSame(DirectoryVerificationModel::STATE_APPROVED, $row['state']);
        $this->assertSame('admin@test', $row['reviewed_by']);
        $this->assertNotNull($row['reviewed_at']);
        $this->assertNull($this->listings->find($listingId)['verified_until'], 'approval is not a badge');
    }

    public function testRejectingStoresTheReasonAndGrantsNothing(): void
    {
        $listingId = $this->makeListing();
        $this->svc->submitApplication($listingId, $this->documents());
        $row = $this->verifications->forListing($listingId);

        $this->assertTrue($this->svc->reject((int) $row['id'], 'The ID photo is cut off at the edge.', 'admin@test'));

        $after = $this->verifications->forListing($listingId);
        $this->assertSame(DirectoryVerificationModel::STATE_REJECTED, $after['state']);
        $this->assertSame('The ID photo is cut off at the edge.', $after['rejection_reason']);
        $this->assertNull($this->listings->find($listingId)['verified_until']);
    }

    public function testRejectingWithoutAReasonIsRefused(): void
    {
        $listingId = $this->makeListing();
        $this->svc->submitApplication($listingId, $this->documents());
        $row = $this->verifications->forListing($listingId);

        $this->assertFalse($this->svc->reject((int) $row['id'], '   ', 'admin@test'));
        $this->assertSame(DirectoryVerificationModel::STATE_SUBMITTED, $this->verifications->forListing($listingId)['state']);
    }

    /** The service's booleans drive the admin flash message, so they must be truthful. */
    public function testDecisionsOnlyApplyToAnApplicationAwaitingReview(): void
    {
        $listingId = $this->makeListing();
        $row       = $this->applyAndApprove($listingId);

        $this->assertFalse($this->svc->approve((int) $row['id'], 'admin@test'), 'already approved');
        $this->assertFalse($this->svc->reject((int) $row['id'], 'too late', 'admin@test'), 'already approved');
        $this->assertFalse($this->svc->approve(999999, 'admin@test'), 'no such application');
    }

    public function testReSubmittingAfterRejectionReusesTheRowAndClearsTheReason(): void
    {
        $listingId = $this->makeListing();
        $this->svc->submitApplication($listingId, $this->documents());
        $first = $this->verifications->forListing($listingId);
        $this->svc->reject((int) $first['id'], 'Too blurry.', 'admin@test');

        $this->assertTrue($this->svc->submitApplication($listingId, $this->documents())['ok']);

        $second = $this->verifications->forListing($listingId);
        $this->assertSame((int) $first['id'], (int) $second['id'], 'one row per listing throughout');
        $this->assertSame(DirectoryVerificationModel::STATE_SUBMITTED, $second['state']);
        $this->assertNull($second['rejection_reason']);
        $this->assertCount(2, $this->svc->forListing($listingId)['documents'], 'superseded documents are replaced, not appended');
    }

    // ----------------------------------------------------------------- payment

    public function testFirstPaymentActivatesTheBadgeForOneMonth(): void
    {
        $listingId = $this->makeListing();

        $this->assertSame('applied', $this->pay($this->applyAndApprove($listingId), 'PF-1'));

        $row = $this->verifications->forListing($listingId);
        $this->assertSame(DirectoryVerificationModel::STATE_ACTIVE, $row['state']);
        $this->assertSame(date('Y-m-d', strtotime('+1 month')), $row['paid_until']);
        $this->assertSame('tok-123', $row['pf_subscription_token']);
        $this->assertNotNull($row['activated_at']);

        // The column the public badge actually renders from.
        $this->assertSame($row['paid_until'], $this->listings->find($listingId)['verified_until']);
    }

    /**
     * The one that protects the revenue. Same pf_payment_id twice must change
     * nothing at all the second time.
     */
    public function testARepeatedNotificationIsANoOp(): void
    {
        $listingId = $this->makeListing();
        $this->pay($this->applyAndApprove($listingId), 'PF-DUPLICATE');

        $afterFirst = $this->verifications->forListing($listingId);

        $this->assertSame('duplicate', $this->pay($afterFirst, 'PF-DUPLICATE'));

        $afterSecond = $this->verifications->forListing($listingId);
        $this->assertSame($afterFirst['paid_until'], $afterSecond['paid_until'], 'a replay must not buy another month');
        $this->assertCount(1, (new DirectoryVerificationEventModel())->forVerification((int) $afterFirst['id']));
    }

    /** A genuine second cycle extends from paid_until, not from today. */
    public function testASecondCycleExtendsFromTheDateAlreadyPaidTo(): void
    {
        $listingId = $this->makeListing();
        $this->pay($this->applyAndApprove($listingId), 'PF-1');

        $afterFirst = $this->verifications->forListing($listingId);
        $this->assertSame('applied', $this->pay($afterFirst, 'PF-2'));

        $this->assertSame(
            date('Y-m-d', strtotime($afterFirst['paid_until'] . ' +1 month')),
            $this->verifications->forListing($listingId)['paid_until']
        );
    }

    /**
     * A subscriber who lapsed and came back starts from today. Extending from a
     * long-past paid_until would hand them a badge that expired before it was
     * bought.
     */
    public function testRenewingAfterALapseStartsFromToday(): void
    {
        $listingId = $this->makeListing();
        $row       = $this->applyAndApprove($listingId);
        $this->pay($row, 'PF-1');

        $this->verifications->update((int) $row['id'], [
            'state'      => DirectoryVerificationModel::STATE_LAPSED,
            'paid_until' => date('Y-m-d', strtotime('-6 months')),
        ]);

        $this->pay($this->verifications->forListing($listingId), 'PF-COMEBACK');

        $after = $this->verifications->forListing($listingId);
        $this->assertSame(date('Y-m-d', strtotime('+1 month')), $after['paid_until']);
        $this->assertSame(DirectoryVerificationModel::STATE_ACTIVE, $after['state']);
    }

    /** A cancellation carries no money and must not extend anything. */
    public function testACancellationDoesNotShortenOrExtendThePaidPeriod(): void
    {
        $listingId = $this->makeListing();
        $this->pay($this->applyAndApprove($listingId), 'PF-1');
        $afterPayment = $this->verifications->forListing($listingId);

        $this->svc->recordPayment($afterPayment, 'PF-CANCEL', 'CANCELLED', null, ['pf_payment_id' => 'PF-CANCEL']);

        $after = $this->verifications->forListing($listingId);
        $this->assertSame($afterPayment['paid_until'], $after['paid_until'], 'they paid for this month');
        $this->assertSame(DirectoryVerificationModel::STATE_ACTIVE, $after['state']);
    }

    // ------------------------------------------------------------------ lapse

    public function testTheSweepLapsesAnExpiredSubscriptionAndClearsTheBadge(): void
    {
        $listingId = $this->makeListing();
        $row       = $this->applyAndApprove($listingId);
        $this->pay($row, 'PF-1');

        $expired = date('Y-m-d', strtotime('-1 day'));
        $this->verifications->update((int) $row['id'], ['paid_until' => $expired]);
        $this->listings->update($listingId, ['verified_until' => $expired]);

        $this->assertSame(1, $this->svc->lapseExpired());

        $this->assertSame(DirectoryVerificationModel::STATE_LAPSED, $this->verifications->forListing($listingId)['state']);
        $this->assertNull($this->listings->find($listingId)['verified_until']);
    }

    public function testTheSweepLeavesCurrentSubscriptionsAlone(): void
    {
        $listingId = $this->makeListing();
        $this->pay($this->applyAndApprove($listingId), 'PF-1');

        $this->assertSame(0, $this->svc->lapseExpired());
        $this->assertSame(DirectoryVerificationModel::STATE_ACTIVE, $this->verifications->forListing($listingId)['state']);
    }

    /**
     * The badge hides on the right day whether or not the sweep ever runs —
     * this is the property that makes the claim honest, so it is worth pinning.
     */
    public function testTheBadgeHidesOnExpiryWithoutTheSweepRunning(): void
    {
        helper('directory_ui');

        $listingId = $this->makeListing();
        $this->pay($this->applyAndApprove($listingId), 'PF-1');
        $this->assertTrue(listing_is_verified_business($this->listings->find($listingId)));

        // Expire it in the database only; do not run the sweep.
        $this->listings->update($listingId, ['verified_until' => date('Y-m-d', strtotime('-1 day'))]);
        $this->assertFalse(listing_is_verified_business($this->listings->find($listingId)));

        // The last paid day still counts.
        $this->listings->update($listingId, ['verified_until' => date('Y-m-d')]);
        $this->assertTrue(listing_is_verified_business($this->listings->find($listingId)));
    }

    public function testRevokingRemovesTheBadgeImmediately(): void
    {
        $listingId = $this->makeListing();
        $row       = $this->applyAndApprove($listingId);
        $this->pay($row, 'PF-1');

        $this->assertTrue($this->svc->revoke((int) $row['id'], 'admin@test'));

        $this->assertSame(DirectoryVerificationModel::STATE_LAPSED, $this->verifications->forListing($listingId)['state']);
        $this->assertNull($this->listings->find($listingId)['verified_until']);
    }

    // ------------------------------------------------- working without PayFast

    /**
     * The headline fix. The whole feature used to be hidden behind
     * PayFast::isConfigured(), so a deployment with no payment credentials could
     * not collect a document, review one, or award a badge.
     */
    public function testTheFeatureIsAvailableWithoutPayFastCredentials(): void
    {
        $svc = new VerificationService($this->payfast(configured: false));

        $this->assertTrue($svc->isEnabled(), 'applying must not require a payment processor');
        $this->assertFalse($svc->canTakePayment(), 'but charging a card must');

        $listingId = $this->makeListing();
        $this->assertTrue($svc->submitApplication($listingId, $this->documents())['ok']);

        $row = $this->verifications->forListing($listingId);
        $this->assertTrue($svc->approve((int) $row['id'], 'admin@test'), 'review works with no PayFast at all');
    }

    public function testManualActivationGrantsTheBadgeWithoutPayFast(): void
    {
        $svc       = new VerificationService($this->payfast(configured: false));
        $listingId = $this->makeListing();
        $svc->submitApplication($listingId, $this->documents());
        $row = $this->verifications->forListing($listingId);
        $svc->approve((int) $row['id'], 'admin@test');

        $this->assertTrue($svc->activateManually((int) $row['id'], 'admin@test', 3));

        $after = $this->verifications->forListing($listingId);
        $this->assertSame(DirectoryVerificationModel::STATE_ACTIVE, $after['state']);
        $this->assertSame(date('Y-m-d', strtotime('+3 months')), $after['paid_until']);
        $this->assertSame($after['paid_until'], $this->listings->find($listingId)['verified_until']);

        // No recurring mandate exists, which is how the cancel path knows not to
        // call PayFast about it.
        $this->assertNull($after['pf_subscription_token']);
    }

    /** Activating something nobody has reviewed would defeat the whole point. */
    public function testManualActivationIsRefusedWhileAwaitingReview(): void
    {
        $listingId = $this->makeListing();
        $this->svc->submitApplication($listingId, $this->documents());
        $row = $this->verifications->forListing($listingId);

        $this->assertFalse($this->svc->activateManually((int) $row['id'], 'admin@test'));
        $this->assertNull($this->listings->find($listingId)['verified_until']);
    }

    public function testAManuallyActivatedBadgeLapsesThroughTheNormalSweep(): void
    {
        $listingId = $this->makeListing();
        $row       = $this->applyAndApprove($listingId);
        $this->svc->activateManually((int) $row['id'], 'admin@test');

        $this->verifications->update((int) $row['id'], ['paid_until' => date('Y-m-d', strtotime('-1 day'))]);

        $this->assertSame(1, $this->svc->lapseExpired());
        $this->assertNull($this->listings->find($listingId)['verified_until']);
    }

    // ------------------------------------------------------------ cancellation

    public function testCancellingStopsRenewalButKeepsTheBadgeUntilItExpires(): void
    {
        $svc       = new VerificationService($this->payfast(cancelSucceeds: true));
        $listingId = $this->makeListing();
        $this->pay($this->applyAndApprove($listingId), 'PF-1');

        $before = $this->verifications->forListing($listingId);
        $result = $svc->cancelSubscription($listingId);

        $this->assertTrue($result['ok'], $result['message']);

        $after = $this->verifications->forListing($listingId);
        $this->assertNotNull($after['cancelled_at']);
        $this->assertSame($before['paid_until'], $after['paid_until'], 'they paid for this month and keep it');
        $this->assertSame(DirectoryVerificationModel::STATE_ACTIVE, $after['state']);
        $this->assertSame($before['paid_until'], $this->listings->find($listingId)['verified_until'], 'the badge stays up');
    }

    /**
     * The branch that matters most. If PayFast will not confirm the
     * cancellation, we must not record one — an owner who believes they
     * cancelled while their card keeps being billed is a dispute we would
     * deserve to lose.
     */
    public function testAFailedPayFastCancelRecordsNothingAndReportsFailure(): void
    {
        $svc       = new VerificationService($this->payfast(cancelSucceeds: false));
        $listingId = $this->makeListing();
        $this->pay($this->applyAndApprove($listingId), 'PF-1');

        $result = $svc->cancelSubscription($listingId);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('could not cancel', $result['message']);
        $this->assertNull(
            $this->verifications->forListing($listingId)['cancelled_at'],
            'a cancellation we could not achieve must not be recorded as one'
        );
    }

    public function testCancellingAManuallyActivatedBadgeNeverCallsPayFast(): void
    {
        // cancelSucceeds: false — if the service called PayFast here, this would fail.
        $svc       = new VerificationService($this->payfast(cancelSucceeds: false));
        $listingId = $this->makeListing();
        $row       = $this->applyAndApprove($listingId);
        $svc->activateManually((int) $row['id'], 'admin@test');

        $result = $svc->cancelSubscription($listingId);

        $this->assertTrue($result['ok'], 'there is no subscription to fail at cancelling');
        $this->assertNotNull($this->verifications->forListing($listingId)['cancelled_at']);
    }

    public function testACancelledSubscriptionStillLapsesOnItsPaidThroughDate(): void
    {
        $svc       = new VerificationService($this->payfast(cancelSucceeds: true));
        $listingId = $this->makeListing();
        $row       = $this->applyAndApprove($listingId);
        $this->pay($row, 'PF-1');
        $svc->cancelSubscription($listingId);

        $this->verifications->update((int) $row['id'], ['paid_until' => date('Y-m-d', strtotime('-1 day'))]);

        $this->assertSame(1, $svc->lapseExpired());
        $this->assertSame(DirectoryVerificationModel::STATE_LAPSED, $this->verifications->forListing($listingId)['state']);
        $this->assertNull($this->listings->find($listingId)['verified_until']);
    }

    public function testCancellingTwiceIsHarmless(): void
    {
        $svc       = new VerificationService($this->payfast(cancelSucceeds: true));
        $listingId = $this->makeListing();
        $this->pay($this->applyAndApprove($listingId), 'PF-1');

        $svc->cancelSubscription($listingId);
        $first = $this->verifications->forListing($listingId)['cancelled_at'];

        $this->assertTrue($svc->cancelSubscription($listingId)['ok']);
        $this->assertSame($first, $this->verifications->forListing($listingId)['cancelled_at']);
    }

    public function testThereIsNothingToCancelWithoutAnActiveBadge(): void
    {
        $listingId = $this->makeListing();
        $this->applyAndApprove($listingId);

        $this->assertFalse($this->svc->cancelSubscription($listingId)['ok']);
    }

    // --------------------------------------------------------------- boundary

    /**
     * The security boundary. verified_until is absent from OWNER_EDITABLE, so
     * no owner POST can reach it however it is crafted.
     */
    public function testAnOwnerCannotGrantThemselvesABadge(): void
    {
        $this->assertNotContains('verified_until', DirectoryListingModel::OWNER_EDITABLE);

        $listingId = $this->makeListing();

        (new App\Services\DirectoryListingMutationService())->updateOwn($listingId, [
            'display_name'   => 'Verification Test Co',
            'consent'        => '1',
            'verified_until' => date('Y-m-d', strtotime('+10 years')),
        ]);

        $this->assertNull($this->listings->find($listingId)['verified_until'], 'a crafted POST must not mint a subscription');
    }
}

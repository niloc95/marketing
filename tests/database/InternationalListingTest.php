<?php

use App\Models\DirectoryCategoryModel;
use App\Models\DirectoryListingModel;
use App\Models\DirectoryVerificationModel;
use App\Services\DirectoryListingMutationService;
use App\Services\DirectorySettings;
use App\Services\VerificationService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Countries;

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
    use DatabaseTestTrait;

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

        $result = $this->mutations->submitPublic($input);
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

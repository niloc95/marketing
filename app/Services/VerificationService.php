<?php

namespace App\Services;

use App\Libraries\Mailer;
use App\Libraries\PayFast;
use App\Libraries\Paystack;
use App\Models\DirectoryListingModel;
use App\Models\DirectoryVerificationDocumentModel;
use App\Models\DirectoryVerificationEventModel;
use App\Models\DirectoryVerificationModel;
use App\Models\DirectoryVerificationSubmissionModel;
use App\Models\PaystackPlanModel;
use Config\Directory as DirectoryConfig;
use Config\Sites;
use Throwable;

/**
 * Everything behind the paid "Verified Business" badge.
 *
 * The order of operations is the point of this class: an owner uploads two
 * documents, an admin approves them, and only then is the owner asked for
 * money. Payment last means a rejected application never needs a refund, which
 * is worth more than the conversion a pay-first flow would win.
 *
 * This is the only writer of xs_directory_listings.verified_until — the column
 * the public badge renders from. Neither the owner edit form nor the admin
 * upsert can reach it, by construction (see DirectoryListingModel::OWNER_EDITABLE).
 * Keep it that way: every path into that column should pass through
 * applyPaidUntil() below, so there is one place to read when asking "how could
 * a listing have got a badge?".
 */
class VerificationService
{
    private DirectoryVerificationModel $verifications;
    private DirectoryVerificationDocumentModel $documents;
    private DirectoryVerificationSubmissionModel $submissions;
    private DirectoryVerificationEventModel $events;
    private DirectoryListingModel $listings;
    private DirectoryConfig $config;
    private PayFast $payfast;
    private Paystack $paystack;
    private DirectorySettings $settings;

    /**
     * PayFast is injectable for one reason: cancelSubscription() has a failure
     * branch that must never be mistaken for success, and the only way to prove
     * both branches behave is to be able to make the call fail on demand. The
     * default keeps every caller in the app unchanged. Paystack likewise, and
     * tests hand in a double whose request() never leaves the machine.
     */
    public function __construct(?PayFast $payfast = null, ?Paystack $paystack = null)
    {
        $this->verifications = new DirectoryVerificationModel();
        $this->documents     = new DirectoryVerificationDocumentModel();
        $this->submissions   = new DirectoryVerificationSubmissionModel();
        $this->events        = new DirectoryVerificationEventModel();
        $this->listings      = new DirectoryListingModel();
        $this->config        = config('Directory');
        $this->payfast       = $payfast ?? new PayFast();
        $this->paystack      = $paystack ?? service('paystack');
        $this->settings      = new DirectorySettings($this->config);
    }

    /**
     * Can businesses apply for the badge?
     *
     * This used to return PayFast::isConfigured(), on the reasoning that we
     * should not advertise something we cannot sell. That was the wrong trade:
     * it also hid document upload, the review queue and the badge itself — none
     * of which involve a card — so a deployment without a PayFast account could
     * not use any part of the feature. Now the two questions are separate, and
     * a business can be verified and pay by EFT with an admin activating the
     * badge by hand.
     */
    public function isEnabled(): bool
    {
        return $this->settings->badgeEnabled();
    }

    /**
     * Can we charge a card right now?
     *
     * Gates the checkout route and the "Activate my badge" button only. When
     * this is false an approved application waits for manual activation instead
     * of showing the owner a button that cannot work.
     */
    public function canTakePayment(): bool
    {
        return $this->isEnabled() && ($this->provider() === DirectoryVerificationModel::PROVIDER_PAYSTACK
            ? $this->paystack->isConfigured()
            : $this->payfast->isConfigured());
    }

    /**
     * Which gateway new checkouts go through (Config\Directory::paymentProvider()).
     * A row keeps the gateway it was paid through until it is paid again.
     */
    public function provider(): string
    {
        return $this->config->paymentProvider() === DirectoryVerificationModel::PROVIDER_PAYSTACK
            ? DirectoryVerificationModel::PROVIDER_PAYSTACK
            : DirectoryVerificationModel::PROVIDER_PAYFAST;
    }

    /** Paystack's test mode: the checkout says no real money moves. */
    public function paystackTestMode(): bool
    {
        return $this->paystack->isTestMode();
    }

    /**
     * The price a new application will be quoted.
     *
     * Note what this is not: the price an existing subscriber pays. That is the
     * amount snapshotted on their verification row, and it stays put when this
     * changes — see recordPayment() and the migration.
     */
    public function monthlyAmount(): string
    {
        return $this->settings->badgePrice();
    }

    /**
     * Is the International Listing plan on offer?
     *
     * When false, nothing about a foreign listing changes: it publishes on
     * email verification like any other and nobody is charged. That is the
     * deliberate failure mode for a plan that gates publication rather than a
     * decoration — switching it off must never strand a listing pending with
     * no way to pay, and must never take a paying business down.
     */
    public function internationalEnabled(): bool
    {
        return $this->settings->internationalEnabled();
    }

    /**
     * As monthlyAmount(), for the other plan: Plus's dollar price in rand once
     * local.webscheduler.app is live, the rand International Profile price
     * until then.
     */
    public function internationalAmount(): string
    {
        if ($this->plusMode() && ($rand = $this->settings->plusRandAmount()) !== null) {
            return $rand;
        }

        return $this->settings->internationalPrice();
    }

    /**
     * Has the International plan become Plus?
     *
     * Until local.webscheduler.app is live, a business outside South Africa
     * pays the International Profile subscription to be published on co.za,
     * as it always has. From launch it is published free on .app, and the
     * same subscription is Plus: optional, sold in dollars, unlocking
     * branches, team members and the Jobs board. One switch, the site flag, so
     * the two can never be half in force.
     */
    public function plusMode(): bool
    {
        return service('site')->scoping();
    }

    /** Is Plus on sale: live, switched on, and priced? */
    public function plusOffered(): bool
    {
        return $this->plusMode() && $this->internationalEnabled() && $this->settings->plusRandAmount() !== null;
    }

    /** Plus's monthly price in US dollars, or null while it is not priced. */
    public function plusPriceUsd(): ?string
    {
        return $this->settings->plusPriceUsd();
    }

    /** Plus's yearly price in US dollars, or null while yearly Plus is not priced. */
    public function plusYearlyPriceUsd(): ?string
    {
        return $this->settings->plusYearlyPriceUsd();
    }

    /**
     * Today's price of a plan on a billing cycle, in rand, or null when that
     * cycle is not on sale. Yearly International exists only as Plus: the
     * co.za International Profile before .app was monthly and stays so.
     */
    public function amountFor(string $plan, string $cycle): ?string
    {
        $yearly = $cycle === DirectoryVerificationModel::CYCLE_YEARLY;

        if ($plan !== DirectoryVerificationModel::PLAN_INTERNATIONAL) {
            return $yearly ? $this->settings->badgeYearlyPrice() : $this->monthlyAmount();
        }

        if (! $yearly) {
            return $this->internationalAmount();
        }

        return $this->plusMode() ? $this->settings->plusYearlyRandAmount() : null;
    }

    /** Can this plan be bought yearly right now? */
    public function yearlyOffered(string $plan): bool
    {
        return $this->amountFor($plan, DirectoryVerificationModel::CYCLE_YEARLY) !== null;
    }

    /**
     * Price an unpaid (approved or lapsed) row for the cycle the owner picked
     * on the checkout page, and return the row as it now stands.
     *
     * Monthly badge keeps the amount an admin's approval quoted, as it always
     * has, unless the row was last priced yearly (then today's monthly price).
     * Yearly takes today's yearly price: nobody was quoted one. A cycle that is
     * not on sale falls back to monthly.
     *
     * A change of cycle or amount mints a fresh m_payment_id, because the
     * owner may already have opened PayFast with the old one and PayFast
     * refuses a reference it has seen with different terms.
     *
     * @param array<string,mixed> $verification
     *
     * @return array<string,mixed>
     */
    public function priceForCheckout(array $verification, string $plan, string $cycle): array
    {
        if (! $this->yearlyOffered($plan)) {
            $cycle = DirectoryVerificationModel::CYCLE_MONTHLY;
        }
        $was = DirectoryVerificationModel::cycleOf($verification);

        $amount = (string) $verification['amount'];
        if ($cycle === DirectoryVerificationModel::CYCLE_YEARLY
            || $was === DirectoryVerificationModel::CYCLE_YEARLY
            || $plan === DirectoryVerificationModel::PLAN_INTERNATIONAL) {
            $amount = (string) $this->amountFor($plan, $cycle);
        }

        if ($cycle === $was && $amount === (string) $verification['amount']) {
            return $verification;
        }

        $update = [
            'billing_cycle'   => $cycle,
            'amount'          => $amount,
            'pf_m_payment_id' => $this->newPaymentId($plan, (int) $verification['id']),
        ];
        $this->verifications->update((int) $verification['id'], $update);

        return array_merge($verification, $update);
    }

    /**
     * Start switching an active monthly subscription to yearly.
     *
     * A PayFast mandate cannot change its frequency, so the switch is a new
     * yearly subscription, paid now. Its reference goes in switch_m_payment_id
     * while the monthly mandate keeps running on pf_m_payment_id; when the
     * yearly payment's notification lands, recordPayment() swaps them over and
     * cancels the monthly one. The year is added on top of what is already
     * paid, so no paid days are lost.
     *
     * @return array{ok:bool,message:string,verification?:array<string,mixed>}
     */
    public function startYearlySwitch(int $listingId, string $plan): array
    {
        $row = $this->verifications->forListing($listingId, $plan);
        if ($row === null || $row['state'] !== DirectoryVerificationModel::STATE_ACTIVE || ! empty($row['cancelled_at'])) {
            return ['ok' => false, 'message' => 'There is no active monthly subscription to switch.'];
        }
        if (DirectoryVerificationModel::cycleOf($row) === DirectoryVerificationModel::CYCLE_YEARLY) {
            return ['ok' => false, 'message' => 'You are already paying yearly.'];
        }
        $amount = $this->amountFor($plan, DirectoryVerificationModel::CYCLE_YEARLY);
        if ($amount === null) {
            return ['ok' => false, 'message' => 'Yearly billing is not available at the moment.'];
        }

        $update = [
            'switch_m_payment_id' => $this->newPaymentId($plan, (int) $row['id']) . '-y',
            'switch_amount'       => $amount,
            'switch_cycle'        => DirectoryVerificationModel::CYCLE_YEARLY,
        ];
        $this->verifications->update((int) $row['id'], $update);

        return ['ok' => true, 'message' => '', 'verification' => array_merge($row, $update)];
    }

    /**
     * Start moving an active PayFast subscription to Paystack.
     *
     * Paystack cannot take over a PayFast card mandate, so the owner pays
     * again, once, through Paystack: the same snapshotted amount and cycle,
     * and the new period starts after the days already paid for, exactly as
     * a yearly switch does. The PayFast subscription keeps running until that
     * payment lands, then recordPayment() cancels it. Nobody loses a day and
     * nobody goes without a badge in between.
     *
     * @return array{ok:bool,message:string,verification?:array<string,mixed>}
     */
    public function startMove(int $listingId, string $plan): array
    {
        $row = $this->verifications->forListing($listingId, $plan);
        if ($row === null || $row['state'] !== DirectoryVerificationModel::STATE_ACTIVE || ! empty($row['cancelled_at'])) {
            return ['ok' => false, 'message' => 'There is no active subscription to move.'];
        }
        if (DirectoryVerificationModel::providerOf($row) === DirectoryVerificationModel::PROVIDER_PAYSTACK) {
            return ['ok' => false, 'message' => 'Your payment is already with Paystack. There is nothing to do.'];
        }
        if (! DirectoryVerificationModel::hasMandate($row)) {
            return ['ok' => false, 'message' => 'This subscription does not renew on its own, so there is nothing to move.'];
        }
        if ($this->provider() !== DirectoryVerificationModel::PROVIDER_PAYSTACK || ! $this->paystack->isConfigured()) {
            return ['ok' => false, 'message' => 'Moving payments is not available at the moment.'];
        }

        $update = [
            'switch_m_payment_id' => $this->newPaymentId($plan, (int) $row['id']) . '-m',
            'switch_amount'       => (string) $row['amount'],
            'switch_cycle'        => DirectoryVerificationModel::cycleOf($row),
        ];
        $this->verifications->update((int) $row['id'], $update);

        return ['ok' => true, 'message' => '', 'verification' => array_merge($row, $update)];
    }

    /**
     * Active PayFast subscriptions that still renew through PayFast: who the
     * move command writes to.
     *
     * @return array<int,array<string,mixed>>
     */
    public function payfastMandatesToMove(): array
    {
        return array_values(array_filter(
            $this->verifications
                ->where('state', DirectoryVerificationModel::STATE_ACTIVE)
                ->where('cancelled_at', null)
                ->where('provider', DirectoryVerificationModel::PROVIDER_PAYFAST)
                ->findAll(),
            static fn (array $row): bool => DirectoryVerificationModel::hasMandate($row)
        ));
    }

    /** Email the owner of a PayFast subscription the link to move it to Paystack. */
    public function sendMoveInvitation(array $verification): void
    {
        $this->mailOwner((int) $verification['listing_id'], 'move', [
            'product'   => $this->productOf($verification),
            'cycle'     => DirectoryVerificationModel::cycleOf($verification),
            'amount'    => (string) $verification['amount'],
            'paidUntil' => $this->prettyDate($verification['paid_until'] ?? null),
        ]);
    }

    /**
     * Where to send the owner's browser to pay a row through Paystack, or
     * null if Paystack would not open a checkout.
     *
     * A fresh reference every time, because Paystack refuses a reference it
     * has already seen and an owner may come back to try again. A stale tab
     * paying an older reference still lands: the metadata carries the row id
     * (PaystackPayments::rowForCharge()).
     *
     * @param array<string,mixed> $listing
     * @param array<string,mixed> $row     the row as stored
     * @param bool                $switch  pay the pending switch (yearly, or a move), not the row
     */
    public function paystackCheckoutUrl(array $listing, array $row, bool $switch): ?string
    {
        $plan  = (string) ($row['plan'] ?? DirectoryVerificationModel::PLAN_BADGE);
        $id    = (int) $row['id'];
        $cycle = $switch ? DirectoryVerificationModel::switchCycleOf($row) : DirectoryVerificationModel::cycleOf($row);

        $amount   = (string) ($switch ? $row['switch_amount'] : $row['amount']);
        $planCode = $this->paystackPlanCode($this->productOf($row), $cycle, $amount);
        if ($planCode === null) {
            return null;
        }

        if ($switch) {
            $suffix    = str_ends_with((string) $row['switch_m_payment_id'], '-m') ? '-m' : '-y';
            $reference = $this->newPaymentId($plan, $id) . $suffix;
            $this->verifications->update($id, ['switch_m_payment_id' => $reference]);
        } else {
            $reference = $this->newPaymentId($plan, $id);
            $this->verifications->update($id, ['pf_m_payment_id' => $reference]);
        }

        return $this->paystack->initialize(
            (string) ($listing['email'] ?? ''),
            Paystack::cents($amount),
            $reference,
            row_site_url($listing, 'manage/verification/paystack'),
            $planCode,
            ['verification_id' => $id, 'listing_id' => (int) $row['listing_id'], 'purpose' => $switch ? 'switch' : 'new']
        );
    }

    /**
     * The Paystack plan for a product, cycle and amount, made the first time
     * it is sold. A Paystack plan has one fixed amount and ours are
     * snapshotted per row, so there is one plan per amount, cached in
     * xs_paystack_plans. Its name is what the subscriber sees in Paystack's
     * emails, so it names what they bought, and no amount: a .app customer
     * must never be shown a rand figure.
     */
    public function paystackPlanCode(string $product, string $cycle, string $amount): ?string
    {
        $cents  = Paystack::cents($amount);
        $plans  = new PaystackPlanModel();
        $cached = $plans->codeFor($product, $cycle, $cents);
        if ($cached !== null) {
            return $cached;
        }

        $name = ['plus' => 'WebScheduler Local Plus', 'international' => 'International Profile'][$product] ?? 'Verified Business badge';
        $code = $this->paystack->createPlan(
            $name . ($cycle === DirectoryVerificationModel::CYCLE_YEARLY ? ', yearly' : ', monthly'),
            $cycle === DirectoryVerificationModel::CYCLE_YEARLY ? Paystack::INTERVAL_ANNUALLY : Paystack::INTERVAL_MONTHLY,
            $cents
        );
        if ($code === null) {
            return null;
        }

        try {
            $plans->insert(['product' => $product, 'cycle' => $cycle, 'amount_cents' => $cents, 'currency' => 'ZAR', 'plan_code' => $code]);
        } catch (Throwable $e) {
            // Two checkouts made the same plan at once: the other's is stored,
            // and this one still works on Paystack's side.
            log_message('info', 'Paystack plan cache: ' . Mailer::oneLine($e->getMessage()));
        }

        return $code;
    }

    /** 'il-' for an International Listing (Plus), 'vb-' for the badge: for humans reading PayFast. */
    public function newPaymentId(string $plan, int $verificationId): string
    {
        return sprintf(
            '%s-%d-%s',
            $plan === DirectoryVerificationModel::PLAN_INTERNATIONAL ? 'il' : 'vb',
            $verificationId,
            bin2hex(random_bytes(4))
        );
    }

    /**
     * May this listing buy Plus? A business outside South Africa, while Plus
     * is on sale.
     *
     * @param array<string,mixed> $listing
     */
    public function plusAvailableFor(array $listing): bool
    {
        return $this->plusOffered() && ! config('Countries')->isLocal((string) ($listing['country'] ?? ''));
    }

    /**
     * Does this listing have to pay to be published at all?
     *
     * The one question the publish gate asks. Reads the listing's country
     * rather than any flag on the row, so an admin correcting a country
     * corrects the answer in the same edit.
     *
     * @param array<string,mixed> $listing
     */
    public function requiresSubscription(array $listing): bool
    {
        // Plus is optional: once it exists nobody pays to be published.
        if ($this->plusMode()) {
            return false;
        }

        return $this->internationalEnabled()
            && ! config('Countries')->isLocal((string) ($listing['country'] ?? ''));
    }

    /**
     * Is that subscription paid up right now?
     *
     * A date compare, exactly like listing_is_verified_business() — see the
     * helper's docblock for why the comparison is made at read time rather
     * than trusting a boolean somebody has to remember to flip.
     *
     * @param array<string,mixed> $listing
     */
    public function subscriptionActive(array $listing): bool
    {
        $until = trim((string) ($listing['hosting_paid_until'] ?? ''));

        return $until !== '' && $until >= date('Y-m-d');
    }

    /**
     * May this listing be published?
     *
     * Every publish path asks this and nothing else: a South African listing
     * always may, a foreign one only while it is paid for. Keeping it in one
     * method is what stops the ITN handler, verify() and the admin form from
     * drifting into three slightly different answers.
     *
     * @param array<string,mixed> $listing
     */
    public function mayPublish(array $listing): bool
    {
        return ! $this->requiresSubscription($listing) || $this->subscriptionActive($listing);
    }

    /**
     * The International Listing subscription for a listing, created if it has
     * none.
     *
     * Created directly in `approved`, which is the state the badge reaches only
     * after an admin has looked at two documents. That is not a shortcut around
     * review — there is nothing to review. The badge asserts "we checked who
     * these people are"; this plan asserts nothing at all, it is the price of
     * being hosted. So it goes straight to payable.
     *
     * The amount is snapshotted here for the same reason the badge's is: a
     * later price change must not start failing an existing subscriber's
     * renewal when the ITN amount is compared against what we asked for.
     *
     * Idempotent — an owner who reloads the checkout page twice gets the same
     * row, not a second one. The (listing_id, plan) unique index is the
     * backstop if two requests race.
     *
     * @return array<string,mixed>|null the row, or null if it could not be made
     */
    public function ensureInternationalSubscription(int $listingId): ?array
    {
        $existing = $this->verifications->forListing(
            $listingId,
            DirectoryVerificationModel::PLAN_INTERNATIONAL
        );
        if ($existing !== null) {
            return $existing;
        }

        $listing = $this->listings->find($listingId);
        if (! is_array($listing) || ! ($this->requiresSubscription($listing) || $this->plusAvailableFor($listing))) {
            return null;
        }

        try {
            $this->verifications->insert([
                'listing_id'   => $listingId,
                'plan'         => DirectoryVerificationModel::PLAN_INTERNATIONAL,
                'state'        => DirectoryVerificationModel::STATE_APPROVED,
                'amount'       => $this->internationalAmount(),
                'submitted_at' => date('Y-m-d H:i:s'),
                'reviewed_at'  => date('Y-m-d H:i:s'),
                'reviewed_by'  => 'system',
            ]);
        } catch (Throwable $e) {
            log_message('error', 'Could not open an international subscription for listing '
                . $listingId . ': ' . Mailer::oneLine($e->getMessage()));

            return null;
        }

        return $this->verifications->forListing(
            $listingId,
            DirectoryVerificationModel::PLAN_INTERNATIONAL
        );
    }

    /**
     * A listing's application plus its documents, or null if it has never
     * applied. The shape the manage panel and the review queue both render from.
     *
     * @return array{verification:array<string,mixed>,documents:array<int,array<string,mixed>>}|null
     */
    public function forListing(
        int $listingId,
        string $plan = DirectoryVerificationModel::PLAN_BADGE
    ): ?array {
        $row = $this->verifications->forListing($listingId, $plan);
        if ($row === null) {
            return null;
        }

        return [
            'verification' => $row,
            'documents'    => $this->documents->forVerification((int) $row['id']),
        ];
    }

    /**
     * Store a new application, or reset an existing one back into review.
     *
     * Both documents are required. A one-document application is refused rather
     * than queued, because an admin cannot act on it and the owner would be left
     * waiting for a decision that could never come.
     *
     * @param array<string,array{name:string,mime:string,bytes:int,sha256:string}> $documents
     *        keyed by DirectoryVerificationDocumentModel::KINDS, as returned by
     *        HandlesVerificationUploads::resolveVerificationDocuments()
     *
     * @return array{ok:bool,message:string}
     */
    public function submitApplication(int $listingId, array $documents): array
    {
        $missing = array_diff(DirectoryVerificationDocumentModel::KINDS, array_keys($documents));
        if ($missing !== []) {
            // The caller has already stored whatever did arrive; drop it rather
            // than leave half an application's PII on disk with no row pointing
            // at it and nothing that would ever clean it up.
            $this->discardStoredFiles($listingId, $documents);

            return [
                'ok'      => false,
                'message' => 'We need both documents. The company registration document and the owner’s ID.',
            ];
        }

        $existing = $this->verifications->forListing($listingId);

        // An active subscriber re-uploading would otherwise knock their own
        // badge back into review. Nothing good comes of that; they can talk to
        // us if a document genuinely needs replacing.
        if ($existing !== null && in_array($existing['state'], [
            DirectoryVerificationModel::STATE_ACTIVE,
            DirectoryVerificationModel::STATE_SUBMITTED,
        ], true)) {
            $this->discardStoredFiles($listingId, $documents);

            return [
                'ok'      => false,
                'message' => $existing['state'] === DirectoryVerificationModel::STATE_ACTIVE
                    ? 'Your profile is already verified.'
                    : 'Your documents are already with us. We’ll email you when the review is done.',
            ];
        }

        $db = db_connect();
        $db->transBegin();

        try {
            $this->openApplication($listingId, $existing, $documents);

            $db->transCommit();
        } catch (\Throwable $e) {
            $db->transRollback();
            log_message('error', 'Verification application failed and was rolled back: ' . Mailer::oneLine($e->getMessage()));
            $this->discardStoredFiles($listingId, $documents);

            return ['ok' => false, 'message' => 'We could not store your documents. Please try again.'];
        }

        $this->notifyAdminOfSubmission($listingId);

        return [
            'ok'      => true,
            'message' => 'Thanks, your documents are with us. We’ll email you once they have been reviewed, '
                . 'and you’ll only be asked to pay after they are approved.',
        ];
    }

    /**
     * Put a listing's badge row into review with a fresh submission and the
     * documents that came with it. Shared by the owner's application and an
     * admin verifying directly, so the history rules below hold for both.
     * Runs inside the caller's transaction and throws on failure.
     *
     * @param array<string,mixed>|null $existing the badge row, if there is one
     * @param array<string,array<string,mixed>> $documents keyed by kind; an
     *        admin may attach fewer than both
     *
     * @return int the verification id
     */
    private function openApplication(int $listingId, ?array $existing, array $documents): int
    {
        $submittedAt = date('Y-m-d H:i:s');

        if ($existing !== null) {
            // Re-submission after a rejection or a lapse. The previous
            // evidence is closed off, not deleted: the verification row
            // below is about to lose its decision fields, and the documents
            // plus the submission row are the only remaining answer to "how
            // was this business verified in the first place". They stay for
            // the life of the listing.
            $previous = $this->submissions->latestForVerification((int) $existing['id']);
            if ($previous !== null) {
                $this->submissions->supersede((int) $previous['id']);
            }
            $this->documents->supersedeForVerification((int) $existing['id']);

            $this->verifications->update((int) $existing['id'], [
                'state'            => DirectoryVerificationModel::STATE_SUBMITTED,
                'amount'           => $this->monthlyAmount(),
                'rejection_reason' => null,
                'reviewed_at'      => null,
                'reviewed_by'      => null,
                'submitted_at'     => $submittedAt,
            ]);
            $verificationId = (int) $existing['id'];
        } else {
            $verificationId = (int) $this->verifications->insert([
                'listing_id'   => $listingId,
                'state'        => DirectoryVerificationModel::STATE_SUBMITTED,
                'amount'       => $this->monthlyAmount(),
                'submitted_at' => $submittedAt,
            ], true);

            if ($verificationId === 0) {
                throw new \RuntimeException('verification row not created');
            }
        }

        $submissionId = $this->submissions->open($verificationId, $submittedAt);
        if ($submissionId === 0) {
            throw new \RuntimeException('verification submission row not created');
        }

        foreach (DirectoryVerificationDocumentModel::KINDS as $kind) {
            if (! isset($documents[$kind])) {
                continue;
            }
            $this->documents->insert([
                'verification_id' => $verificationId,
                'submission_id'   => $submissionId,
                'kind'            => $kind,
                'path'            => $listingId . '/' . $documents[$kind]['name'],
                'original_name'   => $documents[$kind]['original_name'] ?? null,
                'mime'            => $documents[$kind]['mime'],
                'bytes'           => $documents[$kind]['bytes'],
                'sha256'          => $documents[$kind]['sha256'] ?? null,
            ]);
        }

        return $verificationId;
    }

    /**
     * An admin verifies a profile directly from its edit page: for a customer
     * who emailed the documents in, or a Verified profile we built for them.
     * Skips the owner's application, not the check: it needs both documents
     * or a note saying how the admin checked the business, and the note is
     * kept with the decision.
     *
     * $mode 'owner_pays' leaves the row approved and sends the usual
     * "approved, activate and pay" email. 'activate' also grants $months
     * through activateManually() (EFT, or given), so the badge is live now.
     *
     * Refused until the profile's email is confirmed, which for a profile we
     * built is the customer approving the preview: nobody is asked to pay for,
     * or given a badge on, a profile they have not seen and accepted.
     *
     * @param array<string,array<string,mixed>> $documents as returned by
     *        HandlesVerificationUploads::resolveVerificationDocuments()
     *
     * @return array{ok:bool,message:string}
     */
    public function grantByAdmin(int $listingId, array $documents, string $note, string $mode, int $months, string $by): array
    {
        $note    = mb_substr(trim($note), 0, 500);
        $listing = $this->listings->find($listingId);
        $refuse  = function (string $message) use ($listingId, $documents): array {
            $this->discardStoredFiles($listingId, $documents);

            return ['ok' => false, 'message' => $message];
        };

        if (! is_array($listing)) {
            return $refuse('That profile no longer exists.');
        }
        if (! $this->isEnabled()) {
            return $refuse('The Verified Business badge is switched off.');
        }
        if (! config('Countries')->isLocal((string) ($listing['country'] ?? ''))) {
            return $refuse('The badge is for South African profiles only.');
        }
        if (empty($listing['is_verified'])) {
            return $refuse('The customer has not approved the preview yet. Send it, and verify once they approve.');
        }
        if (! in_array($mode, ['owner_pays', 'activate'], true)) {
            return $refuse('Choose approve or activate.');
        }
        $bothDocuments = array_diff(DirectoryVerificationDocumentModel::KINDS, array_keys($documents)) === [];
        if (! $bothDocuments && $note === '') {
            return $refuse('Attach both documents, or write a note saying how you checked the business.');
        }

        $existing = $this->verifications->forListing($listingId);
        if ($existing !== null && $existing['state'] === DirectoryVerificationModel::STATE_ACTIVE) {
            return $refuse('This profile is already verified. Extend it with Activate on the verifications page.');
        }
        if ($existing !== null && $existing['state'] === DirectoryVerificationModel::STATE_SUBMITTED) {
            return $refuse('The owner has an application waiting. Approve or reject it on the verifications page.');
        }

        $db = db_connect();
        $db->transBegin();

        try {
            $verificationId = $this->openApplication($listingId, $existing, $documents);

            $this->verifications->update($verificationId, [
                'state'       => DirectoryVerificationModel::STATE_APPROVED,
                'reviewed_at' => date('Y-m-d H:i:s'),
                'reviewed_by' => $by,
            ]);
            $this->recordDecision(
                $verificationId,
                DirectoryVerificationSubmissionModel::OUTCOME_APPROVED,
                $by,
                null,
                $note !== '' ? $note : null
            );

            $db->transCommit();
        } catch (\Throwable $e) {
            $db->transRollback();
            log_message('error', 'Admin verification failed and was rolled back: ' . Mailer::oneLine($e->getMessage()));

            return $refuse('Could not save the verification. Nothing was changed.');
        }

        if ($mode === 'activate') {
            // Its own transaction, after the approval is safely written: a
            // failure here leaves an approved row the admin can Activate from
            // the verifications page, never a half granted badge.
            if (! $this->activateManually($verificationId, $by, $months)) {
                return ['ok' => false, 'message' => 'Approved, but the badge could not be activated. Use Activate on the verifications page.'];
            }
            $months = max(1, min(24, $months));

            return ['ok' => true, 'message' => sprintf('Verified and active for %d month%s. The owner has been emailed.', $months, $months === 1 ? '' : 's')];
        }

        $row = $this->verifications->find($verificationId);
        $this->mailOwner($listingId, 'approved', ['amount' => $row['amount'] ?? $this->monthlyAmount(), 'yearlyAmount' => $this->yearlyAmountForEmail()]);

        return ['ok' => true, 'message' => 'Verified. The owner has been emailed a link to pay and switch the badge on.'];
    }

    /**
     * Admin decision: documents are good. Does not grant the badge — it invites
     * the owner to pay, and PayFast grants the badge when it confirms money.
     */
    public function approve(int $verificationId, string $by): bool
    {
        $row = $this->verifications->find($verificationId);
        if (! is_array($row) || $row['state'] !== DirectoryVerificationModel::STATE_SUBMITTED) {
            return false;
        }

        $ok = (bool) $this->verifications->update($verificationId, [
            'state'            => DirectoryVerificationModel::STATE_APPROVED,
            'rejection_reason' => null,
            'reviewed_at'      => date('Y-m-d H:i:s'),
            'reviewed_by'      => $by,
        ]);

        if ($ok) {
            // The same decision, written where it will survive the next
            // application. The verification row above is the badge's state and
            // gets overwritten; this is the record of the ruling.
            $this->recordDecision(
                $verificationId,
                DirectoryVerificationSubmissionModel::OUTCOME_APPROVED,
                $by
            );
            $this->mailOwner((int) $row['listing_id'], 'approved', ['amount' => $row['amount'], 'yearlyAmount' => $this->yearlyAmountForEmail()]);
        }

        return $ok;
    }

    /**
     * Admin decision: documents are not good enough, with a reason the owner
     * sees verbatim. Kept as a stored sentence rather than a code because the
     * reason is nearly always specific ("the ID photo is cut off at the edge")
     * and a fixed list would push admins towards a vague nearest match.
     */
    public function reject(int $verificationId, string $reason, string $by): bool
    {
        $reason = trim($reason);
        if ($reason === '') {
            return false;
        }

        $row = $this->verifications->find($verificationId);
        if (! is_array($row) || $row['state'] !== DirectoryVerificationModel::STATE_SUBMITTED) {
            return false;
        }

        $ok = (bool) $this->verifications->update($verificationId, [
            'state'            => DirectoryVerificationModel::STATE_REJECTED,
            'rejection_reason' => mb_substr($reason, 0, 500),
            'reviewed_at'      => date('Y-m-d H:i:s'),
            'reviewed_by'      => $by,
        ]);

        if ($ok) {
            $this->recordDecision(
                $verificationId,
                DirectoryVerificationSubmissionModel::OUTCOME_REJECTED,
                $by,
                mb_substr($reason, 0, 500)
            );
            $this->mailOwner((int) $row['listing_id'], 'rejected', ['reason' => $reason]);
        }

        return $ok;
    }

    /**
     * Stop a badge renewing, at the owner's request.
     *
     * What this deliberately does NOT do is take the badge down. They paid to
     * the end of the current month and they keep it to the end of the current
     * month; the sweep removes it on the date it was always going to. Cancelling
     * changes one thing — whether there is another charge.
     *
     * The failure branch is the important one. If PayFast will not confirm the
     * cancellation we record nothing and say so, because the alternative is an
     * owner who believes they cancelled, a card that keeps being billed, and a
     * dispute we would deserve to lose. "We could not do this, a human has been
     * told" is a worse message and an honest one.
     *
     * @return array{ok:bool,message:string}
     */
    public function cancelSubscription(
        int $listingId,
        string $plan = DirectoryVerificationModel::PLAN_BADGE
    ): array {
        $row = $this->verifications->forListing($listingId, $plan);

        // What the owner loses differs, so what they are told differs. Cancel
        // a badge and a decoration stops renewing; cancel an International
        // Listing and the profile itself comes down at the end of the term.
        // Saying "badge" in both would be the more convenient code and the
        // wrong thing to tell somebody.
        $isInternational = $plan === DirectoryVerificationModel::PLAN_INTERNATIONAL;
        $what            = $isInternational ? 'subscription' : 'badge';

        // Plus is an extra, so cancelling it never takes the profile down.
        if ($isInternational && $this->plusMode()) {
            return $this->cancelPlus($listingId);
        }

        if ($row === null || $row['state'] !== DirectoryVerificationModel::STATE_ACTIVE) {
            return ['ok' => false, 'message' => 'There is no active ' . $what . ' to cancel.'];
        }

        if (! empty($row['cancelled_at'])) {
            return [
                'ok'      => true,
                'message' => $isInternational
                    ? 'Your subscription is already cancelled. Your profile stays live until it expires.'
                    : 'Your badge is already cancelled. It stays up until it expires.',
            ];
        }

        $gateway = $this->gatewayName($row);

        // No mandate means the badge was activated by hand — there is no
        // recurring subscription anywhere to stop, so calling the gateway would
        // fail for a reason that has nothing to do with the owner. Record the
        // intent and tell a human, since somebody may be invoicing them by EFT.
        if (! DirectoryVerificationModel::hasMandate($row)) {
            $this->verifications->update((int) $row['id'], ['cancelled_at' => date('Y-m-d H:i:s')]);
            $this->notifyAdminOfCancellation($listingId, 'manually activated ' . $what . ', no ' . $gateway . ' subscription to cancel');

            return [
                'ok'      => true,
                'message' => $isInternational
                    ? 'Your subscription will not renew. Your profile stays live until '
                        . $this->prettyDate($row['paid_until']) . ', then comes down.'
                    : 'Your badge will not renew. It stays on your profile until '
                        . $this->prettyDate($row['paid_until']) . '.',
            ];
        }

        if (! $this->cancelMandate($row)) {
            $this->notifyAdminOfCancellation($listingId, strtoupper($gateway) . ' API CALL FAILED. Cancel this subscription by hand in the ' . $gateway . ' dashboard');

            return [
                'ok'      => false,
                'message' => 'We could not cancel your subscription automatically. We have alerted our team '
                    . 'and someone will sort it out today. You will get an email confirming it.',
            ];
        }

        $this->verifications->update((int) $row['id'], ['cancelled_at' => date('Y-m-d H:i:s')]);
        $this->notifyAdminOfCancellation($listingId, 'cancelled through ' . $gateway . ' successfully');

        return [
            'ok'      => true,
            'message' => $isInternational
                ? 'Cancelled. You will not be charged again. Your profile stays live until '
                    . $this->prettyDate($row['paid_until']) . ', then comes down.'
                : 'Cancelled. You will not be charged again. Your badge stays on your profile until '
                    . $this->prettyDate($row['paid_until']) . '.',
        ];
    }

    /**
     * cancelSubscription() for Plus: the same gateway call and the same
     * failure rule (nothing recorded if the gateway will not confirm), with Plus's
     * own words, because what the owner keeps differs: the profile always.
     *
     * @return array{ok:bool,message:string}
     */
    private function cancelPlus(int $listingId): array
    {
        $row = $this->verifications->forListing($listingId, DirectoryVerificationModel::PLAN_INTERNATIONAL);
        if ($row === null || $row['state'] !== DirectoryVerificationModel::STATE_ACTIVE) {
            return ['ok' => false, 'message' => 'There is no active Plus subscription to cancel.'];
        }
        $until = $this->prettyDate($row['paid_until']);
        if (! empty($row['cancelled_at'])) {
            return ['ok' => true, 'message' => 'Plus is already cancelled. It stays on until ' . $until . '.'];
        }

        $gateway = $this->gatewayName($row);
        $mandate = DirectoryVerificationModel::hasMandate($row);
        if ($mandate && ! $this->cancelMandate($row)) {
            $this->notifyAdminOfCancellation($listingId, strtoupper($gateway) . ' API CALL FAILED cancelling Plus. Cancel this subscription by hand in the ' . $gateway . ' dashboard');

            return [
                'ok'      => false,
                'message' => 'We could not cancel Plus automatically. We have alerted our team '
                    . 'and someone will sort it out today. You will get an email confirming it.',
            ];
        }

        $this->verifications->update((int) $row['id'], ['cancelled_at' => date('Y-m-d H:i:s')]);
        $this->notifyAdminOfCancellation($listingId, $mandate ? 'Plus cancelled through ' . $gateway . ' successfully' : 'Plus cancelled (no ' . $gateway . ' subscription to stop)');

        return [
            'ok'      => true,
            'message' => 'Cancelled. You will not be charged again. Plus stays on until ' . $until
                . ', and your profile stays published after that.',
        ];
    }

    /**
     * Grant the badge without PayFast, for a business that paid some other way.
     *
     * The EFT path, and the reason splitting the payment gate does not create a
     * dead end: an approved application on a deployment with no card processing
     * can still be completed by hand. Also the recovery path when a PayFast
     * notification is lost and someone has genuinely paid.
     *
     * Goes through applyPaidUntil() like every other grant, so there is still
     * exactly one place that writes the column the public badge reads.
     *
     * Leaves pf_subscription_token null — nothing recurring exists to cancel,
     * which is precisely how cancelSubscription() knows not to call PayFast.
     * The trade is that this does not renew itself: the sweep lapses it when
     * the month runs out and an admin activates again when the next EFT lands.
     */
    public function activateManually(int $verificationId, string $by, int $months = 1): bool
    {
        $months = max(1, min(24, $months));

        $row = $this->verifications->find($verificationId);
        if (! is_array($row) || $row['state'] === DirectoryVerificationModel::STATE_SUBMITTED) {
            // Refused while awaiting review: activating would hand out a badge
            // for documents nobody has looked at.
            return false;
        }

        $db = db_connect();
        $db->transBegin();

        try {
            // Honours the anchor if this row ever had a PayFast mandate — an
            // admin extending a subscription by hand should not shift the day it
            // renews on. An EFT-only badge has none, and falls back to the day
            // it is being activated from.
            $paidUntil = $this->nextPaidUntil(
                $row['paid_until'] ?? null,
                $months,
                isset($row['billing_anchor_day']) ? (int) $row['billing_anchor_day'] : null
            );

            $this->verifications->update($verificationId, [
                'state'        => DirectoryVerificationModel::STATE_ACTIVE,
                'paid_until'   => $paidUntil,
                'activated_at' => $row['activated_at'] ?? date('Y-m-d H:i:s'),
                'reviewed_by'  => $by,
            ]);
            // Same split as recordPayment(): the EFT path has to grant the
            // same thing the card path does, or activating by hand would take
            // the money and leave an international listing unpublished.
            if ($this->isInternational($row)) {
                $this->applyHostingPaidUntil((int) $row['listing_id'], $paidUntil);

                $listing = $this->listings->find((int) $row['listing_id']);
                if (is_array($listing)) {
                    $this->publishIfPaidFor($listing);
                }
            } else {
                $this->applyPaidUntil((int) $row['listing_id'], $paidUntil);
            }

            $db->transCommit();
        } catch (\Throwable $e) {
            $db->transRollback();
            log_message('error', 'Manual verification activation failed: ' . Mailer::oneLine($e->getMessage()));

            return false;
        }

        if (empty($row['activated_at'])) {
            $this->mailOwner((int) $row['listing_id'], 'activated', ['product' => $this->productOf($row)]);
        }

        return true;
    }

    /**
     * Pull a live badge immediately — for a business that turns out not to be
     * what its documents claimed. Separate from lapsing, which is just
     * non-payment and carries no judgement.
     *
     * Also stops the recurring subscription at whichever gateway holds it,
     * after the commit: a revoked business must not keep being billed. If the
     * gateway will not confirm, the admin is emailed to finish it by hand.
     */
    public function revoke(int $verificationId, string $by): bool
    {
        $row = $this->verifications->find($verificationId);
        if (! is_array($row)) {
            return false;
        }

        $db = db_connect();
        $db->transBegin();

        try {
            $this->verifications->update($verificationId, [
                'state'       => DirectoryVerificationModel::STATE_LAPSED,
                'paid_until'  => null,
                'reviewed_at' => date('Y-m-d H:i:s'),
                'reviewed_by' => $by,
            ]);
            // Revoking an international subscription takes the listing down
            // with it, exactly as a lapse does — the plan IS the publication.
            // Deliberate and worth an admin knowing: revoke here is not the
            // same act as revoking a badge.
            if ($this->isInternational($row)) {
                $this->applyHostingPaidUntil((int) $row['listing_id'], null);
                // Before Plus the plan was the publication; Plus is an extra.
                if (! $this->plusMode()) {
                    $this->listings->update((int) $row['listing_id'], ['status' => 'unpublished']);
                }
            } else {
                $this->applyPaidUntil((int) $row['listing_id'], null);
            }

            $db->transCommit();
        } catch (\Throwable $e) {
            $db->transRollback();
            log_message('error', 'Verification revoke failed: ' . Mailer::oneLine($e->getMessage()));

            return false;
        }

        if (DirectoryVerificationModel::hasMandate($row) && empty($row['cancelled_at'])) {
            $gateway = $this->gatewayName($row);
            if ($this->cancelMandate($row)) {
                $this->verifications->update($verificationId, ['cancelled_at' => date('Y-m-d H:i:s')]);
                $this->notifyAdminOfCancellation((int) $row['listing_id'], 'revoked; the ' . $gateway . ' subscription was cancelled');
            } else {
                $this->notifyAdminOfCancellation((int) $row['listing_id'], 'REVOKED but the ' . $gateway . ' subscription could not be cancelled. Cancel it by hand in the ' . $gateway . ' dashboard');
            }
        }

        return true;
    }

    /**
     * Bank a confirmed payment, from PayFast or Paystack.
     *
     * Called only after PayFastNotify or PaystackPayments has run every
     * validation it can; this method's own job is the part that must be
     * atomic. The event row is inserted first and its unique index on
     * pf_payment_id is what makes a repeat delivery harmless — see
     * DirectoryVerificationEventModel::claim(). Paystack payments are claimed
     * as 'paystack:{transaction id}', so the two gateways' ids never collide.
     *
     * $facts is what the gateway told us, in our terms. PayFast's are read off
     * its notification when it is null:
     *   reference  our reference the payment carried
     *   switch     whether it pays the pending switch; by reference when absent
     *   mandate    columns to write when the payment opens a new recurring
     *              mandate (a PayFast token, a Paystack customer and plan),
     *              empty on a renewal
     *   anchor_day the day of the month the new mandate bills on
     *
     * @param array<string,mixed>      $verification
     * @param array<string,mixed>      $payload      the full notification, stored verbatim
     * @param array<string,mixed>|null $facts
     *
     * @return 'applied'|'duplicate'|'failed'
     */
    public function recordPayment(
        array $verification,
        string $pfPaymentId,
        string $status,
        ?float $amountGross,
        array $payload,
        ?array $facts = null
    ): string {
        $verificationId = (int) $verification['id'];
        $listingId      = (int) $verification['listing_id'];
        $facts ??= $this->payfastFacts($payload);
        $mandate = (array) ($facts['mandate'] ?? []);

        // The first payment of a monthly to yearly switch (startYearlySwitch())
        // or of a move from PayFast to Paystack (startMove()).
        $switchId = trim((string) ($verification['switch_m_payment_id'] ?? ''));
        $isSwitch = $switchId !== '' && (bool) ($facts['switch'] ?? (trim((string) ($facts['reference'] ?? '')) === $switchId));
        $isMove   = $isSwitch && DirectoryVerificationModel::providerOf($verification) !== ($mandate['provider'] ?? DirectoryVerificationModel::providerOf($verification));

        $db = db_connect();
        $db->transBegin();

        try {
            if (! $this->events->claim($verificationId, $pfPaymentId, $status, $amountGross, $payload)) {
                // Already banked, or storage refused it. Either way we must not
                // extend the subscription; roll back so the claim attempt leaves
                // nothing behind.
                $db->transRollback();

                return 'duplicate';
            }

            if ($status === 'COMPLETE') {
                $update = ['state' => DirectoryVerificationModel::STATE_ACTIVE];

                // The mandate (PayFast's recurring token, Paystack's customer
                // and plan) arrives with the first payment of a subscription
                // and is absent from later cycles; only write it when it is
                // actually there, or a renewal would blank it.
                //
                // The billing day is captured in the same breath, and only here.
                // A new mandate is the one moment the day the gateway bills on
                // can change.
                //
                // Reading it on every notification instead would undo the fix it
                // exists for: if PayFast reports the current cycle's date rather
                // than the subscription's on a renewal, a 31st subscriber would
                // re-anchor to the 28th the first time February clamped, and
                // never find its way back to the 31st.
                $anchorDay = $verification['billing_anchor_day'] ?? null;
                $anchorDay = $anchorDay === null ? null : (int) $anchorDay;

                if ($mandate !== []) {
                    $update += $mandate;

                    $fromGateway = isset($facts['anchor_day']) ? (int) $facts['anchor_day'] : null;
                    if ($fromGateway !== null && $fromGateway >= 1 && $fromGateway <= 31) {
                        $anchorDay                    = $fromGateway;
                        $update['billing_anchor_day'] = $fromGateway;
                    }
                }

                $switchCycle = DirectoryVerificationModel::switchCycleOf($verification);
                $yearly      = $isSwitch
                    ? $switchCycle === DirectoryVerificationModel::CYCLE_YEARLY
                    : DirectoryVerificationModel::cycleOf($verification) === DirectoryVerificationModel::CYCLE_YEARLY;

                if ($isSwitch) {
                    $update['billing_cycle']       = $switchCycle;
                    $update['amount']              = (string) $verification['switch_amount'];
                    $update['pf_m_payment_id']     = $switchId;
                    $update['switch_m_payment_id'] = null;
                    $update['switch_amount']       = null;
                    $update['switch_cycle']        = null;
                    $update['cancelled_at']        = null;
                }

                // A switch keeps the days left on the month already paid: the
                // year runs on from paid_until on its own day, not the new
                // mandate's, the first time. Renewals after that follow the
                // yearly mandate's billing day like any other.
                $paidUntil = $this->nextPaidUntil(
                    $verification['paid_until'] ?? null,
                    $yearly ? 12 : 1,
                    $isSwitch ? null : $anchorDay
                );
                $update['paid_until'] = $paidUntil;

                if (empty($verification['activated_at'])) {
                    $update['activated_at'] = date('Y-m-d H:i:s');
                }

                $this->verifications->update($verificationId, $update);

                // Which column this payment extends is the plan's to decide.
                // The badge writes verified_until, which only makes a badge
                // render; the International Listing plan writes
                // hosting_paid_until, which is what lets the listing be
                // published at all — so it also has to publish it, or the
                // owner pays and nothing visibly happens.
                if ($this->isInternational($verification)) {
                    $this->applyHostingPaidUntil($listingId, $paidUntil);

                    $listing = $this->listings->find($listingId);
                    if (is_array($listing)) {
                        $this->publishIfPaidFor($listing);
                    }
                } else {
                    $this->applyPaidUntil($listingId, $paidUntil);
                }
            }

            $db->transCommit();
        } catch (\Throwable $e) {
            $db->transRollback();
            log_message('error', 'Verification payment could not be recorded: ' . Mailer::oneLine($e->getMessage()));

            return 'failed';
        }

        if ($status === 'COMPLETE' && empty($verification['activated_at'])) {
            $this->mailOwner($listingId, 'activated', [
                'product' => $this->productOf($verification),
                'cycle'   => DirectoryVerificationModel::cycleOf($verification),
            ]);
        }

        // The new mandate is live, so the old one must stop. After the
        // commit, like the mail: the period is banked whatever the gateway
        // answers, and a failed cancel goes to a human, as a failed owner
        // cancel does. The old row goes in without its id, so a Paystack
        // lookup cannot write the old subscription onto the new mandate.
        if ($status === 'COMPLETE' && $isSwitch && DirectoryVerificationModel::hasMandate($verification)
            && ! $this->sameMandate($verification, $mandate)) {
            $old     = array_diff_key($verification, ['id' => true]);
            $gateway = $this->gatewayName($verification);
            $did     = $isMove ? 'moved to Paystack' : 'switched to yearly';
            if (! $this->cancelMandate($old)) {
                $this->notifyAdminOfCancellation($listingId, strtoupper($did) . ' but the old ' . $gateway . ' subscription could not be cancelled. Cancel it by hand in the ' . $gateway . ' dashboard');
            } else {
                $this->notifyAdminOfCancellation($listingId, $did . '; the old ' . $gateway . ' subscription was cancelled');
            }
        }

        // Partner commission, after the commit like the mail above: the
        // payment is banked whatever happens here. recordCommission() swallows
        // its own failures, and partners:sweep rebuilds any it missed.
        if ($status === 'COMPLETE') {
            try {
                (new PartnerService())->recordCommission($verificationId, $pfPaymentId, $amountGross);
            } catch (\Throwable $e) {
                log_message('error', 'Partner commission hook failed: ' . Mailer::oneLine($e->getMessage()));
            }
        }

        return 'applied';
    }

    /**
     * recordPayment()'s facts from a PayFast notification: the reference it
     * carried, the recurring token on the first payment of a subscription,
     * and the billing day PayFast sent with that token.
     *
     * @param array<string,mixed> $payload
     *
     * @return array<string,mixed>
     */
    private function payfastFacts(array $payload): array
    {
        $token = trim((string) ($payload['token'] ?? ''));

        return [
            'reference'  => trim((string) ($payload['m_payment_id'] ?? '')),
            'mandate'    => $token === '' ? [] : [
                'provider'              => DirectoryVerificationModel::PROVIDER_PAYFAST,
                'pf_subscription_token' => $token,
            ],
            'anchor_day' => $token === '' ? null : $this->billingAnchorDay($payload),
        ];
    }

    /**
     * Is the mandate a payment opened the one the row already had? Then a
     * switch has nothing old to cancel. No new mandate at all counts as
     * different, as it always has for PayFast.
     *
     * @param array<string,mixed> $row
     * @param array<string,mixed> $mandate
     */
    private function sameMandate(array $row, array $mandate): bool
    {
        if ($mandate === [] || ($mandate['provider'] ?? '') !== DirectoryVerificationModel::providerOf($row)) {
            return false;
        }
        if ($mandate['provider'] === DirectoryVerificationModel::PROVIDER_PAYFAST) {
            return ($mandate['pf_subscription_token'] ?? '') === trim((string) ($row['pf_subscription_token'] ?? ''));
        }

        return ($mandate['paystack_customer_code'] ?? '') === (string) ($row['paystack_customer_code'] ?? '')
            && ($mandate['paystack_plan_code'] ?? '') === (string) ($row['paystack_plan_code'] ?? '');
    }

    /**
     * Move `active` rows whose paid_until has passed to `lapsed`, and clear the
     * badge date on their listings.
     *
     * Note what this is NOT for: the public badge already hides itself, because
     * every render compares verified_until against today. That is deliberate —
     * a badge that outlived its payment because a cron job was wedged would be
     * a claim we are being paid to make and are not. This method exists so the
     * admin view and the state machine agree with what the public already sees.
     *
     * @return int rows lapsed
     */
    public function lapseExpired(): int
    {
        $today = date('Y-m-d');

        $due = $this->verifications
            ->where('state', DirectoryVerificationModel::STATE_ACTIVE)
            ->where('paid_until <', $today)
            ->findAll();

        $lapsed = 0;
        foreach ($due as $row) {
            $db = db_connect();
            $db->transBegin();

            try {
                $this->verifications->update((int) $row['id'], [
                    'state' => DirectoryVerificationModel::STATE_LAPSED,
                ]);

                if ($this->isInternational($row) && ! $this->plusMode()) {
                    // Unlike the badge, this plan is what keeps the listing
                    // public, so lapsing has to take it down. 'unpublished' is
                    // already in the status ENUM and every read path filters
                    // on status = 'published', so nothing else needs changing —
                    // and nothing is deleted, so paying again brings the
                    // listing back exactly as it was.
                    //
                    // Not a render-time check like the badge's, deliberately.
                    // A badge outliving its payment is a claim we are not
                    // being paid to make; a listing staying up a few hours
                    // longer is not, and the alternative is a country
                    // comparison bolted onto browse(), the map, the sitemap
                    // and every other query that filters on status.
                    $this->applyHostingPaidUntil((int) $row['listing_id'], null);
                    $this->listings->update((int) $row['listing_id'], ['status' => 'unpublished']);
                } elseif ($this->isInternational($row)) {
                    // Plus lapsing takes away its extras, never the profile.
                    $this->applyHostingPaidUntil((int) $row['listing_id'], null);
                } else {
                    $this->applyPaidUntil((int) $row['listing_id'], null);
                }

                $db->transCommit();
                $lapsed++;
            } catch (\Throwable $e) {
                $db->transRollback();
                log_message('error', 'Could not lapse verification ' . (int) $row['id'] . ': ' . Mailer::oneLine($e->getMessage()));
            }
        }

        return $lapsed;
    }

    /**
     * Active subscriptions renewing in exactly $days days, for the reminder
     * mail. Exactly, not "within" — the sweep runs daily, and a range would
     * mail the same person every day of the window.
     *
     * @return array<int,array<string,mixed>>
     */
    public function dueForRenewalReminder(int $days = 3, string $cycle = DirectoryVerificationModel::CYCLE_MONTHLY): array
    {
        return $this->verifications
            ->where('state', DirectoryVerificationModel::STATE_ACTIVE)
            ->where('billing_cycle', $cycle)
            ->where('paid_until', date('Y-m-d', strtotime("+{$days} days")))
            ->findAll();
    }

    public function sendRenewalReminder(array $verification): void
    {
        $this->mailOwner(
            (int) $verification['listing_id'],
            'renewing',
            ['paid_until' => $verification['paid_until'], 'amount' => $verification['amount'], 'product' => $this->productOf($verification), 'cycle' => DirectoryVerificationModel::cycleOf($verification), 'gateway' => $this->gatewayName($verification)]
        );
    }

    /**
     * Tell the owner a Paystack renewal failed, with Paystack's page for
     * updating the card (or their dashboard when Paystack gave none).
     *
     * @param array<string,mixed> $verification
     */
    public function sendPaymentFailed(array $verification, ?string $cardLink): void
    {
        $this->mailOwner((int) $verification['listing_id'], 'payment_failed', [
            'product'   => $this->productOf($verification),
            'cycle'     => DirectoryVerificationModel::cycleOf($verification),
            'paidUntil' => $this->prettyDate($verification['paid_until'] ?? null),
            'cardLink'  => $cardLink,
        ]);
    }

    /** An operational alert about a listing's subscription, to the admin inbox. */
    public function notifyAdmin(int $listingId, string $detail): void
    {
        $this->notifyAdminOfCancellation($listingId, $detail);
    }

    /**
     * What a verification row sells, as the owner emails name it: 'badge',
     * 'international' (the co.za plan before .app) or 'plus'.
     *
     * @param array<string,mixed> $row
     */
    private function productOf(array $row): string
    {
        if (! $this->isInternational($row)) {
            return 'badge';
        }

        return $this->plusMode() ? 'plus' : 'international';
    }

    /**
     * Counts for the admin status panel.
     *
     * @return array<string,int>
     */
    public function counts(): array
    {
        return $this->verifications->counts();
    }

    // ---------------------------------------------------------------- internals

    /**
     * Is this verification row the International Listing plan?
     *
     * Defaults to the badge when `plan` is absent, which is what every row
     * written before the column existed looks like to old code paths and to a
     * row array assembled in a test.
     *
     * @param array<string,mixed> $verification
     */
    private function isInternational(array $verification): bool
    {
        return ($verification['plan'] ?? DirectoryVerificationModel::PLAN_BADGE)
            === DirectoryVerificationModel::PLAN_INTERNATIONAL;
    }

    /**
     * The one place xs_directory_listings.verified_until is written.
     *
     * Goes through the model rather than a raw builder so the column stays
     * subject to $allowedFields — if someone later removes it from that list
     * thinking it unused, this breaks loudly instead of silently no-opping.
     */
    private function applyPaidUntil(int $listingId, ?string $paidUntil): void
    {
        $this->listings->update($listingId, ['verified_until' => $paidUntil]);
    }

    /**
     * The one place xs_directory_listings.hosting_paid_until is written.
     *
     * The International Listing plan's twin of applyPaidUntil(), and held to
     * the same rule for the same reason: one writer means one place to read
     * when asking "how is this listing published?". Neither column is in
     * OWNER_EDITABLE nor in DirectoryAdminService::upsert()'s privileged
     * block, so no form post of any kind can reach either.
     */
    private function applyHostingPaidUntil(int $listingId, ?string $paidUntil): void
    {
        $this->listings->update($listingId, ['hosting_paid_until' => $paidUntil]);
    }

    /**
     * Publish a listing whose subscription has just been paid.
     *
     * Only ever moves a listing that is waiting on money, and only once its
     * owner has confirmed their email — `is_verified` is a separate question
     * from payment and paying does not answer it. A listing an admin has
     * rejected stays rejected: money does not overturn a moderation decision.
     *
     * @param array<string,mixed> $listing
     */
    private function publishIfPaidFor(array $listing): void
    {
        $status = (string) ($listing['status'] ?? '');

        if (empty($listing['is_verified']) || ! in_array($status, ['pending', 'unpublished'], true)) {
            return;
        }

        $update = ['status' => 'published'];
        if (empty($listing['published_at'])) {
            $update['published_at'] = date('Y-m-d H:i:s');
        }

        $this->listings->update((int) $listing['id'], $update);
    }

    /**
     * One month on from whichever is later: today, or the date already paid to
     * — landing on the day of the month PayFast actually bills.
     *
     * The max() is what makes renewals safe. Extending from today would quietly
     * shorten the term of anyone who renews early — PayFast bills on its own
     * schedule, not ours — and extending from paid_until alone would back-date a
     * subscriber who lapsed for six months and came back, giving them a badge
     * that expired before it was bought.
     *
     * $anchorDay is the day of the month PayFast bills this subscription on, and
     * it is a separate input rather than something read back off $from because a
     * renewal date cannot be derived from the previous renewal date. PHP's
     * `+1 month` overflows a day the next month does not have — 31 January
     * becomes 3 March — while PayFast clamps to the last day and then returns to
     * the original day: 28 February, 31 March, 30 April.
     *
     * Merely clamping would be worse than the overflow it fixes. Clamp 31
     * January to 28 February, then compute the next renewal from *that*, and you
     * get 28 March against a PayFast charge on 31 March: three days in which a
     * paying customer has no badge. Only a remembered anchor keeps the two in
     * step, which is what the billing_anchor_day column is for. It falls back to
     * the day of $from, which is right for a badge activated by hand — there is
     * no PayFast mandate behind it and so no billing day to honour.
     *
     * The arithmetic itself lives in addMonthsAnchored() so it can be tested
     * against fixed dates. This method cannot be: it resolves $from against the
     * clock, so any past date handed to it collapses to today.
     */
    private function nextPaidUntil(?string $current, int $months = 1, ?int $anchorDay = null): string
    {
        $today = date('Y-m-d');
        $from  = ($current !== null && $current !== '' && $current > $today) ? $current : $today;

        return $this->addMonthsAnchored($from, $months, $anchorDay);
    }

    /**
     * $months on from $from, landing on $anchorDay of the target month, or the
     * last day of that month when the anchor does not fit in it.
     *
     * Pure: no clock, no database. That is the point — it is the half of the
     * renewal date that can be checked against known answers.
     *
     * The month arithmetic runs on the FIRST of the month, which cannot
     * overflow, and the day is placed inside the target month afterwards. Doing
     * it the other way round — adding a month to the 31st and hoping — is the
     * whole bug this replaced.
     */
    private function addMonthsAnchored(string $from, int $months = 1, ?int $anchorDay = null): string
    {
        $anchorDay = $anchorDay !== null && $anchorDay >= 1 && $anchorDay <= 31
            ? $anchorDay
            : (int) date('j', strtotime($from));

        $firstOfTarget = date(
            'Y-m-01',
            strtotime(date('Y-m-01', strtotime($from)) . ' +' . max(1, $months) . ' month')
        );

        $day = min($anchorDay, (int) date('t', strtotime($firstOfTarget)));

        return date('Y-m-d', strtotime($firstOfTarget . ' +' . ($day - 1) . ' days'));
    }

    /**
     * The day of the month a PayFast subscription bills on, from the ITN that
     * opened it.
     *
     * PayFast sends `billing_date` alongside `token` on the first notification
     * of a subscription. Reading it is better than reconstructing it from the
     * payment date, because the two can differ — a payment can land a day after
     * the date the subscription is anchored to, and it is the anchor that
     * decides every future charge.
     *
     * @param array<string,mixed> $payload
     */
    private function billingAnchorDay(array $payload): ?int
    {
        $billingDate = trim((string) ($payload['billing_date'] ?? ''));
        if ($billingDate === '') {
            return null;
        }

        $ts = strtotime($billingDate);

        return $ts === false ? null : (int) date('j', $ts);
    }

    /**
     * Remove files that were stored before the row they belong to could be
     * written. Best-effort by design: this runs on a failure path, and throwing
     * here would replace a clear error with a confusing one.
     *
     * @param array<string,array{name:string}> $documents
     */
    /**
     * Freeze an admin's ruling onto the application it was made about.
     *
     * Silent when there is no submission row — a verification created before
     * this table existed and never re-applied has nothing to write to, and a
     * missing history row is not a reason to fail a decision the admin has
     * already made and the owner has already been emailed about.
     */
    private function recordDecision(int $verificationId, string $outcome, string $by, ?string $reason = null, ?string $note = null): void
    {
        $submission = $this->submissions->latestForVerification($verificationId);
        if ($submission === null) {
            return;
        }

        $this->submissions->recordDecision((int) $submission['id'], $outcome, $by, $reason, $note);
    }

    private function discardStoredFiles(int $listingId, array $documents): void
    {
        foreach ($documents as $doc) {
            if (isset($doc['name'])) {
                $this->documents->deleteFileAt($listingId . '/' . $doc['name']);
            }
        }
    }

    /**
     * Stop the recurring mandate behind a row, through whichever gateway holds
     * it. True only when the gateway confirmed it.
     *
     * A Paystack row may not know its subscription code yet (the subscription
     * opens just after the first charge), so it is looked up from the
     * customer and plan, and stored, on the way.
     *
     * @param array<string,mixed> $row
     */
    public function cancelMandate(array $row): bool
    {
        if (DirectoryVerificationModel::providerOf($row) !== DirectoryVerificationModel::PROVIDER_PAYSTACK) {
            $token = trim((string) ($row['pf_subscription_token'] ?? ''));

            return $token !== '' && $this->payfast->cancelSubscription($token);
        }

        $sub = $this->paystackSubscription($row);

        return $sub !== null && $this->paystack->disableSubscription($sub['code'], $sub['email_token']);
    }

    /**
     * A Paystack row's subscription code and email token, from the row or
     * looked up and saved to it.
     *
     * @param array<string,mixed> $row
     *
     * @return array{code:string,email_token:string}|null
     */
    public function paystackSubscription(array $row): ?array
    {
        $code  = trim((string) ($row['paystack_subscription_code'] ?? ''));
        $token = trim((string) ($row['paystack_email_token'] ?? ''));
        if ($code !== '' && $token !== '') {
            return ['code' => $code, 'email_token' => $token];
        }

        $found = $this->paystack->findSubscription(
            (string) ($row['paystack_customer_code'] ?? ''),
            (string) ($row['paystack_plan_code'] ?? '')
        );
        if ($found !== null && ! empty($row['id'])) {
            $this->verifications->update((int) $row['id'], [
                'paystack_subscription_code' => $found['code'],
                'paystack_email_token'       => $found['email_token'],
            ]);
        }

        return $found;
    }

    /** 'PayFast' or 'Paystack', for admin alerts and owner emails. */
    private function gatewayName(array $row): string
    {
        return DirectoryVerificationModel::providerOf($row) === DirectoryVerificationModel::PROVIDER_PAYSTACK ? 'Paystack' : 'PayFast';
    }

    /**
     * Tell the operator a subscription was cancelled — and, when the API call
     * failed, that they have to finish the job by hand.
     *
     * Plain text rather than a template: it is an operational alert to one
     * person, not correspondence with a customer.
     */
    private function notifyAdminOfCancellation(int $listingId, string $detail): void
    {
        $admin = $this->config->adminEmail();
        if ($admin === '') {
            log_message('warning', 'Verification cancellation for listing ' . $listingId . ' (' . $detail . ') but no admin email is configured.');

            return;
        }

        $listing = $this->listings->find($listingId);
        $name    = is_array($listing) ? (string) ($listing['display_name'] ?? '') : ('listing ' . $listingId);

        (new Mailer())->send(
            $admin,
            'Verified Business cancelled: ' . $name,
            '<p><strong>' . esc($name) . '</strong> cancelled their Verified Business badge.</p>'
            . '<p>' . esc($detail) . '</p>'
            . '<p><a href="' . esc(site_url_for(Sites::ZA, 'admin/verifications?state=active'), 'attr') . '">Open the queue</a></p>'
        );
    }

    /** The badge's yearly price for the approval email, '' when yearly is not on sale. */
    private function yearlyAmountForEmail(): string
    {
        return (string) ($this->amountFor(DirectoryVerificationModel::PLAN_BADGE, DirectoryVerificationModel::CYCLE_YEARLY) ?? '');
    }

    private function prettyDate(?string $date): string
    {
        $ts = $date === null || $date === '' ? false : strtotime($date);

        return $ts === false ? 'it expires' : date('j F Y', $ts);
    }

    private function notifyAdminOfSubmission(int $listingId): void
    {
        $admin = $this->config->adminEmail();
        if ($admin === '') {
            return;
        }

        $listing = $this->listings->find($listingId);
        if (! is_array($listing)) {
            return;
        }

        $body = view('emails/verification-admin', [
            'site'    => $this->config->siteName(),
            'listing' => $listing,
            'url'     => site_url_for(Sites::ZA, 'admin/verifications'),
        ]);

        (new Mailer())->send(
            $admin,
            'Verification documents submitted: ' . ($listing['display_name'] ?? ''),
            $body
        );
    }

    /**
     * @param 'approved'|'rejected'|'activated'|'renewing'|'payment_failed'|'move' $event
     * @param array<string,mixed>                          $extra
     */
    private function mailOwner(int $listingId, string $event, array $extra): void
    {
        $listing = $this->listings->find($listingId);
        if (! is_array($listing) || trim((string) ($listing['email'] ?? '')) === '') {
            return;
        }

        $what     = ['plus' => 'WebScheduler Local Plus', 'international' => 'International Profile'][$extra['product'] ?? ''] ?? 'Verified Business badge';
        $subjects = [
            'approved'  => 'Your documents are approved: activate your Verified Business badge',
            'rejected'  => 'We could not verify your business yet',
            'activated'      => 'Your ' . $what . ' is live',
            'renewing'       => 'Your ' . $what . ' renews soon',
            'payment_failed' => 'We could not take your ' . $what . ' payment',
            'move'           => 'Please move your ' . $what . ' payment to Paystack',
        ];

        $body = view('emails/verification-owner', array_merge([
            'site'       => $this->config->siteName(),
            'name'       => (string) ($listing['display_name'] ?? ''),
            'event'      => $event,
            'manageLink' => $this->ownerLink($listingId, $event),
        ], $extra));

        (new Mailer())->send((string) $listing['email'], $subjects[$event], $body);
    }

    /**
     * The button target for an owner email.
     *
     * The two events that ask the owner to *do* something carry a live magic
     * link, because both follow a decision only we could have made — the owner
     * cannot act until we mail them, and sending them to a sign-in page to
     * request a second email before they can pay was the longest detour in the
     * whole funnel. They proved inbox control by opening this message; asking
     * for the same proof again buys nothing.
     *
     * 'approved' goes straight to checkout via Manage::REDEEM_DESTINATIONS.
     * 'activated' and 'renewing' stay on the plain sign-in page: they are
     * receipts, and a live credential in a receipt is a credential with no job
     * to do.
     *
     * A minting failure must not cost the owner the email itself, so this falls
     * back to the sign-in page — the slow path still works.
     */
    private function ownerLink(int $listingId, string $event): string
    {
        if (! in_array($event, ['approved', 'rejected', 'move'], true)) {
            return listing_site_url($listingId, 'manage');
        }

        try {
            $token = (new DirectoryListingMutationService())
                ->mintManageToken($listingId, $this->config->approvalLinkTtl);
        } catch (Throwable $e) {
            log_message('error', 'Could not mint an owner link for listing {id}: {msg}', [
                'id'  => $listingId,
                'msg' => Mailer::oneLine($e->getMessage()),
            ]);

            return listing_site_url($listingId, 'manage');
        }

        return match ($event) {
            'approved' => listing_site_url($listingId, 'manage/' . $token) . '?to=checkout',
            'move'     => listing_site_url($listingId, 'manage/' . $token) . '?to=move',
            default    => listing_site_url($listingId, 'manage/' . $token),
        };
    }
}

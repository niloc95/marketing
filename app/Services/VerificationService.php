<?php

namespace App\Services;

use App\Libraries\Mailer;
use App\Libraries\PayFast;
use App\Models\DirectoryListingModel;
use App\Models\DirectoryVerificationDocumentModel;
use App\Models\DirectoryVerificationSubmissionModel;
use App\Models\DirectoryVerificationEventModel;
use App\Models\DirectoryVerificationModel;
use Config\Directory as DirectoryConfig;
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
    private DirectorySettings $settings;

    /**
     * PayFast is injectable for one reason: cancelSubscription() has a failure
     * branch that must never be mistaken for success, and the only way to prove
     * both branches behave is to be able to make the call fail on demand. The
     * default keeps every caller in the app unchanged.
     */
    public function __construct(?PayFast $payfast = null)
    {
        $this->verifications = new DirectoryVerificationModel();
        $this->documents     = new DirectoryVerificationDocumentModel();
        $this->submissions   = new DirectoryVerificationSubmissionModel();
        $this->events        = new DirectoryVerificationEventModel();
        $this->listings      = new DirectoryListingModel();
        $this->config        = config('Directory');
        $this->payfast       = $payfast ?? new PayFast();
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
        return $this->isEnabled() && $this->payfast->isConfigured();
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

    /** As monthlyAmount(), for the other plan. */
    public function internationalAmount(): string
    {
        return $this->settings->internationalPrice();
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
        if (! is_array($listing) || ! $this->requiresSubscription($listing)) {
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
                . $listingId . ': ' . $this->oneLine($e->getMessage()));

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
                'message' => 'We need both documents — the company registration document and the owner’s ID.',
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
                    ? 'Your listing is already verified.'
                    : 'Your documents are already with us — we’ll email you when the review is done.',
            ];
        }

        $db = db_connect();
        $db->transBegin();

        try {
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

            $db->transCommit();
        } catch (\Throwable $e) {
            $db->transRollback();
            log_message('error', 'Verification application failed and was rolled back: ' . $this->oneLine($e->getMessage()));
            $this->discardStoredFiles($listingId, $documents);

            return ['ok' => false, 'message' => 'We could not store your documents. Please try again.'];
        }

        $this->notifyAdminOfSubmission($listingId);

        return [
            'ok'      => true,
            'message' => 'Thanks — your documents are with us. We’ll email you once they have been reviewed, '
                . 'and you’ll only be asked to pay after they are approved.',
        ];
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
            $this->mailOwner((int) $row['listing_id'], 'approved', ['amount' => $row['amount']]);
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

        if ($row === null || $row['state'] !== DirectoryVerificationModel::STATE_ACTIVE) {
            return ['ok' => false, 'message' => 'There is no active ' . $what . ' to cancel.'];
        }

        if (! empty($row['cancelled_at'])) {
            return [
                'ok'      => true,
                'message' => $isInternational
                    ? 'Your subscription is already cancelled — your profile stays live until it expires.'
                    : 'Your badge is already cancelled — it stays up until it expires.',
            ];
        }

        $token = trim((string) ($row['pf_subscription_token'] ?? ''));

        // No token means the badge was activated by hand — there is no recurring
        // mandate anywhere to stop, so calling PayFast would fail for a reason
        // that has nothing to do with the owner. Record the intent and tell a
        // human, since somebody may be invoicing them by EFT.
        if ($token === '') {
            $this->verifications->update((int) $row['id'], ['cancelled_at' => date('Y-m-d H:i:s')]);
            $this->notifyAdminOfCancellation($listingId, 'manually-activated ' . $what . ', no PayFast subscription to cancel');

            return [
                'ok'      => true,
                'message' => $isInternational
                    ? 'Your subscription will not renew. Your profile stays live until '
                        . $this->prettyDate($row['paid_until']) . ', then comes down.'
                    : 'Your badge will not renew. It stays on your profile until '
                        . $this->prettyDate($row['paid_until']) . '.',
            ];
        }

        if (! $this->payfast->cancelSubscription($token)) {
            $this->notifyAdminOfCancellation($listingId, 'PAYFAST API CALL FAILED — cancel this subscription by hand in the PayFast dashboard');

            return [
                'ok'      => false,
                'message' => 'We could not cancel your subscription automatically. We have alerted our team '
                    . 'and someone will sort it out today — you will get an email confirming it.',
            ];
        }

        $this->verifications->update((int) $row['id'], ['cancelled_at' => date('Y-m-d H:i:s')]);
        $this->notifyAdminOfCancellation($listingId, 'cancelled through PayFast successfully');

        return [
            'ok'      => true,
            'message' => $isInternational
                ? 'Cancelled — you will not be charged again. Your profile stays live until '
                    . $this->prettyDate($row['paid_until']) . ', then comes down.'
                : 'Cancelled — you will not be charged again. Your badge stays on your profile until '
                    . $this->prettyDate($row['paid_until']) . '.',
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
            log_message('error', 'Manual verification activation failed: ' . $this->oneLine($e->getMessage()));

            return false;
        }

        if (empty($row['activated_at'])) {
            $this->mailOwner((int) $row['listing_id'], 'activated', []);
        }

        return true;
    }

    /**
     * Pull a live badge immediately — for a business that turns out not to be
     * what its documents claimed. Separate from lapsing, which is just
     * non-payment and carries no judgement.
     *
     * Deliberately does not cancel the PayFast subscription: we cannot, from
     * here, and pretending otherwise would be worse than saying so. The admin
     * has to cancel it in the PayFast dashboard, which the flash message says.
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
                $this->listings->update((int) $row['listing_id'], ['status' => 'unpublished']);
            } else {
                $this->applyPaidUntil((int) $row['listing_id'], null);
            }

            $db->transCommit();
        } catch (\Throwable $e) {
            $db->transRollback();
            log_message('error', 'Verification revoke failed: ' . $this->oneLine($e->getMessage()));

            return false;
        }

        return true;
    }

    /**
     * Bank a confirmed PayFast payment.
     *
     * Called only after PayFastNotify has run every validation it can; this
     * method's own job is the part that must be atomic. The event row is
     * inserted first and its unique index on pf_payment_id is what makes a
     * repeat delivery harmless — see DirectoryVerificationEventModel::claim().
     *
     * @param array<string,mixed> $verification
     * @param array<string,mixed> $payload      the full POST, stored verbatim
     *
     * @return 'applied'|'duplicate'|'failed'
     */
    public function recordPayment(
        array $verification,
        string $pfPaymentId,
        string $status,
        ?float $amountGross,
        array $payload
    ): string {
        $verificationId = (int) $verification['id'];
        $listingId      = (int) $verification['listing_id'];

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

                // The recurring token arrives with the first payment of a
                // subscription and is absent from later cycles; only write it
                // when it is actually there, or a renewal would blank it.
                $token = trim((string) ($payload['token'] ?? ''));

                // The billing day is captured in the same breath, and only here.
                // A token means a new recurring mandate has begun, which is the
                // one moment the day PayFast bills on can change.
                //
                // Reading it on every notification instead would undo the fix it
                // exists for: if PayFast reports the current cycle's date rather
                // than the subscription's on a renewal, a 31st subscriber would
                // re-anchor to the 28th the first time February clamped, and
                // never find its way back to the 31st.
                $anchorDay = $verification['billing_anchor_day'] ?? null;
                $anchorDay = $anchorDay === null ? null : (int) $anchorDay;

                if ($token !== '') {
                    $update['pf_subscription_token'] = $token;

                    $fromPayload = $this->billingAnchorDay($payload);
                    if ($fromPayload !== null) {
                        $anchorDay                    = $fromPayload;
                        $update['billing_anchor_day'] = $fromPayload;
                    }
                }

                $paidUntil            = $this->nextPaidUntil($verification['paid_until'] ?? null, 1, $anchorDay);
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
            log_message('error', 'Verification payment could not be recorded: ' . $this->oneLine($e->getMessage()));

            return 'failed';
        }

        if ($status === 'COMPLETE' && empty($verification['activated_at'])) {
            $this->mailOwner($listingId, 'activated', []);
        }

        return 'applied';
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

                if ($this->isInternational($row)) {
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
                } else {
                    $this->applyPaidUntil((int) $row['listing_id'], null);
                }

                $db->transCommit();
                $lapsed++;
            } catch (\Throwable $e) {
                $db->transRollback();
                log_message('error', 'Could not lapse verification ' . (int) $row['id'] . ': ' . $this->oneLine($e->getMessage()));
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
    public function dueForRenewalReminder(int $days = 3): array
    {
        return $this->verifications
            ->where('state', DirectoryVerificationModel::STATE_ACTIVE)
            ->where('paid_until', date('Y-m-d', strtotime("+{$days} days")))
            ->findAll();
    }

    public function sendRenewalReminder(array $verification): void
    {
        $this->mailOwner(
            (int) $verification['listing_id'],
            'renewing',
            ['paid_until' => $verification['paid_until'], 'amount' => $verification['amount']]
        );
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
    private function recordDecision(int $verificationId, string $outcome, string $by, ?string $reason = null): void
    {
        $submission = $this->submissions->latestForVerification($verificationId);
        if ($submission === null) {
            return;
        }

        $this->submissions->recordDecision((int) $submission['id'], $outcome, $by, $reason);
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
            . '<p><a href="' . esc(base_url('admin/verifications?state=active'), 'attr') . '">Open the queue</a></p>'
        );
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
            'url'     => base_url('admin/verifications'),
        ]);

        (new Mailer())->send(
            $admin,
            'Verification documents submitted: ' . ($listing['display_name'] ?? ''),
            $body
        );
    }

    /**
     * @param 'approved'|'rejected'|'activated'|'renewing' $event
     * @param array<string,mixed>                          $extra
     */
    private function mailOwner(int $listingId, string $event, array $extra): void
    {
        $listing = $this->listings->find($listingId);
        if (! is_array($listing) || trim((string) ($listing['email'] ?? '')) === '') {
            return;
        }

        $subjects = [
            'approved'  => 'Your documents are approved — activate your Verified Business badge',
            'rejected'  => 'We could not verify your business yet',
            'activated' => 'Your Verified Business badge is live',
            'renewing'  => 'Your Verified Business badge renews soon',
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
        if ($event !== 'approved' && $event !== 'rejected') {
            return base_url('manage');
        }

        try {
            $token = (new DirectoryListingMutationService())
                ->mintManageToken($listingId, $this->config->approvalLinkTtl);
        } catch (Throwable $e) {
            log_message('error', 'Could not mint an owner link for listing {id}: {msg}', [
                'id'  => $listingId,
                'msg' => $this->oneLine($e->getMessage()),
            ]);

            return base_url('manage');
        }

        return $event === 'approved'
            ? base_url('manage/' . $token) . '?to=checkout'
            : base_url('manage/' . $token);
    }

    /** Collapse newlines so a failure message can't forge extra log lines. */
    private function oneLine(string $s): string
    {
        return trim((string) preg_replace('/\s+/', ' ', $s));
    }
}

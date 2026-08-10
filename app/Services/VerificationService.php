<?php

namespace App\Services;

use App\Libraries\Mailer;
use App\Libraries\PayFast;
use App\Models\DirectoryListingModel;
use App\Models\DirectoryVerificationDocumentModel;
use App\Models\DirectoryVerificationEventModel;
use App\Models\DirectoryVerificationModel;
use Config\Directory as DirectoryConfig;

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
    private DirectoryVerificationEventModel $events;
    private DirectoryListingModel $listings;
    private DirectoryConfig $config;
    private PayFast $payfast;

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
        $this->events        = new DirectoryVerificationEventModel();
        $this->listings      = new DirectoryListingModel();
        $this->config        = config('Directory');
        $this->payfast       = $payfast ?? new PayFast();
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
        return $this->config->verifiedBadgeEnabled();
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

    public function monthlyAmount(): string
    {
        return $this->config->verifiedMonthlyAmount();
    }

    /**
     * A listing's application plus its documents, or null if it has never
     * applied. The shape the manage panel and the review queue both render from.
     *
     * @return array{verification:array<string,mixed>,documents:array<int,array<string,mixed>>}|null
     */
    public function forListing(int $listingId): ?array
    {
        $row = $this->verifications->forListing($listingId);
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
     * @param array<string,array{name:string,mime:string,bytes:int}> $documents
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
            if ($existing !== null) {
                // Re-submission after a rejection or a lapse. The superseded
                // evidence goes, files included: keeping an ID copy we have
                // already decided against is retention without a purpose.
                $this->documents->deleteForVerification((int) $existing['id']);

                $this->verifications->update((int) $existing['id'], [
                    'state'            => DirectoryVerificationModel::STATE_SUBMITTED,
                    'amount'           => $this->monthlyAmount(),
                    'rejection_reason' => null,
                    'reviewed_at'      => null,
                    'reviewed_by'      => null,
                    'submitted_at'     => date('Y-m-d H:i:s'),
                ]);
                $verificationId = (int) $existing['id'];
            } else {
                $verificationId = (int) $this->verifications->insert([
                    'listing_id'   => $listingId,
                    'state'        => DirectoryVerificationModel::STATE_SUBMITTED,
                    'amount'       => $this->monthlyAmount(),
                    'submitted_at' => date('Y-m-d H:i:s'),
                ], true);

                if ($verificationId === 0) {
                    throw new \RuntimeException('verification row not created');
                }
            }

            foreach (DirectoryVerificationDocumentModel::KINDS as $kind) {
                $this->documents->insert([
                    'verification_id' => $verificationId,
                    'kind'            => $kind,
                    'path'            => $listingId . '/' . $documents[$kind]['name'],
                    'original_name'   => $documents[$kind]['original_name'] ?? null,
                    'mime'            => $documents[$kind]['mime'],
                    'bytes'           => $documents[$kind]['bytes'],
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
    public function cancelSubscription(int $listingId): array
    {
        $row = $this->verifications->forListing($listingId);

        if ($row === null || $row['state'] !== DirectoryVerificationModel::STATE_ACTIVE) {
            return ['ok' => false, 'message' => 'There is no active badge to cancel.'];
        }

        if (! empty($row['cancelled_at'])) {
            return ['ok' => true, 'message' => 'Your badge is already cancelled — it stays up until it expires.'];
        }

        $token = trim((string) ($row['pf_subscription_token'] ?? ''));

        // No token means the badge was activated by hand — there is no recurring
        // mandate anywhere to stop, so calling PayFast would fail for a reason
        // that has nothing to do with the owner. Record the intent and tell a
        // human, since somebody may be invoicing them by EFT.
        if ($token === '') {
            $this->verifications->update((int) $row['id'], ['cancelled_at' => date('Y-m-d H:i:s')]);
            $this->notifyAdminOfCancellation($listingId, 'manually-activated badge, no PayFast subscription to cancel');

            return [
                'ok'      => true,
                'message' => 'Your badge will not renew. It stays on your profile until '
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
            'message' => 'Cancelled — you will not be charged again. Your badge stays on your profile until '
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
            $paidUntil = $this->nextPaidUntil($row['paid_until'] ?? null, $months);

            $this->verifications->update($verificationId, [
                'state'        => DirectoryVerificationModel::STATE_ACTIVE,
                'paid_until'   => $paidUntil,
                'activated_at' => $row['activated_at'] ?? date('Y-m-d H:i:s'),
                'reviewed_by'  => $by,
            ]);
            $this->applyPaidUntil((int) $row['listing_id'], $paidUntil);

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
            $this->applyPaidUntil((int) $row['listing_id'], null);
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
                $paidUntil = $this->nextPaidUntil($verification['paid_until'] ?? null);

                $update = [
                    'state'      => DirectoryVerificationModel::STATE_ACTIVE,
                    'paid_until' => $paidUntil,
                ];

                // The recurring token arrives with the first payment of a
                // subscription and is absent from later cycles; only write it
                // when it is actually there, or a renewal would blank it.
                $token = trim((string) ($payload['token'] ?? ''));
                if ($token !== '') {
                    $update['pf_subscription_token'] = $token;
                }

                if (empty($verification['activated_at'])) {
                    $update['activated_at'] = date('Y-m-d H:i:s');
                }

                $this->verifications->update($verificationId, $update);
                $this->applyPaidUntil($listingId, $paidUntil);
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
                $this->applyPaidUntil((int) $row['listing_id'], null);
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
     * One month on from whichever is later: today, or the date already paid to.
     *
     * The max() is what makes renewals safe. Extending from today would quietly
     * shorten the term of anyone who renews early — PayFast bills on its own
     * schedule, not ours — and extending from paid_until alone would back-date a
     * subscriber who lapsed for six months and came back, giving them a badge
     * that expired before it was bought.
     */
    private function nextPaidUntil(?string $current, int $months = 1): string
    {
        $today = date('Y-m-d');
        $from  = ($current !== null && $current !== '' && $current > $today) ? $current : $today;

        return date('Y-m-d', strtotime($from . ' +' . max(1, $months) . ' month'));
    }

    /**
     * Remove files that were stored before the row they belong to could be
     * written. Best-effort by design: this runs on a failure path, and throwing
     * here would replace a clear error with a confusing one.
     *
     * @param array<string,array{name:string}> $documents
     */
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
            'manageLink' => base_url('manage'),
        ], $extra));

        (new Mailer())->send((string) $listing['email'], $subjects[$event], $body);
    }

    /** Collapse newlines so a failure message can't forge extra log lines. */
    private function oneLine(string $s): string
    {
        return trim((string) preg_replace('/\s+/', ' ', $s));
    }
}

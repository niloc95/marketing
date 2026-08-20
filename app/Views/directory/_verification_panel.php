<?php
/**
 * The owner's view of their Verified Business application.
 *
 * Rendered outside the main edit form on manage_edit.php, for the same reason
 * the gallery manager is: it posts its own multipart form, and forms cannot
 * nest.
 *
 * One panel, six states. The thing to preserve when editing: in every state the
 * owner should be able to tell what, if anything, they are waiting for and what,
 * if anything, they owe. "Under review" with no sense of who is holding the ball
 * is how support tickets start.
 *
 * @var array{verification:array<string,mixed>,documents:array<int,array<string,mixed>>}|null $verification
 * @var string $amount
 * @var bool   $payable  whether PayFast is configured — see VerificationService::canTakePayment()
 * @var bool   $pending  owner is back from PayFast, ITN not in yet — see Manage::paymentPending()
 */

use App\Models\DirectoryVerificationDocumentModel;
use App\Models\DirectoryVerificationModel;

$row       = $verification['verification'] ?? null;
$docs      = $verification['documents'] ?? [];
$state     = $row['state'] ?? null;
$payable   = $payable ?? false;
$pending   = $pending ?? false;
$cancelled = ! empty($row['cancelled_at']);

$prettyDate = static function (?string $date): string {
    if ($date === null || $date === '') {
        return '';
    }
    $ts = strtotime($date);

    return $ts === false ? '' : date('j F Y', $ts);
};
?>
<div class="panel verify-panel">
    <h2 class="verify-panel-title">
        <span class="badge badge-verified">&#10003; Verified Business</span>
    </h2>

    <?php if ($state === DirectoryVerificationModel::STATE_ACTIVE): ?>

        <?php if ($cancelled): ?>
            <p class="text-sm text-slate-600 dark:text-slate-300">
                Your badge is <strong>cancelled and will not renew</strong>. It stays on your profile
                until <strong><?= esc($prettyDate($row['paid_until'])) ?></strong> &mdash; you have
                paid for that time and you keep it.
            </p>
            <p class="hint">Changed your mind? Get in touch and we will start it up again.</p>
        <?php else: ?>
            <p class="text-sm text-slate-600 dark:text-slate-300">
                Your badge is live on your profile and in search results.
                Paid through <strong><?= esc($prettyDate($row['paid_until'])) ?></strong><?= empty($row['pf_subscription_token']) ? '.' : ', and it renews automatically.' ?>
            </p>
            <?php if (! empty($row['pf_subscription_token'])): ?>
                <?php // Only offered when there is a real recurring subscription to stop.
                      // A badge activated by hand has nothing to cancel — it simply runs out. ?>
                <form method="post" action="<?= base_url('manage/verification/cancel') ?>" class="mt-4"
                      data-confirm="Cancel your Verified Business badge? It stays up until <?= esc($prettyDate($row['paid_until']), 'attr') ?>, then comes down.">
                    <?= csrf_field() ?>
                    <button class="btn btn-ghost btn-xs text-brand-crimson">Cancel my badge</button>
                </form>
            <?php else: ?>
                <p class="hint">This badge was activated manually and does not renew on its own.</p>
            <?php endif; ?>
        <?php endif; ?>

    <?php elseif ($state === DirectoryVerificationModel::STATE_SUBMITTED): ?>

        <p class="text-sm text-slate-600 dark:text-slate-300">
            Your documents are with us. We usually review within two working days and will email you
            either way &mdash; <strong>you have not been charged anything</strong>.
        </p>
        <ul class="verify-doclist">
            <?php foreach ($docs as $doc): ?>
                <li>
                    <?= esc(DirectoryVerificationDocumentModel::KIND_LABELS[$doc['kind']] ?? $doc['kind']) ?>
                    <span class="hint"><?= esc($doc['original_name'] ?? '') ?></span>
                </li>
            <?php endforeach; ?>
        </ul>

    <?php elseif ($state === DirectoryVerificationModel::STATE_APPROVED): ?>

        <?php if ($payable && $pending): ?>
            <?php // Back from PayFast, ITN not in yet. Offering "Activate my badge"
                  // here reads as "your payment failed" to someone who just paid,
                  // and invites them to pay a second time. Say what we are waiting
                  // for instead — and claim nothing, because the money is not
                  // confirmed until the ITN says so. ?>
            <p class="text-sm text-slate-600 dark:text-slate-300">
                <strong>Thanks &mdash; we have your payment.</strong> PayFast is confirming it now.
            </p>
            <p class="hint">
                Your badge goes live on your profile as soon as that clears, usually within a few
                minutes. Refresh this page to check &mdash; there is nothing else for you to do, and
                you will not be charged twice.
            </p>

        <?php elseif ($payable): ?>
            <p class="text-sm text-slate-600 dark:text-slate-300">
                Your documents check out. Switch the badge on for
                <strong>R<?= esc($amount) ?> a month</strong> &mdash; it appears on your profile and
                everywhere your business shows up in search.
            </p>
            <p class="mt-4">
                <a class="btn btn-accent" href="<?= base_url('manage/verification/checkout') ?>">Activate my badge</a>
            </p>
        <?php else: ?>
            <?php // Card payments are not switched on for this deployment. Show the
                  // good news and say a human will follow up, rather than a button
                  // that leads nowhere — an approved application must never look
                  // like it stalled. ?>
            <p class="text-sm text-slate-600 dark:text-slate-300">
                Good news &mdash; your documents check out and your business is approved.
            </p>
            <p class="hint">
                We will email you about payment (<strong>R<?= esc($amount) ?> a month</strong>) and
                switch your badge on as soon as it clears. Nothing has been charged.
            </p>
        <?php endif; ?>

    <?php elseif ($state === DirectoryVerificationModel::STATE_LAPSED): ?>

        <p class="text-sm text-slate-600 dark:text-slate-300">
            Your badge is paused &mdash; we stopped receiving payments
            <?php if (! empty($row['paid_until'])): ?>
                after <strong><?= esc($prettyDate($row['paid_until'])) ?></strong>
            <?php endif; ?>.
            Your listing itself is unaffected.
        </p>

        <?php if ($payable && $pending): ?>
            <?php // Same reasoning as the approved branch: someone who has just
                  // paid must not be shown a button that reads as "that failed,
                  // try again". ?>
            <p class="text-sm text-slate-600 dark:text-slate-300">
                <strong>Thanks &mdash; we have your payment.</strong> PayFast is confirming it now.
            </p>
            <p class="hint">
                Your badge goes back up as soon as that clears, usually within a few minutes.
                Refresh this page to check &mdash; you will not be charged twice.
            </p>

        <?php elseif ($payable): ?>
            <?php // The badge lapsed, which almost always means a card expired or a
                  // payment bounced — not that the business stopped being real. The
                  // documents behind this row were approved, and they stay approved,
                  // so the way back is a payment and not another review. Sending
                  // someone to re-photograph their ID because their card was
                  // declined is how a recoverable lapse becomes a lost subscriber. ?>
            <p class="hint">
                We still have your approved documents, so starting again really is one step.
            </p>
            <p class="mt-4">
                <a class="btn btn-accent" href="<?= base_url('manage/verification/checkout') ?>">Reactivate my badge &mdash; R<?= esc($amount) ?> a month</a>
            </p>
        <?php else: ?>
            <p class="hint">
                We still have your approved documents. We will email you about payment
                (<strong>R<?= esc($amount) ?> a month</strong>) and put your badge back up as soon
                as it clears.
            </p>
        <?php endif; ?>

        <?php // Kept, demoted. Re-uploading is the right path for a business whose
              // registration or ownership genuinely changed, and it is the only path
              // when card payments are switched off — but it is no longer what a
              // lapsed subscriber is pushed towards. ?>
        <details class="verify-resubmit">
            <summary class="hint">Our company details have changed</summary>
            <form method="post" action="<?= base_url('manage/verification') ?>" enctype="multipart/form-data" class="verify-form">
                <?= csrf_field() ?>
                <p class="hint">Send fresh documents and we will review them again before your badge goes back up.</p>
                <?= view('directory/_verification_fields', ['amount' => $amount]) ?>
                <button type="submit" class="btn btn-primary">Submit for review</button>
            </form>
        </details>

    <?php elseif ($state === DirectoryVerificationModel::STATE_REJECTED): ?>

        <p class="text-sm text-slate-600 dark:text-slate-300">
            We could not verify your business from the documents you sent:
        </p>
        <p class="alert alert-warning verify-reason"><?= nl2br(esc($row['rejection_reason'] ?? '')) ?></p>
        <p class="hint">Nothing was charged, and your listing is unaffected. Send new documents whenever you are ready.</p>
        <form method="post" action="<?= base_url('manage/verification') ?>" enctype="multipart/form-data" class="verify-form">
            <?= csrf_field() ?>
            <?= view('directory/_verification_fields', ['amount' => $amount]) ?>
            <button type="submit" class="btn btn-primary">Send new documents</button>
        </form>

    <?php else: ?>

        <?php // The only state an owner sees on a dashboard they have never applied
              // from, so it does the selling. The upload fields stay folded away
              // behind the button: two file inputs are a wall, and the decision to
              // make first is "do I want this", not "where is my ID". ?>
        <h3 class="verify-cta-heading">Get verified &mdash; show customers your business is real</h3>
        <?= view('directory/_verification_pitch', ['amount' => $amount]) ?>
        <p class="hint mt-2">
            To apply, send your company registration document and the owner's ID. We review them,
            usually within two working days.
            <a class="verify-cta-link" href="<?= base_url('verified') ?>">What we check &rarr;</a>
        </p>
        <details class="verify-cta-details">
            <summary class="btn btn-accent">Apply for the badge</summary>
            <form method="post" action="<?= base_url('manage/verification') ?>" enctype="multipart/form-data" class="verify-form">
                <?= csrf_field() ?>
                <?= view('directory/_verification_fields', ['amount' => $amount]) ?>
                <button type="submit" class="btn btn-primary">Submit for review</button>
            </form>
        </details>

    <?php endif; ?>
</div>

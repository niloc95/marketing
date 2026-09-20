<?php
/**
 * The owner's view of their International Listing subscription.
 *
 * Deliberately NOT folded into _verification_panel.php, which is the badge's.
 * The two look similar and mean opposite things: the badge is an optional
 * decoration whose absence costs nothing, this is the listing's right to be
 * published at all. An owner reading one panel that switches between those two
 * meanings would have to work out which one they are looking at, and the state
 * they most need to understand — "my listing is down and here is why" — is
 * exactly the one that must not be ambiguous.
 *
 * Rendered only for a listing the plan applies to, so there is no "you don't
 * need this" state. Four it does have:
 *
 *   live      — paid, published, renews on a date
 *   due       — never paid, or lapsed. The listing is not public. This is the
 *               state that has to be unmissable.
 *   pending   — back from PayFast, waiting on the ITN
 *   no card   — PayFast unconfigured; we owe them an email, not a button
 *
 * @var array<string,mixed> $listing
 * @var array{verification:array<string,mixed>,documents:array<int,array<string,mixed>>}|null $subscription
 * @var string $amount
 * @var bool   $payable  PayFast is configured — VerificationService::canTakePayment()
 * @var bool   $pending  owner is back from PayFast, ITN not in yet
 */

$row     = $subscription['verification'] ?? null;
$payable = $payable ?? false;
$pending = $pending ?? false;
$active  = listing_subscription_active($listing);
$public  = ($listing['status'] ?? '') === 'published';

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
        <span class="badge gap-1"><?= lucide('globe', 'h-3.5 w-3.5 shrink-0') ?>International Listing</span>
    </h2>

    <?php if ($active): ?>
        <p class="verify-panel-lead">
            Your subscription is active<?php if (! empty($row['paid_until'])): ?>
                and renews on <strong><?= esc($prettyDate($row['paid_until'])) ?></strong><?php endif; ?>.
            <?php if ($public): ?>
                Your profile is live.
            <?php else: ?>
                <?php // Paid but not public is a real state — an admin can
                      // unpublish a listing for reasons that have nothing to do
                      // with money — and saying "you are live" here would be
                      // false. Say what is true and point at a human. ?>
                Your profile is not currently public; please contact us.
            <?php endif; ?>
        </p>

        <?php if (! empty($row['cancelled_at'])): ?>
            <div class="alert alert-info">
                You have cancelled this subscription. Your profile stays live until
                <strong><?= esc($prettyDate($row['paid_until'])) ?></strong>, then comes down.
            </div>
        <?php else: ?>
            <form method="post" action="<?= base_url('manage/verification/cancel') ?>"
                  data-confirm="Cancel your International Listing? Your profile stays live until <?= esc($prettyDate($row['paid_until'] ?? null), 'attr') ?>, then comes down.">
                <?= csrf_field() ?>
                <?php // See _verification_panel.php — the route cancels the plan
                      // it is told to, never the one it guesses. ?>
                <input type="hidden" name="plan" value="<?= esc(\App\Models\DirectoryVerificationModel::PLAN_INTERNATIONAL, 'attr') ?>">
                <button type="submit" class="btn btn-ghost btn-sm text-brand-crimson">Cancel subscription</button>
            </form>
        <?php endif; ?>

    <?php elseif ($pending): ?>
        <?php // Never say "paid" here. Only the ITN grants anything, and the
              // browser coming back from PayFast is not the ITN. ?>
        <div class="alert alert-info">
            <strong>We are confirming your payment.</strong>
            This usually takes a moment. Your profile goes live as soon as the
            payment is confirmed — you do not need to pay again, and you will
            get an email either way.
        </div>

    <?php else: ?>
        <div class="alert alert-warning">
            <strong>Your profile is not published yet.</strong>
            Listing a business in South Africa is free. Yours is outside South
            Africa, which needs an International Listing subscription at
            <strong>R<?= esc($amount) ?> per month</strong>.
            <?php // Everything they have already done still exists. Someone
                  // looking at an unpublished profile needs to know they are
                  // not being asked to start again. ?>
            Everything you have entered is saved — the profile goes live the
            moment the first payment clears, and comes back untouched if you
            ever stop and start again.
        </div>

        <?php if ($payable): ?>
            <a class="btn btn-accent" href="<?= base_url('manage/verification/checkout') ?>">
                Subscribe and publish
            </a>
            <p class="hint mt-2">
                <?php // Said here rather than discovered at the card form: PayFast
                      // settles in rand, and a card that will not accept a South
                      // African merchant fails at the last step with an error
                      // that explains none of this. ?>
                Billed in South African rand. Cancel any time.
            </p>
        <?php else: ?>
            <p class="hint">
                Card payments are not available at the moment. We will be in touch
                about paying by EFT so your profile can go live.
            </p>
        <?php endif; ?>
    <?php endif; ?>
</div>

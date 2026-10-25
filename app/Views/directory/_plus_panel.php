<?php
/**
 * The owner's view of Plus, on local.webscheduler.app.
 *
 * Plus is the International plan once .app is live (VerificationService::
 * plusMode()): optional, sold in US dollars, charged in rand by PayFast, and
 * unlocking branches, team members and the Jobs board (listing_has_premium()).
 * Unlike _hosting_panel.php, nothing here can say the profile is down: a
 * profile abroad is published free, and Plus lapsing only takes away the
 * extras.
 *
 * Four states: active, cancelled but still paid for, waiting on PayFast, and
 * on offer. Rendered only for a listing outside South Africa while Plus is on
 * sale, or while it still holds Plus.
 *
 * @var array<string,mixed>      $listing
 * @var array<string,mixed>|null $subscription VerificationService::forListing(..., PLAN_INTERNATIONAL)
 * @var string                   $amount   the rand a new subscription is charged
 * @var string|null              $usd      the dollar price
 * @var bool                     $payable  VerificationService::canTakePayment()
 * @var bool                     $pending  back from PayFast, payment not confirmed yet
 */
$row     = $subscription['verification'] ?? null;
$payable = $payable ?? false;
$pending = $pending ?? false;
$active  = listing_subscription_active($listing);

$prettyDate = static function (?string $date): string {
    $ts = $date === null || $date === '' ? false : strtotime($date);

    return $ts === false ? '' : date('j F Y', $ts);
};
$price = $usd !== null && $usd !== '' ? money($usd, 'USD') . ' a month' : money($amount) . ' a month';
?>
<?php // #get-plus is where Directory::verify() lands a new profile abroad. ?>
<div class="panel verify-panel" id="get-plus">
    <h2 class="verify-panel-title"><?= plus_badge_pill() ?></h2>

    <?php if ($active): ?>
        <p class="verify-panel-lead">
            You have Plus<?php if (! empty($row['paid_until'])): ?>, renewing on <strong><?= esc($prettyDate($row['paid_until'])) ?></strong><?php endif; ?>.
            Your branches, team and the Jobs board are switched on.
        </p>
        <?php if (! empty($row['cancelled_at'])): ?>
            <div class="alert alert-info">
                You have cancelled Plus. It stays on until
                <strong><?= esc($prettyDate($row['paid_until'] ?? null)) ?></strong>, and your profile stays published after that.
            </div>
        <?php else: ?>
            <form method="post" action="<?= base_url('manage/verification/cancel') ?>"
                  data-confirm="Cancel Plus? It stays on until <?= esc($prettyDate($row['paid_until'] ?? null), 'attr') ?>. Your profile stays published.">
                <?= csrf_field() ?>
                <input type="hidden" name="plan" value="<?= esc(\App\Models\DirectoryVerificationModel::PLAN_INTERNATIONAL, 'attr') ?>">
                <button type="submit" class="btn btn-ghost btn-xs text-brand-crimson">Cancel Plus</button>
            </form>
        <?php endif; ?>

    <?php elseif ($pending): ?>
        <div class="alert alert-info">
            <strong>We are confirming your payment.</strong>
            This usually takes a moment. Plus switches on as soon as it is confirmed,
            and you will get an email either way. You do not need to pay again.
        </div>

    <?php else: ?>
        <p class="verify-panel-lead">
            Your profile is free and stays free. <strong>Plus</strong> adds more ways to be found,
            for <strong><?= esc($price) ?></strong>:
        </p>
        <ul class="plan-rows">
            <li class="plan-row-yes"><span><strong>Locations:</strong> add your other branches, each with its own address, hours and map pin.</span></li>
            <li class="plan-row-yes"><span><strong>Team:</strong> show your people by name, with photos and what they do.</span></li>
            <li class="plan-row-yes"><span><strong>Jobs:</strong> post vacancies and reply to requests for your kind of service.</span></li>
            <li class="plan-row-yes"><span><strong>A Plus label</strong> on your profile and in discovery results.</span></li>
        </ul>
        <?php if ($payable): ?>
            <a class="btn btn-accent mt-4" href="<?= base_url('manage/verification/checkout?plan=plus') ?>">Get Plus</a>
            <p class="hint mt-2">
                Billed monthly in South African rand: WebScheduler is a South African company and your
                bank converts the charge. Cancel any time.
            </p>
        <?php else: ?>
            <p class="hint">Card payments are not available at the moment. Please contact us.</p>
        <?php endif; ?>
    <?php endif; ?>
</div>

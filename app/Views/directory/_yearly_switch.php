<?php
/**
 * "Switch to yearly" for an active monthly badge or Plus subscription, on the
 * owner dashboard (_verification_panel.php, _plus_panel.php).
 *
 * Posts to Manage::switchToYearly(), which shows a checkout for a new yearly
 * subscription. The monthly one stops when that payment lands
 * (VerificationService::recordPayment()), and the year starts after the days
 * already paid for.
 *
 * @var string $plan      DirectoryVerificationModel::PLAN_*
 * @var string $monthly   what the owner pays a month now
 * @var string $yearly    today's yearly price
 * @var string $currency  'ZAR' for the badge, 'USD' for Plus (no rand on .app)
 * @var string $paidUntil the date already paid to, formatted
 */
$saving = round((float) $monthly * 12 - (float) $yearly, 2);
?>
<div class="yearly-switch">
    <p class="text-sm text-slate-600 dark:text-slate-300">
        <strong>Pay yearly instead:</strong> <?= esc(money($yearly, $currency)) ?> a year<?php if ($saving > 0): ?>,
        which saves <?= esc(money(number_format($saving, 2, '.', ''), $currency)) ?> against twelve monthly payments<?php endif; ?>.
        The year starts after <?= esc($paidUntil) ?>, so you lose none of the time you have paid for.
    </p>
    <form method="post" action="<?= base_url('manage/verification/yearly') ?>" class="mt-2">
        <?= csrf_field() ?>
        <input type="hidden" name="plan" value="<?= esc($plan, 'attr') ?>">
        <button type="submit" class="btn btn-ghost btn-xs">Switch to yearly</button>
    </form>
</div>

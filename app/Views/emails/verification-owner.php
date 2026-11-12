<?php
/**
 * The four things we say to an owner about their Verified Business badge, in
 * one template rather than four near-identical files. They share a shell and
 * differ by a paragraph; splitting them would mean four copies of the card
 * chrome drifting apart.
 *
 * @var string $site
 * @var string $name
 * @var string $event      approved|rejected|activated|renewing|payment_failed|move
 * @var string $manageLink
 * @var string $reason     rejected only
 * @var string $amount     approved and renewing only
 * @var string $paidUntil  renewing only
 * @var string $product    activated and renewing: badge|international|plus
 *                         (VerificationService::productOf())
 * @var string $cycle      activated and renewing: monthly|yearly
 * @var string $yearlyAmount approved only: the yearly price, '' when not offered
 * @var string $gateway    renewing: PayFast or Paystack, whoever holds the subscription
 * @var string|null $cardLink payment_failed: Paystack's page for updating the card
 * @var string $paidUntil  payment_failed and move: already formatted
 */
$product   = $product ?? 'badge';
$yearly    = ($cycle ?? '') === 'yearly';
$period    = $yearly ? 'year' : 'month';
$yearlyAmount = $yearlyAmount ?? '';
$amount    = $amount ?? '';
$reason    = $reason ?? '';
$paidUntil = $paid_until ?? ($paidUntil ?? '');
$gateway   = $gateway ?? 'PayFast';
$cardLink  = $cardLink ?? null;
$what      = ['plus' => 'Plus', 'international' => 'International Profile'][$product] ?? 'Verified Business badge';
?>
<?= $this->extend('emails/_layout') ?>
<?= $this->section('content') ?>
        <h1 style="color:#003049;font-size:20px;margin:0 0 12px"><?= esc($site) ?></h1>
        <p style="font-size:15px;line-height:1.6">Hi <?= esc($name) ?>,</p>

        <?php if ($event === 'approved'): ?>
            <p style="font-size:15px;line-height:1.6">
                Good news. We've checked your company registration document and owner ID, and both are in order.
            </p>
            <p style="font-size:15px;line-height:1.6">
                Your Verified Business badge is ready to switch on. It costs
                <strong><?= esc(money($amount)) ?> a month</strong><?php if ($yearlyAmount !== ''): ?>,
                or <strong><?= esc(money($yearlyAmount)) ?> a year</strong> if you pay yearly<?php endif; ?>, and it appears on your profile and
                everywhere your business is discovered on the platform.<?php if (bookings_in_premium(Config\Sites::ZA)): ?>
                With it you can also take online bookings, and add your other branches, your team and your job vacancies.<?php endif; ?>
            </p>
            <p style="margin:22px 0">
                <?= email_button($manageLink, 'Activate my badge') ?>
            </p>
            <p style="font-size:13px;color:#64748b">
                That button takes you straight to the payment page: no password, nothing else to fill in.
                It works once, and expires in 7 days.
            </p>

        <?php elseif ($event === 'rejected'): ?>
            <p style="font-size:15px;line-height:1.6">
                We weren't able to verify your business from the documents you sent. Here's why:
            </p>
            <p style="font-size:15px;line-height:1.6;background:#fef2f2;border-left:3px solid #dc2626;padding:12px 16px;border-radius:6px">
                <?= nl2br(esc($reason)) ?>
            </p>
            <p style="font-size:15px;line-height:1.6">
                You're welcome to send new documents whenever you're ready. Nothing has been charged,
                and your business profile itself is unaffected.
            </p>
            <p style="margin:22px 0">
                <?= email_button($manageLink, 'Send new documents') ?>
            </p>

        <?php elseif ($event === 'activated' && $product === 'plus'): ?>
            <p style="font-size:15px;line-height:1.6">
                Your payment came through and <strong>Plus</strong> is now on: you can add your other
                branches and your team, <?= bookings_in_premium(Config\Sites::APP) ? 'turn on online bookings, ' : '' ?>and post on the Jobs board, from your dashboard.
            </p>
            <p style="font-size:15px;line-height:1.6">
                It renews automatically each <?= $period ?>, billed in South African rand. You can cancel any time
                from your dashboard; Plus stays on until the <?= $period ?> you've paid for runs out, and your
                profile stays published either way.
            </p>
            <p style="margin:22px 0">
                <?= email_button($manageLink, 'Go to my dashboard') ?>
            </p>

        <?php elseif ($event === 'activated' && $product === 'international'): ?>
            <p style="font-size:15px;line-height:1.6">
                Your payment came through and your <strong>International Profile</strong> is live.
            </p>
            <p style="font-size:15px;line-height:1.6">
                It renews automatically each <?= $period ?>. You can cancel any time from your dashboard; your
                profile stays live until the <?= $period ?> you've paid for runs out.
            </p>
            <p style="margin:22px 0">
                <?= email_button($manageLink, 'View my profile') ?>
            </p>

        <?php elseif ($event === 'activated'): ?>
            <p style="font-size:15px;line-height:1.6">
                Your payment came through and your <strong>Verified Business</strong> badge is now live on your profile.
            </p>
            <?php if (bookings_in_premium(Config\Sites::ZA)): ?>
                <p style="font-size:15px;line-height:1.6">
                    It comes with <strong>online bookings</strong>: <?= esc(lcfirst(bookings_pitch())) ?>
                    Turn them on from your dashboard, along with your other branches, your team and your job vacancies.
                </p>
            <?php endif; ?>
            <p style="font-size:15px;line-height:1.6">
                It renews automatically each <?= $period ?>. You can cancel any time from your dashboard. The
                badge stays up until the <?= $period ?> you've paid for runs out.
            </p>
            <p style="margin:22px 0">
                <?= email_button($manageLink, 'View my profile') ?>
            </p>

        <?php elseif ($event === 'payment_failed'): ?>
            <p style="font-size:15px;line-height:1.6">
                We tried to renew your <?= $yearly ? 'yearly ' : '' ?><?= esc($what) ?>, but the payment did not go through.
                Paystack will try your card again over the next few days.
            </p>
            <p style="font-size:15px;line-height:1.6">
                If your card has expired or changed, update it now so nothing switches off. Everything stays on until
                <strong><?= esc($paidUntil) ?></strong>.
            </p>
            <p style="margin:22px 0">
                <?= email_button($cardLink ?: $manageLink, $cardLink ? 'Update my card' : 'Open my dashboard') ?>
            </p>

        <?php elseif ($event === 'move'): ?>
            <p style="font-size:15px;line-height:1.6">
                We have moved our payments from PayFast to Paystack, which handles monthly and yearly
                subscriptions for customers everywhere. Your <?= esc($what) ?> is still paid through PayFast,
                so we need you to set it up again on Paystack. It takes a minute.
            </p>
            <p style="font-size:15px;line-height:1.6">
                You pay <?php if ($product === 'plus'): ?>for your next <?= $period ?> of Plus<?php else: ?><strong><?= esc(money($amount)) ?></strong>, the same <?= $yearly ? 'yearly' : 'monthly' ?> price as now<?php endif; ?>,
                and it starts after <strong><?= esc($paidUntil) ?></strong>, so you lose none of the time you have already paid for.
                Your PayFast payments stop as soon as this payment comes through, so you are never charged twice.
            </p>
            <p style="margin:22px 0">
                <?= email_button($manageLink, 'Move my payment') ?>
            </p>
            <p style="font-size:13px;color:#64748b">
                That button signs you in and opens the payment page. It works once, and expires in 7 days.
                Nothing changes until you pay.
            </p>

        <?php elseif ($product === 'plus'): /* renewing */ ?>
            <p style="font-size:15px;line-height:1.6">
                Just so there are no surprises: your <?= $yearly ? 'yearly ' : '' ?>Plus subscription renews on
                <strong><?= esc($paidUntil) ?></strong>, and <?= esc($gateway) ?> will charge it in South African
                rand as usual.
            </p>
            <p style="font-size:15px;line-height:1.6">
                Nothing to do if that's all fine. If you'd rather stop, cancel from your dashboard before
                that date: Plus switches off when the <?= $period ?> ends and your profile stays published.
            </p>

        <?php else: /* renewing */ ?>
            <p style="font-size:15px;line-height:1.6">
                Just so there are no surprises: your <?= $yearly ? 'yearly ' : '' ?>Verified Business badge renews on
                <strong><?= esc($paidUntil) ?></strong>, and <?= esc($gateway) ?> will charge
                <strong><?= esc(money($amount)) ?></strong> as usual.
            </p>
            <p style="font-size:15px;line-height:1.6">
                Nothing to do if that's all fine. If you'd rather stop, cancel from your dashboard
                before that date and the badge will simply come down when the <?= $period ?> ends.
            </p>
        <?php endif; ?>

        <p style="font-size:13px;color:#64748b">
            You're getting this because your business has a profile on <?= esc($site) ?>.
        </p>
<?= $this->endSection() ?>

<?php
/**
 * To every admin of a business about its Bookings plan
 * (BookingPlanService): the trial ends soon, or a renewal did not go through.
 *
 * @var array<string,mixed> $listing
 * @var string              $kind  trial | failed
 * @var string|null         $ends  trial end, UTC (trial)
 * @var string|null         $link  Paystack's page for a new card (failed)
 * @var string              $price monthly price in rand, '' when not set
 * @var string              $url   the Plan tab
 */
helper('money');
$ends = isset($ends) ? (new DateTimeImmutable($ends, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('Africa/Johannesburg'))->format('j F Y') : '';
?>
<?= $this->extend('emails/_layout') ?>
<?= $this->section('content') ?>
<?php if ($kind === 'trial'): ?>
        <h1 style="color:#003049;font-size:20px;margin:0 0 12px">Your free trial ends on <?= esc($ends) ?></h1>
        <p style="font-size:15px;line-height:1.6">
            Online bookings for <?= esc($listing['display_name']) ?> are free until <?= esc($ends) ?>.
            To keep taking bookings after that, choose the plan<?= $price !== '' ? ' for ' . esc(money((float) $price, 'ZAR')) . ' a month' : '' ?>.
            If you do not, your calendar and customers stay in your business area, and customers can no longer book online until you do.
        </p>
        <p style="margin:18px 0"><a href="<?= esc($url) ?>" style="background:#F77F00;color:#fff;text-decoration:none;font-weight:700;padding:12px 22px;border-radius:11px;display:inline-block">Choose the plan</a></p>
<?php else: ?>
        <h1 style="color:#003049;font-size:20px;margin:0 0 12px">Your payment did not go through</h1>
        <p style="font-size:15px;line-height:1.6">
            The monthly payment for online bookings for <?= esc($listing['display_name']) ?> was declined. Paystack tries again over the next few days.
            Please check your card<?= $link ? ', or add a new one' : '' ?>, so customers can keep booking online.
        </p>
        <p style="margin:18px 0"><a href="<?= esc($link ?: $url) ?>" style="background:#F77F00;color:#fff;text-decoration:none;font-weight:700;padding:12px 22px;border-radius:11px;display:inline-block"><?= $link ? 'Update your card' : 'See your plan' ?></a></p>
<?php endif ?>
        <p style="font-size:13px;color:#64748b">Every admin of the business gets this email.</p>
<?= $this->endSection() ?>

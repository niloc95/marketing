<?php
/**
 * To every admin of a business when its payment account for deposits
 * changes (PaymentSettings::tellAdmins()). Names the gateway and whether it
 * is on, never a key.
 *
 * @var array<string,mixed> $listing
 * @var array<string,mixed> $account BookingIntegrations::view('payment')
 * @var string              $by      who changed it
 * @var string              $url     the Payments tab
 */
use App\Services\Bookings\Notifications\BookingIntegrations;

$gateway = BookingIntegrations::PROVIDER_LABELS[$account['provider']] ?? '';
?>
<?= $this->extend('emails/_layout') ?>
<?= $this->section('content') ?>
        <h1 style="color:#003049;font-size:20px;margin:0 0 12px">Payment account changed</h1>
        <p style="font-size:15px;line-height:1.6">
            <?= esc($by !== '' ? $by : 'Someone on your team') ?> changed where deposits for <?= esc($listing['display_name']) ?> are paid.
            <?php if ($gateway === ''): ?>
                The account was removed, so customers no longer pay a deposit when they book.
            <?php else: ?>
                Deposits now go to the <?= esc($gateway) ?> account saved in your settings<?= $account['active'] ? '' : ', which is switched off for now' ?>.
            <?php endif ?>
        </p>
        <p style="margin:18px 0"><a href="<?= esc($url) ?>" style="background:#F77F00;color:#fff;text-decoration:none;font-weight:700;padding:12px 22px;border-radius:11px;display:inline-block">See the payment settings</a></p>
        <p style="font-size:13px;color:#64748b">If you did not expect this, check the account at once and change your password. Every admin of the business gets this email.</p>
<?= $this->endSection() ?>

<?php
/**
 * Invitation to join a business on WebScheduler Local (TeamService::invite()).
 *
 * @var string $name
 * @var string $business
 * @var string $inviter
 * @var string $role
 * @var string $url
 * @var int    $days
 * @var bool   $newEmail an admin changed their email: confirm it to keep their place (T36)
 */
$newEmail ??= false;
?>
<?= $this->extend('emails/_layout') ?>
<?= $this->section('content') ?>
<?php if ($newEmail): ?>
        <h1 style="color:#003049;font-size:20px;margin:0 0 12px">Confirm your new email for <?= esc($business) ?></h1>
        <p style="font-size:15px;line-height:1.6">Hi <?= esc($name) ?>, <?= $inviter !== '' ? esc($inviter) : 'an admin' ?> changed the email you use for <?= esc($business) ?> on WebScheduler Local to this one. Open the link to sign in with it from now on. Your role, your bookings and your notices stay as they are.</p>
        <p style="margin:22px 0">
            <a href="<?= esc($url) ?>" style="background:#F77F00;color:#fff;text-decoration:none;font-weight:700;padding:12px 22px;border-radius:11px;display:inline-block">Use this email</a>
        </p>
        <p style="font-size:13px;color:#64748b">The link works for <?= (int) $days ?> days. Until you open it, your old email still signs you in. If you were not expecting this, ignore this email.</p>
<?php else: ?>
        <h1 style="color:#003049;font-size:20px;margin:0 0 12px">Join <?= esc($business) ?></h1>
        <p style="font-size:15px;line-height:1.6">Hi <?= esc($name) ?>, <?= $inviter !== '' ? esc($inviter) . ' invited' : 'you are invited' ?> <?= $inviter !== '' ? 'you ' : '' ?>to join <?= esc($business) ?> on WebScheduler Local as <?= esc($role) ?>, to see and manage bookings.</p>
        <p style="margin:22px 0">
            <a href="<?= esc($url) ?>" style="background:#F77F00;color:#fff;text-decoration:none;font-weight:700;padding:12px 22px;border-radius:11px;display:inline-block">Join <?= esc($business) ?></a>
        </p>
        <p style="font-size:13px;color:#64748b">The link works for <?= (int) $days ?> days. If you were not expecting this, ignore this email: nothing happens unless you join.</p>
<?php endif ?>
<?= $this->endSection() ?>

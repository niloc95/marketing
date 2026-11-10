<?php
/**
 * Password reset for /business (AccountService::sendPasswordReset()).
 *
 * @var string $name
 * @var string $url
 * @var int    $minutes
 */
?>
<?= $this->extend('emails/_layout') ?>
<?= $this->section('content') ?>
        <h1 style="color:#003049;font-size:20px;margin:0 0 12px">Reset your password</h1>
        <p style="font-size:15px;line-height:1.6"><?= $name !== '' ? 'Hi ' . esc($name) . ', s' : 'S' ?>omeone, hopefully you, asked to reset the password of your WebScheduler Local business account.</p>
        <p style="margin:22px 0">
            <a href="<?= esc($url) ?>" style="background:#F77F00;color:#fff;text-decoration:none;font-weight:700;padding:12px 22px;border-radius:11px;display:inline-block">Choose a new password</a>
        </p>
        <p style="font-size:13px;color:#64748b">The link works for <?= (int) $minutes ?> minutes. If you did not ask for it, ignore this email: your password stays as it is.</p>
<?= $this->endSection() ?>

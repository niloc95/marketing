<?php
/**
 * One use sign in link for /bookings (AccountService::sendSignInLink()).
 *
 * @var string $name
 * @var string $url
 * @var int    $minutes
 */
?>
<?= $this->extend('emails/_layout') ?>
<?= $this->section('content') ?>
        <h1 style="color:#003049;font-size:20px;margin:0 0 12px">Sign in to your Dashboard</h1>
        <p style="font-size:15px;line-height:1.6"><?= $name !== '' ? 'Hi ' . esc($name) . ', h' : 'H' ?>ere is the link you asked for.</p>
        <p style="margin:22px 0">
            <a href="<?= esc($url) ?>" style="background:#F77F00;color:#fff;text-decoration:none;font-weight:700;padding:12px 22px;border-radius:11px;display:inline-block">Sign in</a>
        </p>
        <p style="font-size:13px;color:#64748b">The link works once, for <?= (int) $minutes ?> minutes. If you did not ask for it, ignore this email: nobody can sign in without the link.</p>
<?= $this->endSection() ?>

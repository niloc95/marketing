<?php
/**
 * "Please confirm your subscription": the one email a newsletter signup gets
 * before it is confirmed (NewsletterService::subscribe()). The button opens a
 * page with a Confirm button; the link alone subscribes nobody.
 *
 * @var string $brand      "WebScheduler Local", or "WebScheduler" for the brand site's form
 * @var string $confirmUrl
 * @var int    $days       how long the link works
 */
?>
<?= $this->extend('emails/_layout') ?>
<?= $this->section('content') ?>
        <h1 style="color:#003049;font-size:20px;margin:0 0 12px"><?= esc($brand) ?></h1>
        <p style="font-size:15px;line-height:1.6">
            Someone, hopefully you, asked to receive <?= esc($brand) ?> updates at this address.
            Please confirm it was you.
        </p>

        <p style="margin:22px 0">
            <a href="<?= esc($confirmUrl) ?>" style="background:#F77F00;color:#fff;text-decoration:none;font-weight:700;padding:12px 22px;border-radius:11px;display:inline-block">Confirm my subscription</a>
        </p>

        <p style="font-size:13px;color:#64748b">
            The button opens a page where you confirm with one more click. The link works for <?= (int) $days ?> days.
            If you did not ask for this, ignore this email: you will not be added and we will not write again.
        </p>
<?= $this->endSection() ?>

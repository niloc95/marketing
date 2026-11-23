<?php
/**
 * The welcome pack email (WelcomePackService::mail()), sent once when
 * Verified or Plus starts, and again when admin presses Send. Rendered with
 * saveData false. Neutral on both sites: nothing South African, no prices.
 *
 * @var string $name     the profile's name
 * @var string $contact  who we greet
 * @var string $noun     practice, pharmacy, business…
 * @var string $audience patients, clients, customers
 * @var string $link     the PDF (welcome-pack/{token})
 * @var string $owner    the same PDF from the owner area
 * @var string $plan     Verified or Plus
 */
?>
<?= $this->extend('emails/_layout') ?>
<?= $this->section('content') ?>
        <p style="font-size:12px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:#F77F00;margin:0 0 6px">Your welcome pack</p>
        <h1 style="color:#003049;font-size:20px;margin:0 0 12px"><?= esc($name) ?></h1>
        <p style="font-size:15px;line-height:1.6">Hi <?= esc($contact) ?>,</p>
        <p style="font-size:15px;line-height:1.6">Welcome to <?= esc($plan) ?>. We made you a welcome pack: a printable copy of your <?= esc($noun) ?> profile and a QR poster for your reception or front door. Your <?= esc($audience) ?> scan it to discover your hours, services and contact details on WebScheduler Local.</p>
        <p style="margin:22px 0">
            <?= email_button($link, 'Download my welcome pack (PDF)') ?>
        </p>
        <p style="font-size:15px;line-height:1.6;margin:0 0 6px"><strong>Inside the pack</strong></p>
        <ul style="font-size:15px;line-height:1.6;margin:0 0 16px;padding-left:20px">
            <li>Your profile, ready to print, with a QR code to the live page</li>
            <li>An A4 QR poster and four counter cards to cut out</li>
            <li>A share kit: your link and messages for WhatsApp, email and social media</li>
        </ul>
        <p style="font-size:13px;color:#64748b">The pack is built from your live profile each time you open it, so it always shows your latest details. You can also download it from your owner area: <a href="<?= esc($owner) ?>" style="color:#003049;word-break:break-all"><?= esc($owner) ?></a></p>
        <p style="font-size:13px;color:#64748b">Or paste this link into your browser:<br><a href="<?= esc($link) ?>" style="color:#003049;word-break:break-all"><?= esc($link) ?></a></p>
<?= $this->endSection() ?>

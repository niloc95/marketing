<?php
/**
 * "Booked here before?" (ReturningPatientService::send(), T37): a 6 digit
 * code for the page the patient is on, and a link back to the time they
 * chose. Sent only to an address this business already has.
 *
 * @var string $business
 * @var string $firstName
 * @var string $code
 * @var string $link
 * @var int    $minutes
 */
?>
<?= $this->extend('emails/_layout') ?>
<?= $this->section('content') ?>
        <h1 style="color:#003049;font-size:20px;margin:0 0 12px">Your code to book with <?= esc($business) ?></h1>
        <p style="font-size:15px;line-height:1.6"><?= $firstName !== '' ? 'Hi ' . esc($firstName) . ', enter' : 'Enter' ?> this code on the booking page and we fill in your details for you.</p>
        <p style="font-size:32px;font-weight:700;letter-spacing:8px;color:#003049;margin:20px 0;font-family:Consolas,Menlo,monospace"><?= esc($code) ?></p>
        <p style="font-size:15px;line-height:1.6">Or continue from this email, with the time you chose:</p>
        <p style="margin:18px 0">
            <a href="<?= esc($link) ?>" style="background:#F77F00;color:#fff;text-decoration:none;font-weight:700;padding:12px 22px;border-radius:11px;display:inline-block">Continue my booking</a>
        </p>
        <p style="font-size:13px;color:#64748b">The code and the link work once, for <?= (int) $minutes ?> minutes. Never share them: anyone with them can book as you. If you did not ask for this email, you can ignore it.</p>
<?= $this->endSection() ?>

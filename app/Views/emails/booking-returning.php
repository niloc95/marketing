<?php
/**
 * "Booked here before?" (ReturningPatientService::send(), T37): a 6 digit
 * code for the page the patient is on, and a link back to the time they
 * chose. Sent only to an address this business already has. While codes
 * are paused after several wrong tries ($code is ''), the link only.
 *
 * @var string $business
 * @var string $firstName
 * @var string $code
 * @var string $link
 * @var int    $codeMinutes
 * @var int    $linkMinutes
 */
?>
<?= $this->extend('emails/_layout') ?>
<?= $this->section('content') ?>
<?php if ($code !== ''): ?>
        <h1 style="color:#003049;font-size:20px;margin:0 0 12px">Your code to book with <?= esc($business) ?></h1>
        <p style="font-size:15px;line-height:1.6"><?= $firstName !== '' ? 'Hi ' . esc($firstName) . ', enter' : 'Enter' ?> this code on the booking page and we fill in your details for you.</p>
        <p style="font-size:32px;font-weight:700;letter-spacing:8px;color:#003049;margin:20px 0;font-family:Consolas,Menlo,monospace"><?= esc($code) ?></p>
        <p style="font-size:15px;line-height:1.6">Or continue from this email, with the time you chose:</p>
<?php else: ?>
        <h1 style="color:#003049;font-size:20px;margin:0 0 12px">Your link to book with <?= esc($business) ?></h1>
        <p style="font-size:15px;line-height:1.6"><?= $firstName !== '' ? 'Hi ' . esc($firstName) . ', for' : 'For' ?> your safety, codes are paused for a while after several wrong tries. Use this button instead: it brings you back to the time you chose and fills in your details.</p>
<?php endif ?>
        <p style="margin:18px 0">
            <a href="<?= esc($link) ?>" style="background:#F77F00;color:#fff;text-decoration:none;font-weight:700;padding:12px 22px;border-radius:11px;display:inline-block">Continue my booking</a>
        </p>
        <p style="font-size:13px;color:#64748b"><?= $code !== '' ? 'The code works for ' . (int) $codeMinutes . ' minutes and the link for ' . (int) $linkMinutes . ', once each.' : 'The link works once, for ' . (int) $linkMinutes . ' minutes.' ?> Never share them: anyone with them can book as you. If you did not ask for this email, you can ignore it.</p>
<?= $this->endSection() ?>

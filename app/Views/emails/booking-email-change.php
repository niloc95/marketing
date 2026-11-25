<?php
/**
 * A person's own sign in email change (AccountService::requestEmailChange()
 * and confirmEmailChange()): the confirm link to the new address, then a
 * note to the old one.
 *
 * @var string      $name
 * @var string      $url
 * @var bool        $confirm  true: the link to the new address; false: the note to the old one
 * @var string|null $newEmail
 */
?>
<?= $this->extend('emails/_layout') ?>
<?= $this->section('content') ?>
<?php if ($confirm): ?>
        <h1 style="color:#003049;font-size:20px;margin:0 0 12px">Confirm your new email</h1>
        <p style="font-size:15px;line-height:1.6"><?= $name !== '' ? 'Hi ' . esc($name) . ', y' : 'Y' ?>ou asked to sign in to WebScheduler Local with this email from now on.</p>
        <p style="margin:22px 0">
            <a href="<?= esc($url) ?>" style="background:#F77F00;color:#fff;text-decoration:none;font-weight:700;padding:12px 22px;border-radius:11px;display:inline-block">Use this email</a>
        </p>
        <p style="font-size:13px;color:#64748b">The link works for 24 hours. Until you open it, your old email still signs you in. If you did not ask for this, ignore this email.</p>
<?php else: ?>
        <h1 style="color:#003049;font-size:20px;margin:0 0 12px">Your sign in email was changed</h1>
        <p style="font-size:15px;line-height:1.6"><?= $name !== '' ? 'Hi ' . esc($name) . ', y' : 'Y' ?>our WebScheduler Local account now signs in with <?= esc((string) ($newEmail ?? '')) ?>. This address no longer does.</p>
        <p style="font-size:15px;line-height:1.6">If this was not you, reply to this email at once so we can lock the account.</p>
<?php endif ?>
<?= $this->endSection() ?>

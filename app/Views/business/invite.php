<?php
/**
 * The page an invitation link opens. Only the form joins the business.
 *
 * @var array<string,mixed>|null $invite
 * @var string                   $token
 * @var string                   $roleLabel
 * @var int                      $minLength
 */
$new = $invite !== null && empty($invite['password_hash']);
?>
<?= $this->extend('layouts/business') ?>
<?= $this->section('content') ?>
<div class="mx-auto max-w-md">
    <div class="form-card">
        <?php if ($invite === null): ?>
            <h1 class="mb-2 text-xl font-bold text-slate-900 dark:text-white">This invitation has expired</h1>
            <p class="text-sm text-slate-600 dark:text-slate-300">Invitations work for 7 days and only once. Ask the business to send a new one.</p>
        <?php else: ?>
            <h1 class="mb-1.5 text-xl font-bold text-slate-900 dark:text-white">Join <?= esc((string) $invite['display_name']) ?></h1>
            <p class="mb-5 text-sm text-slate-500 dark:text-slate-400">As <?= esc($roleLabel) ?>, signed in with <?= esc((string) $invite['account_email']) ?>.</p>
            <form method="post" action="<?= base_url('business/invite/' . $token) ?>">
                <?= csrf_field() ?>
                <div class="field">
                    <label for="inv-pw"><?= $new ? 'Choose a password' : 'Your password' ?></label>
                    <input id="inv-pw" type="password" name="password" autocomplete="<?= $new ? 'new-password' : 'current-password' ?>"<?= $new ? ' minlength="' . $minLength . '"' : '' ?> required autofocus>
                    <?php if ($new): ?><p class="mt-1 text-xs text-slate-500">At least <?= $minLength ?> characters.</p><?php endif ?>
                </div>
                <button type="submit" class="btn btn-primary btn-block">Join</button>
            </form>
        <?php endif ?>
    </div>
</div>
<?= $this->endSection() ?>

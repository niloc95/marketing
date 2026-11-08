<?php
/**
 * @var bool   $valid
 * @var string $token
 */
?>
<?= $this->extend('layouts/business') ?>
<?= $this->section('content') ?>
<div class="mx-auto max-w-md">
    <div class="form-card">
        <?php if ($valid): ?>
            <h1 class="mb-1.5 text-xl font-bold text-slate-900 dark:text-white">Choose a new password</h1>
            <p class="mb-5 text-sm text-slate-500 dark:text-slate-400">At least <?= (int) config('Bookings')->passwordMinLength ?> characters.</p>
            <form method="post" action="<?= base_url('bookings/password/' . $token) ?>">
                <?= csrf_field() ?>
                <div class="field">
                    <label for="biz-new-password">New password</label>
                    <input id="biz-new-password" type="password" name="password" autocomplete="new-password" minlength="<?= (int) config('Bookings')->passwordMinLength ?>" required autofocus>
                </div>
                <div class="field">
                    <label for="biz-new-password-2">The same password again</label>
                    <input id="biz-new-password-2" type="password" name="password_confirm" autocomplete="new-password" required>
                </div>
                <button type="submit" class="btn btn-primary btn-block">Save and sign in</button>
            </form>
        <?php else: ?>
            <h1 class="mb-2 text-xl font-bold text-slate-900 dark:text-white">This link has expired</h1>
            <p class="mb-4 text-sm text-slate-600 dark:text-slate-300">Reset links work for an hour, and only until the password is changed.</p>
            <a class="btn btn-primary btn-block" href="<?= base_url('bookings/password') ?>">Get a new link</a>
        <?php endif ?>
    </div>
</div>
<?= $this->endSection() ?>

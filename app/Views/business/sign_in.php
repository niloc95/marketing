<?php
/** @var string|null $notice */
$old = flash('old') ?: [];
?>
<?= $this->extend('layouts/business') ?>
<?= $this->section('content') ?>
<div class="mx-auto max-w-md">
    <div class="form-card">
        <h1 class="mb-1.5 text-xl font-bold text-slate-900 dark:text-white">Sign in to your business</h1>
        <p class="mb-5 text-sm text-slate-500 dark:text-slate-400">Your business profile, and your bookings if you take them online.</p>
        <?php if (! empty($notice)): ?>
            <div class="alert alert-info mb-5" role="status"><?= esc($notice) ?></div>
        <?php endif ?>
        <form method="post" action="<?= base_url('business/sign-in') ?>">
            <?= csrf_field() ?>
            <div class="field">
                <label for="biz-email">Email</label>
                <input id="biz-email" type="email" name="email" value="<?= esc((string) ($old['email'] ?? '')) ?>" autocomplete="username" required autofocus>
            </div>
            <div class="field">
                <label for="biz-password">Password</label>
                <input id="biz-password" type="password" name="password" autocomplete="current-password" required>
            </div>
            <button type="submit" class="btn btn-primary btn-block">Sign in</button>
        </form>
        <p class="mt-4 text-sm"><a class="underline" href="<?= base_url('business/password') ?>">Forgot your password?</a></p>
    </div>

    <div class="form-card mt-5">
        <h2 class="mb-1.5 font-bold text-slate-900 dark:text-white">Or get a sign in link</h2>
        <p class="mb-4 text-sm text-slate-500 dark:text-slate-400">Use the email on your business profile. We email you a link that signs you in once. No password needed.</p>
        <form method="post" action="<?= base_url('business/sign-in/link') ?>">
            <?= csrf_field() ?>
            <div class="field">
                <label for="biz-link-email">Email</label>
                <input id="biz-link-email" type="email" name="email" autocomplete="email" required>
            </div>
            <button type="submit" class="btn btn-ghost btn-block">Email me a link</button>
        </form>
    </div>

    <p class="mt-5 text-center text-sm text-slate-500 dark:text-slate-400">
        Not on WebScheduler Local yet? <a class="underline" href="<?= base_url('add-profile') ?>">Add your business</a>, free.
    </p>
</div>
<?= $this->endSection() ?>

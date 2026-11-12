<?php
/**
 * @var array<string,mixed> $account
 * @var bool $hasPassword
 * @var bool $welcome
 * @var int  $minLength
 */
?>
<?= $this->extend('layouts/business') ?>
<?= $this->section('content') ?>
<div class="mx-auto max-w-lg space-y-5">

    <?php if ($welcome && ! $hasPassword): ?>
        <div class="alert alert-success" role="status">Online bookings are on. Choose a password so you can sign in to your business from any device.</div>
    <?php endif ?>

    <div class="form-card">
        <p class="mb-4 text-sm text-slate-500 dark:text-slate-400">Signed in as <?= esc((string) $account['email']) ?></p>
        <form method="post" action="<?= base_url('business/account/name') ?>">
            <?= csrf_field() ?>
            <div class="field">
                <label for="biz-name">Your name</label>
                <input id="biz-name" type="text" name="name" value="<?= esc((string) $account['name']) ?>" maxlength="120" autocomplete="name" required>
            </div>
            <button type="submit" class="btn btn-ghost">Save name</button>
        </form>
    </div>

    <div class="form-card" id="password">
        <h2 class="mb-1.5 section-title"><?= $hasPassword ? 'Change your password' : 'Choose a password' ?></h2>
        <p class="mb-4 text-sm text-slate-500 dark:text-slate-400">At least <?= $minLength ?> characters.</p>
        <form method="post" action="<?= base_url('business/account/password') ?>">
            <?= csrf_field() ?>
            <?php if ($hasPassword): ?>
                <div class="field">
                    <label for="biz-current">Current password</label>
                    <input id="biz-current" type="password" name="current_password" autocomplete="current-password" required>
                </div>
            <?php endif ?>
            <div class="field">
                <label for="biz-pw">New password</label>
                <input id="biz-pw" type="password" name="password" autocomplete="new-password" minlength="<?= $minLength ?>" required>
            </div>
            <div class="field">
                <label for="biz-pw2">The same password again</label>
                <input id="biz-pw2" type="password" name="password_confirm" autocomplete="new-password" required>
            </div>
            <button type="submit" class="btn btn-primary">Save password</button>
        </form>
    </div>

    <p class="text-sm"><a class="underline" href="<?= base_url('business/choose') ?>">Your businesses</a></p>
</div>
<?= $this->endSection() ?>

<?= $this->section('shell_title') ?>Your account<?= $this->endSection() ?>

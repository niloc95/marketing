<?php
/**
 * The page an emailed sign in link opens. Only the button signs in, so a mail
 * scanner opening the link does not use it up.
 *
 * @var bool   $valid
 * @var string $token
 */
?>
<?= $this->extend('layouts/business') ?>
<?= $this->section('content') ?>
<div class="mx-auto max-w-md">
    <div class="form-card">
        <?php if ($valid): ?>
            <h1 class="mb-4 text-xl font-bold text-slate-900 dark:text-white">Sign in to your Dashboard</h1>
            <form method="post" action="<?= base_url('bookings/sign-in/' . $token) ?>">
                <?= csrf_field() ?>
                <button type="submit" class="btn btn-primary btn-block">Sign in</button>
            </form>
        <?php else: ?>
            <h1 class="mb-2 text-xl font-bold text-slate-900 dark:text-white">This link has expired</h1>
            <p class="mb-4 text-sm text-slate-600 dark:text-slate-300">Sign in links work once, for 15 minutes.</p>
            <a class="btn btn-primary btn-block" href="<?= base_url('bookings/sign-in') ?>">Get a new link</a>
        <?php endif ?>
    </div>
</div>
<?= $this->endSection() ?>

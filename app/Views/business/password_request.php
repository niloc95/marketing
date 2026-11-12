<?= $this->extend('layouts/business') ?>
<?= $this->section('content') ?>
<div class="mx-auto max-w-md">
    <div class="form-card">
        <h1 class="mb-1.5 page-title">Reset your password</h1>
        <p class="mb-5 text-sm text-slate-500 dark:text-slate-400">We email you a link to choose a new one. It works for an hour.</p>
        <form method="post" action="<?= base_url('business/password') ?>">
            <?= csrf_field() ?>
            <div class="field">
                <label for="biz-reset-email">Email</label>
                <input id="biz-reset-email" type="email" name="email" autocomplete="email" required autofocus>
            </div>
            <button type="submit" class="btn btn-primary btn-block">Email me a reset link</button>
        </form>
        <p class="mt-4 text-sm"><a class="underline" href="<?= base_url('business/sign-in') ?>">Back to sign in</a></p>
    </div>
</div>
<?= $this->endSection() ?>

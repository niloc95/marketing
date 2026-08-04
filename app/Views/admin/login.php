<?= $this->extend('layouts/public') ?>

<?= $this->section('head') ?>
<?= seo_meta(['title' => 'Admin sign in — WebScheduler Directory']) ?>
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<section class="section">
    <div class="container">
        <div class="form-card max-w-md">
            <h1 class="mb-1.5 text-xl font-bold text-slate-900 dark:text-white">Admin sign in</h1>
            <p class="mb-5 text-sm text-slate-500 dark:text-slate-400">Directory moderation.</p>
            <form method="post" action="<?= base_url('admin/login') ?>">
                <?= csrf_field() ?>
                <div class="field">
                    <label>Password</label>
                    <input type="password" name="password" autofocus required>
                </div>
                <button type="submit" class="btn btn-primary btn-block">Sign in</button>
            </form>
        </div>
    </div>
</section>
<?= $this->endSection() ?>

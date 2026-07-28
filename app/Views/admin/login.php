<?= $this->extend('layouts/public') ?>

<?= $this->section('head') ?>
<?= seo_meta(['title' => 'Admin sign in — WebScheduler Directory']) ?>
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<section class="section">
    <div class="container">
        <div class="form-card" style="max-width:420px">
            <h1 style="margin:0 0 6px">Admin sign in</h1>
            <p style="color:var(--muted);margin:0 0 20px">Directory moderation.</p>
            <form method="post" action="<?= base_url('admin/login') ?>">
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

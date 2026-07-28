<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?= $this->renderSection('head') ?: seo_meta(['title' => 'WebScheduler Directory — Find a healthcare provider']) ?>
    <link rel="icon" type="image/svg+xml" href="<?= base_url('assets/favicon.svg') ?>">
    <link rel="stylesheet" href="<?= base_url('assets/directory.css') ?>">
</head>
<body>
    <header class="site-header">
        <div class="container">
            <a class="brand" href="<?= base_url('/') ?>">
                <span class="brand-mark">W</span>
                <span>WebScheduler <span style="color:var(--orange)">Directory</span></span>
            </a>
            <nav class="nav">
                <a href="<?= base_url('directory') ?>">Find a provider</a>
                <a href="<?= base_url('list-your-practice') ?>" class="btn btn-accent" style="color:#fff">List your practice</a>
            </nav>
        </div>
    </header>

    <?php if (session()->getFlashdata('success')): ?>
        <div class="container" style="margin-top:18px"><div class="alert alert-success"><?= esc(session()->getFlashdata('success')) ?></div></div>
    <?php endif; ?>
    <?php if (session()->getFlashdata('error')): ?>
        <div class="container" style="margin-top:18px"><div class="alert alert-error"><?= esc(session()->getFlashdata('error')) ?></div></div>
    <?php endif; ?>
    <?php if (session()->getFlashdata('info')): ?>
        <div class="container" style="margin-top:18px"><div class="alert alert-info"><?= esc(session()->getFlashdata('info')) ?></div></div>
    <?php endif; ?>

    <?= $this->renderSection('content') ?>

    <footer class="site-footer">
        <div class="container">
            <span>&copy; <?= date('Y') ?> WebScheduler Directory</span>
            <span><a href="<?= base_url('directory') ?>">Browse</a> &middot; <a href="<?= base_url('list-your-practice') ?>">List your practice</a></span>
        </div>
    </footer>
</body>
</html>

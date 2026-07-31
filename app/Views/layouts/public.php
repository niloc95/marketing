<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?= $this->renderSection('head') ?: seo_meta(['title' => config('Directory')->siteName() . ' — Find a local business or service']) ?>
    <link rel="icon" type="image/svg+xml" href="<?= base_url('assets/favicon.svg') ?>">
    <link rel="stylesheet" href="<?= base_url('assets/directory.css') ?>">
</head>
<body>
    <header class="site-header">
        <div class="container">
            <a class="brand" href="<?= base_url('/') ?>">
                <span class="brand-mark">W</span>
                <span>WebScheduler <span class="text-brand-orange">Directory</span></span>
            </a>
            <nav class="nav">
                <a href="<?= base_url('directory') ?>">Find a business</a>
                <a href="<?= base_url('list-your-practice') ?>" class="btn btn-accent">List your business — free</a>
            </nav>
        </div>
    </header>

    <?php foreach (['success' => 'alert-success', 'error' => 'alert-error', 'info' => 'alert-info'] as $key => $cls): ?>
        <?php if (session()->getFlashdata($key)): ?>
            <div class="container mt-5"><div class="alert <?= $cls ?>"><?= esc(session()->getFlashdata($key)) ?></div></div>
        <?php endif; ?>
    <?php endforeach; ?>

    <?= $this->renderSection('content') ?>

    <footer class="site-footer">
        <div class="container">
            <span>&copy; <?= date('Y') ?> <?= esc(config('Directory')->siteName()) ?></span>
            <span>
                <a href="<?= base_url('directory') ?>">Browse</a> &middot;
                <a href="<?= base_url('directory/categories') ?>">All categories</a> &middot;
                <a href="<?= base_url('list-your-practice') ?>">List your business</a> &middot;
                <a href="<?= base_url('manage') ?>">Manage your listing</a>
            </span>
        </div>
    </footer>

    <script defer src="<?= base_url('assets/directory.js') ?>"></script>
</body>
</html>

<?= $this->extend('layouts/admin') ?>

<?= $this->section('head') ?>
<?= seo_meta(['title' => 'New campaign | Admin']) ?>
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<?php
/**
 * Step one of a new campaign: pick the template (Config\Campaigns::$kinds).
 *
 * @var Config\Campaigns $config
 */
?>
<div class="shell-page">
    <nav class="crumbs" aria-label="Breadcrumb">
        <a href="<?= base_url('admin/communications') ?>">Communications</a>
        <span class="crumbs-sep">/</span>
        <span>New campaign</span>
    </nav>

    <div class="grid gap-4 md:grid-cols-3">
        <?php foreach ($config->kinds as $key => $kind): ?>
            <a class="panel block hover:border-primary-300" href="<?= base_url('admin/communications/new?kind=' . rawurlencode($key)) ?>">
                <h2 class="mb-1 text-base font-bold text-slate-900 dark:text-white"><?= esc($kind['label']) ?></h2>
                <p class="mb-2 text-sm text-slate-600 dark:text-slate-300"><?= esc($kind['summary']) ?></p>
                <p class="text-xs text-slate-500 dark:text-slate-400">
                    Goes to: <?= esc(implode(', ', array_map(static fn (string $s): string => $config->segments[$s], $kind['segments']))) ?>
                </p>
            </a>
        <?php endforeach; ?>
    </div>
</div>
<?= $this->endSection() ?>

<?= $this->extend('layouts/public') ?>

<?= $this->section('head') ?>
<?= seo_meta(['title' => 'Documents | ' . config('Directory')->siteName()]) ?>
<meta name="robots" content="noindex, nofollow">
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<?php
/**
 * @var list<array{key:string,title:string,description:string,file:string,bytes:int|null,modified:int|null}> $docs
 */
?>
<?= view('admin/_bar') ?>

<section class="section">
    <div class="container">
        <h1 class="mb-1 text-xl font-bold text-slate-900 dark:text-white">Documents</h1>
        <p class="mb-4 text-sm text-slate-500 dark:text-slate-400">
            Internal documents. They have no public address: they open only from here, for signed in admins.
        </p>

        <?php if ($docs === []): ?>
            <div class="empty">No documents yet.</div>
        <?php else: ?>
            <div class="tablewrap">
                <table class="table">
                    <thead><tr><th>Document</th><th>Updated</th><th>Size</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($docs as $d): ?>
                        <tr>
                            <td>
                                <strong><?= esc($d['title']) ?></strong>
                                <div class="text-xs text-slate-500 dark:text-slate-400"><?= esc($d['description']) ?></div>
                            </td>
                            <td><?= $d['modified'] !== null ? esc(date('j M Y', $d['modified'])) : '' ?></td>
                            <td><?= $d['bytes'] !== null ? esc(number_format($d['bytes'] / 1024) . ' KB') : '' ?></td>
                            <td>
                                <?php if ($d['bytes'] === null): ?>
                                    <span class="text-xs text-brand-crimson">File missing</span>
                                <?php else: ?>
                                    <div class="actions">
                                        <a class="btn btn-primary btn-xs" href="<?= base_url('admin/documents/' . $d['key']) ?>" target="_blank" rel="noopener">Open</a>
                                        <a class="btn btn-ghost btn-xs" href="<?= base_url('admin/documents/' . $d['key']) ?>?download=1">Download</a>
                                    </div>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</section>
<?= $this->endSection() ?>

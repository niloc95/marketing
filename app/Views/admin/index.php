<?= $this->extend('layouts/public') ?>

<?= $this->section('head') ?>
<?= seo_meta(['title' => 'Admin — WebScheduler Directory']) ?>
<meta name="robots" content="noindex, nofollow">
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<?php $counts = $result['counts']; $tabs = ['' => 'All', 'pending' => 'Pending', 'published' => 'Published', 'unpublished' => 'Unpublished']; ?>
<div class="admin-bar">
    <div class="container">
        <strong>Directory admin</strong>
        <a href="<?= base_url('admin/logout') ?>">Sign out</a>
    </div>
</div>

<section class="section">
    <div class="container">
        <h1 style="font-size:24px;margin:0 0 16px">Listings</h1>

        <div class="tabs">
            <?php foreach ($tabs as $key => $label): ?>
                <?php $c = $key === '' ? ($counts['all'] ?? 0) : ($counts[$key] ?? 0); ?>
                <a class="<?= $status === $key ? 'active' : '' ?>" href="<?= base_url('admin') . ($key ? '?status=' . $key : '') ?>"><?= esc($label) ?> (<?= (int) $c ?>)</a>
            <?php endforeach; ?>
        </div>

        <?php if (empty($result['items'])): ?>
            <div class="empty">No listings in this view.</div>
        <?php else: ?>
        <div class="tablewrap">
            <table class="table">
                <thead>
                    <tr><th>Name</th><th>Profession</th><th>Location</th><th>Status</th><th>Verified</th><th>Actions</th></tr>
                </thead>
                <tbody>
                <?php foreach ($result['items'] as $l): ?>
                    <tr>
                        <td>
                            <strong><?= esc($l['display_name']) ?></strong>
                            <?php if (! empty($l['is_featured'])): ?> <span class="pill" style="background:#fffbeb;color:#a16207">★</span><?php endif; ?>
                            <div style="color:var(--muted);font-size:12px"><?= esc($l['email'] ?? '') ?></div>
                        </td>
                        <td><?= esc($l['profession_name'] ?? '—') ?></td>
                        <td><?= esc(trim(($l['city'] ?? '') . ' ' . ($l['province'] ?? ''))) ?: '—' ?></td>
                        <td><span class="pill pill-<?= esc($l['status'], 'attr') ?>"><?= esc($l['status']) ?></span></td>
                        <td><?= ! empty($l['is_verified']) ? '✓' : '—' ?></td>
                        <td>
                            <div class="actions">
                                <?php if ($l['status'] !== 'published'): ?>
                                    <form method="post" action="<?= base_url('admin/publish/' . $l['id']) ?>"><button class="btn btn-primary btn-xs">Publish</button></form>
                                <?php else: ?>
                                    <form method="post" action="<?= base_url('admin/unpublish/' . $l['id']) ?>"><button class="btn btn-ghost btn-xs">Unpublish</button></form>
                                <?php endif; ?>
                                <form method="post" action="<?= base_url('admin/feature/' . $l['id']) ?>">
                                    <input type="hidden" name="on" value="<?= empty($l['is_featured']) ? '1' : '0' ?>">
                                    <button class="btn btn-ghost btn-xs"><?= empty($l['is_featured']) ? 'Feature' : 'Unfeature' ?></button>
                                </form>
                                <a class="btn btn-ghost btn-xs" href="<?= base_url('directory/' . $l['slug']) ?>" target="_blank">View</a>
                                <form method="post" action="<?= base_url('admin/delete/' . $l['id']) ?>" onsubmit="return confirm('Remove this listing?')"><button class="btn btn-ghost btn-xs" style="color:#b91c1c">Delete</button></form>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php if ($result['totalPages'] > 1): ?>
            <nav class="pager">
                <?php for ($i = 1; $i <= $result['totalPages']; $i++): ?>
                    <?php $href = base_url('admin') . '?' . http_build_query(array_filter(['status' => $status, 'page' => $i])); ?>
                    <?php if ($i === $result['page']): ?><span class="current"><?= $i ?></span><?php else: ?><a href="<?= esc($href, 'attr') ?>"><?= $i ?></a><?php endif; ?>
                <?php endfor; ?>
            </nav>
        <?php endif; ?>
        <?php endif; ?>
    </div>
</section>
<?= $this->endSection() ?>

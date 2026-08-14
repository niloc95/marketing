<?= $this->extend('layouts/public') ?>

<?= $this->section('head') ?>
<?= seo_meta(['title' => 'Admin — ' . config('Directory')->siteName()]) ?>
<meta name="robots" content="noindex, nofollow">
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<?php
$counts = $result['counts'];
$tabs   = [
    ''            => 'All',
    'pending'     => 'Pending',
    'published'   => 'Published',
    'unpublished' => 'Unpublished',
    'trashed'     => 'Trash',
];
/** Preserve the active filters when linking to another tab or page. */
$link = function (array $overrides = []) use ($status, $filters) {
    $q = array_filter(array_merge(['status' => $status], $filters, $overrides), fn ($v) => $v !== '' && $v !== null);
    return base_url('admin') . ($q ? '?' . http_build_query($q) : '');
};
$isTrash = $status === 'trashed';
?>
<?= view('admin/_bar') ?>

<?php // Outbound mail failing is the one outage the public site hides completely:
      // signups still report "check your email". Nobody would think to look for
      // it, so say it on the page an admin lands on. ?>
<?php if (! empty($mailError)): ?>
    <div class="container mt-5">
        <div class="alert alert-error">
            <strong>Outbound email is failing.</strong>
            Verification and manage links are not reaching people — signups will sit in Pending.
            Last failure <?= esc(date('j M Y H:i', $mailError['at'])) ?>:
            <?= esc($mailError['reason']) ?>
        </div>
    </div>
<?php endif; ?>

<section class="section">
    <div class="container">
        <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
            <h1 class="text-xl font-bold text-slate-900 dark:text-white">Profiles <span class="text-sm font-normal text-slate-500 dark:text-slate-400">(<?= (int) $result['total'] ?>)</span></h1>
            <a class="btn btn-accent btn-xs" href="<?= base_url('admin/new') ?>">+ New profile</a>
        </div>

        <form method="get" action="<?= base_url('admin') ?>" class="mb-4 flex flex-wrap items-end gap-2">
            <?php if ($status !== ''): ?><input type="hidden" name="status" value="<?= esc($status, 'attr') ?>"><?php endif; ?>
            <div class="field mb-0">
                <label class="text-xs">Search</label>
                <input type="text" name="q" value="<?= esc($filters['q'], 'attr') ?>" placeholder="Name, email, city or phone" class="w-64">
            </div>
            <div class="field mb-0">
                <label class="text-xs">Category</label>
                <select name="category" class="w-52">
                    <option value="">All</option>
                    <?php foreach ($categories as $c): ?>
                        <option value="<?= esc($c['slug'], 'attr') ?>" <?= $filters['category'] === $c['slug'] ? 'selected' : '' ?>><?= esc($c['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field mb-0">
                <label class="text-xs">Province</label>
                <select name="province" class="w-44">
                    <option value="">All</option>
                    <?php foreach ($provinces as $p): ?>
                        <option value="<?= esc($p, 'attr') ?>" <?= $filters['province'] === $p ? 'selected' : '' ?>><?= esc($p) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button class="btn btn-primary btn-xs">Filter</button>
            <?php if ($filters['q'] || $filters['category'] || $filters['province']): ?>
                <a class="btn btn-ghost btn-xs" href="<?= esc($link(['q' => '', 'category' => '', 'province' => '']), 'attr') ?>">Clear</a>
            <?php endif; ?>
        </form>

        <div class="tabs">
            <?php foreach ($tabs as $key => $label): ?>
                <?php $c = $key === '' ? ($counts['all'] ?? 0) : ($counts[$key] ?? 0); ?>
                <a class="<?= $status === $key ? 'active' : '' ?>" href="<?= esc($link(['status' => $key, 'page' => '']), 'attr') ?>"><?= esc($label) ?> (<?= (int) $c ?>)</a>
            <?php endforeach; ?>
        </div>

        <?php if (empty($result['items'])): ?>
            <div class="empty">No profiles in this view.</div>
        <?php else: ?>
        <div class="tablewrap">
            <table class="table">
                <thead>
                    <?php // "Email" and "Badge", not one column called "Verified". Two
                          // different things wear that word here: is_verified means the
                          // owner clicked their confirmation link, verified_until means
                          // they pay for the Verified Business badge. One header covering
                          // both is how someone ends up refunding the wrong person. ?>
                    <tr><th>Name</th><th>Category</th><th>Location</th><th>Pin</th><th>Status</th><th>Email</th><th>Badge</th><th>Actions</th></tr>
                </thead>
                <tbody>
                <?php foreach ($result['items'] as $l): ?>
                    <tr>
                        <td>
                            <strong><?= esc($l['display_name']) ?></strong>
                            <?php if (! empty($l['is_featured'])): ?> <span class="pill badge-featured">★</span><?php endif; ?>
                            <div class="text-xs text-slate-500 dark:text-slate-400"><?= esc($l['email'] ?? '') ?></div>
                        </td>
                        <td><?= esc($l['category_name'] ?? '—') ?></td>
                        <td><?= esc(trim(($l['city'] ?? '') . ' ' . ($l['province'] ?? ''))) ?: '—' ?></td>
                        <?php
                        // How good is this listing's map pin? Anything below
                        // exact/manual is a centroid that can sit hundreds of
                        // metres out, so these are the rows worth chasing an
                        // owner about (or fixing with the picker on the edit form).
                        $pin      = $l['geocode_precision'] ?? null;
                        $pinLabel = $pin ?: (($l['latitude'] ?? null) === null ? 'none' : 'unknown');
                        $pinGood  = in_array($pin, ['manual', 'exact'], true);
                        ?>
                        <td>
                            <span class="pill <?= $pinGood ? 'pill-published' : 'pill-pending' ?>" title="<?= $pinGood ? 'Pinpointed' : 'Approximate — worth confirming on the edit form' ?>"><?= esc($pinLabel) ?></span>
                        </td>
                        <td><span class="pill pill-<?= esc($l['status'], 'attr') ?>"><?= esc($l['status']) ?></span></td>
                        <td><?= ! empty($l['is_verified']) ? '✓' : '—' ?></td>
                        <td>
                            <?php if (listing_is_verified_business($l)): ?>
                                <span class="pill pill-published" title="Paid through <?= esc($l['verified_until'], 'attr') ?>">✓</span>
                            <?php elseif (! empty($l['verified_until'])): ?>
                                <span class="pill pill-unpublished" title="Lapsed <?= esc($l['verified_until'], 'attr') ?>">lapsed</span>
                            <?php else: ?>
                                —
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="actions">
                                <?php if ($isTrash): ?>
                                    <form method="post" action="<?= base_url('admin/restore/' . $l['id']) ?>"><?= csrf_field() ?><button class="btn btn-primary btn-xs">Restore</button></form>
                                    <form method="post" action="<?= base_url('admin/purge/' . $l['id']) ?>" data-confirm="Permanently delete this profile? This cannot be undone."><?= csrf_field() ?><button class="btn btn-ghost btn-xs text-brand-crimson">Delete forever</button></form>
                                <?php else: ?>
                                    <a class="btn btn-ghost btn-xs" href="<?= base_url('admin/edit/' . $l['id']) ?>">Edit</a>
                                    <?php if ($l['status'] !== 'published'): ?>
                                        <form method="post" action="<?= base_url('admin/publish/' . $l['id']) ?>"><?= csrf_field() ?><button class="btn btn-primary btn-xs">Publish</button></form>
                                    <?php else: ?>
                                        <form method="post" action="<?= base_url('admin/unpublish/' . $l['id']) ?>"><?= csrf_field() ?><button class="btn btn-ghost btn-xs">Unpublish</button></form>
                                    <?php endif; ?>
                                    <form method="post" action="<?= base_url('admin/feature/' . $l['id']) ?>">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="on" value="<?= empty($l['is_featured']) ? '1' : '0' ?>">
                                        <button class="btn btn-ghost btn-xs"><?= empty($l['is_featured']) ? 'Feature' : 'Unfeature' ?></button>
                                    </form>
                                    <a class="btn btn-ghost btn-xs" href="<?= esc(base_url('directory/' . $l['slug']), 'attr') ?>" target="_blank">View</a>
                                    <form method="post" action="<?= base_url('admin/delete/' . $l['id']) ?>" data-confirm="Move this profile to trash?"><?= csrf_field() ?><button class="btn btn-ghost btn-xs text-brand-crimson">Delete</button></form>
                                <?php endif; ?>
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
                    <?php if ($i === $result['page']): ?><span class="current"><?= $i ?></span><?php else: ?><a href="<?= esc($link(['page' => $i]), 'attr') ?>"><?= $i ?></a><?php endif; ?>
                <?php endfor; ?>
            </nav>
        <?php endif; ?>
        <?php endif; ?>
    </div>
</section>
<?= $this->endSection() ?>

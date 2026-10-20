<?= $this->extend('layouts/public') ?>

<?= $this->section('head') ?>
<?= seo_meta(['title' => 'Admin | ' . config('Directory')->siteName()]) ?>
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
$quality = new \App\Services\ListingQualityService();
?>
<?= view('admin/_bar') ?>

<?php // Outbound mail failing is the one outage the public site hides completely:
      // signups still report "check your email". Nobody would think to look for
      // it, so say it on the page an admin lands on. ?>
<?php if (! empty($mailError)): ?>
    <div class="container mt-5">
        <div class="alert alert-error">
            <strong>Outbound email is failing.</strong>
            Verification and manage links are not reaching people. Signups will sit in Pending.
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
            <div class="field mb-0">
                <label class="text-xs">Sort</label>
                <select name="sort" class="w-40">
                    <option value="">Newest</option>
                    <option value="score_asc" <?= $filters['sort'] === 'score_asc' ? 'selected' : '' ?>>Lowest score</option>
                    <option value="score_desc" <?= $filters['sort'] === 'score_desc' ? 'selected' : '' ?>>Highest score</option>
                </select>
            </div>
            <button class="btn btn-primary btn-xs">Filter</button>
            <?php if ($filters['q'] || $filters['category'] || $filters['province'] || $filters['sort']): ?>
                <a class="btn btn-ghost btn-xs" href="<?= esc($link(['q' => '', 'category' => '', 'province' => '', 'sort' => ''])) ?>">Clear</a>
            <?php endif; ?>
        </form>

        <div class="tabs">
            <?php foreach ($tabs as $key => $label): ?>
                <?php $c = $key === '' ? ($counts['all'] ?? 0) : ($counts[$key] ?? 0); ?>
                <a class="<?= $status === $key ? 'active' : '' ?>" href="<?= esc($link(['status' => $key, 'page' => ''])) ?>"><?= esc($label) ?> (<?= (int) $c ?>)</a>
            <?php endforeach; ?>
        </div>

        <?php if (empty($result['items'])): ?>
            <div class="empty">No profiles in this view.</div>
        <?php else: ?>
        <?php // Six columns, not eleven. The table used to need a sideways scroll on
              // anything but a wide monitor, so related facts now share a cell:
              // category under the name, pin under the place, score beside the
              // status, and the three yes/no facts as chips. Row actions are icon
              // buttons with a tooltip and an aria-label each; a dropdown would be
              // clipped by .tablewrap, which has to scroll on a phone. ?>
        <div class="tablewrap">
            <table class="table admin-list">
                <thead>
                    <tr><th>Profile</th><th>Location</th><th>Status &middot; Score</th><th>Created</th><th>Checks</th><th>Actions</th></tr>
                </thead>
                <tbody>
                <?php foreach ($result['items'] as $l): ?>
                    <?php
                    // How good is this listing's map pin? Anything below
                    // exact/manual is a centroid that can sit hundreds of
                    // metres out, so these are the rows worth chasing an
                    // owner about (or fixing with the picker on the edit form).
                    $pin      = $l['geocode_precision'] ?? null;
                    $pinLabel = $pin ?: (($l['latitude'] ?? null) === null ? 'no pin' : 'unknown');
                    $pinGood  = in_array($pin, ['manual', 'exact'], true);
                    // Kept from before a street edit whose new lookup failed.
                    // Whatever its precision, nobody has confirmed it since.
                    if (($l['geocoding_status'] ?? null) === \App\Libraries\ListingGeocoder::STATUS_STALE) {
                        $pinLabel = 'check pin';
                        $pinGood  = false;
                    }
                    $place    = trim(($l['city'] ?? '') . ' ' . ($l['province'] ?? ''));
                    $id       = (int) $l['id'];
                    ?>
                    <tr>
                        <td class="admin-list-profile">
                            <a class="admin-list-name" href="<?= base_url('admin/edit/' . $id) ?>"><?= esc($l['display_name']) ?></a>
                            <?php if (! empty($l['is_featured'])): ?><span class="pill badge-featured ml-1 align-middle" title="Featured"><?= lucide('star', 'h-3.5 w-3.5') ?></span><?php endif; ?>
                            <div class="admin-list-sub"><?= esc($l['category_name'] ?? 'No category') ?></div>
                            <?php if (! empty($l['email'])): ?><div class="admin-list-sub"><?= esc($l['email']) ?></div><?php endif; ?>
                        </td>
                        <td>
                            <div><?= $place !== '' ? esc($place) : 'n/a' ?></div>
                            <span class="pill mt-1 <?= $pinGood ? 'pill-published' : 'pill-pending' ?>" title="<?= $pinGood ? 'Pinpointed' : 'Approximate: worth confirming on the edit form' ?>"><?= lucide('map-pin', 'h-3 w-3') ?><?= esc($pinLabel) ?></span>
                        </td>
                        <td class="whitespace-nowrap">
                            <span class="pill pill-<?= esc($l['status'], 'attr') ?>"><?= esc($l['status']) ?></span>
                            <?php // Profile strength (ListingQualityService), the same number the
                                  // owner sees on /manage/edit. Open the profile for the checklist. ?>
                            <?php if (($l['quality_score'] ?? null) === null): ?>
                                <span class="pill pill-unpublished" title="Not scored yet">n/a</span>
                            <?php else: ?>
                                <?php $qs = (int) $l['quality_score']; ?>
                                <span class="pill <?= ['high' => 'pill-published', 'mid' => 'pill-score-mid', 'low' => 'pill-pending'][strength_tone($qs)] ?>" title="Profile strength: <?= esc($quality->bandFor($qs), 'attr') ?>"><?= $qs ?></span>
                            <?php endif; ?>
                        </td>
                        <td class="whitespace-nowrap text-xs"><?= esc(local_datetime($l['created_at'] ?? null)) ?></td>
                        <?php // "Email" and "Badge", never one chip called "Verified". Two
                              // different things wear that word here: is_verified means the
                              // owner clicked their confirmation link, verified_until means
                              // they pay for the Verified Business badge. One label covering
                              // both is how someone ends up refunding the wrong person.
                              // "Reports" is the monthly analytics opt-in, read-only here. ?>
                        <td>
                            <div class="admin-chips">
                                <span class="admin-chip <?= ! empty($l['is_verified']) ? 'is-on' : '' ?>" title="<?= ! empty($l['is_verified']) ? 'Email confirmed' : 'Email not confirmed' ?>">Email</span>
                                <?php if (listing_is_verified_business($l)): ?>
                                    <span class="admin-chip is-on" title="Badge paid through <?= esc($l['verified_until'], 'attr') ?>">Badge</span>
                                <?php elseif (! empty($l['verified_until'])): ?>
                                    <span class="admin-chip is-warn" title="Badge lapsed <?= esc($l['verified_until'], 'attr') ?>">Lapsed</span>
                                <?php else: ?>
                                    <span class="admin-chip" title="No Verified Business badge">Badge</span>
                                <?php endif; ?>
                                <span class="admin-chip <?= ! empty($l['marketing_opt_in']) ? 'is-on' : '' ?>" title="<?= ! empty($l['marketing_opt_in']) ? 'Analytics reports on since ' . esc((string) $l['marketing_consent_at'], 'attr') : 'No analytics reports' ?>">Reports</span>
                            </div>
                        </td>
                        <td>
                            <div class="actions admin-actions">
                                <?php if ($isTrash): ?>
                                    <form method="post" action="<?= base_url('admin/restore/' . $id) ?>"><?= csrf_field() ?><button class="icon-act" title="Restore" aria-label="Restore <?= esc($l['display_name'], 'attr') ?>"><?= lucide('rotate-ccw', 'h-4 w-4') ?></button></form>
                                    <form method="post" action="<?= base_url('admin/purge/' . $id) ?>" data-confirm="Permanently delete this profile? This cannot be undone."><?= csrf_field() ?><button class="icon-act is-danger" title="Delete forever" aria-label="Delete <?= esc($l['display_name'], 'attr') ?> forever"><?= lucide('trash-2', 'h-4 w-4') ?></button></form>
                                <?php else: ?>
                                    <a class="icon-act" href="<?= base_url('admin/edit/' . $id) ?>" title="Edit" aria-label="Edit <?= esc($l['display_name'], 'attr') ?>"><?= lucide('square-pen', 'h-4 w-4') ?></a>
                                    <a class="icon-act" href="<?= esc(base_url('directory/' . $l['slug'])) ?>" target="_blank" rel="noopener" title="View profile" aria-label="View <?= esc($l['display_name'], 'attr') ?>"><?= lucide('external-link', 'h-4 w-4') ?></a>
                                    <?php if ($l['status'] !== 'published'): ?>
                                        <form method="post" action="<?= base_url('admin/publish/' . $id) ?>"><?= csrf_field() ?><button class="icon-act is-primary" title="Publish" aria-label="Publish <?= esc($l['display_name'], 'attr') ?>"><?= lucide('eye', 'h-4 w-4') ?></button></form>
                                    <?php else: ?>
                                        <form method="post" action="<?= base_url('admin/unpublish/' . $id) ?>"><?= csrf_field() ?><button class="icon-act" title="Unpublish" aria-label="Unpublish <?= esc($l['display_name'], 'attr') ?>"><?= lucide('eye-off', 'h-4 w-4') ?></button></form>
                                    <?php endif; ?>
                                    <?php // Only where a verify link still means something — resendVerification() refuses any other status anyway. ?>
                                    <?php if ($l['status'] === 'pending'): ?>
                                        <form method="post" action="<?= base_url('admin/resend-verify/' . $id) ?>"><?= csrf_field() ?><button class="icon-act" title="Resend verify email" aria-label="Resend verify email to <?= esc($l['display_name'], 'attr') ?>"><?= lucide('send', 'h-4 w-4') ?></button></form>
                                    <?php endif; ?>
                                    <form method="post" action="<?= base_url('admin/feature/' . $id) ?>">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="on" value="<?= empty($l['is_featured']) ? '1' : '0' ?>">
                                        <?php $featureLabel = empty($l['is_featured']) ? 'Feature' : 'Unfeature'; ?>
                                        <button class="icon-act" title="<?= $featureLabel ?>" aria-label="<?= $featureLabel ?> <?= esc($l['display_name'], 'attr') ?>"><?= lucide(empty($l['is_featured']) ? 'star' : 'star-off', 'h-4 w-4') ?></button>
                                    </form>
                                    <form method="post" action="<?= base_url('admin/delete/' . $id) ?>" data-confirm="Move this profile to trash?"><?= csrf_field() ?><button class="icon-act is-danger" title="Move to trash" aria-label="Move <?= esc($l['display_name'], 'attr') ?> to trash"><?= lucide('trash-2', 'h-4 w-4') ?></button></form>
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
                    <?php if ($i === $result['page']): ?><span class="current"><?= $i ?></span><?php else: ?><a href="<?= esc($link(['page' => $i])) ?>"><?= $i ?></a><?php endif; ?>
                <?php endfor; ?>
            </nav>
        <?php endif; ?>
        <?php endif; ?>
    </div>
</section>
<?= $this->endSection() ?>

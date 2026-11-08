<?php
/**
 * Layout for /admin: the app shell (layouts/_shell.php) with the admin
 * sidebar. Admin pages used to sit inside layouts/public.php, under the
 * public header, the Popular categories strip and the footer; none of that
 * belongs on a staff screen.
 *
 * The page's own head section (seo_meta title) passes through. Every view()
 * call here sits inside a section: one outside would render this layout
 * early, because the page's extend() is still pending on the shared renderer.
 *
 * A page with a "bare" section (admin/login.php) has no sidebar.
 */
$bare = trim($this->renderSection('bare')) !== '';
?>
<?= $this->extend('layouts/_shell') ?>

<?= $this->section('assets') ?>
<?php $shellJs = 'assets/business/shell.js'; ?>
<?php if (is_file(FCPATH . $shellJs)): ?>
    <script type="module" src="<?= base_url($shellJs) ?>?v=<?= @filemtime(FCPATH . $shellJs) ?: 0 ?>"></script>
<?php endif ?>
<?= $this->endSection() ?>

<?= $this->section('body_attrs') ?>
<?php
// directory.js reads these on the profile form (admin/edit.php): where a map
// opens and where a pin may go, as in layouts/public.php.
$countryViews = [];
$countryBoxes = [];
foreach (config('Countries')->profiles as $code => $profile) {
    $name                = (string) config('Countries')->nameForCode($code);
    $countryViews[$name] = $profile['mapView'];
    $countryBoxes[$name] = $profile['bbox'];
}
?>
data-shell="admin" data-shell-scope="<?= esc(rtrim((string) parse_url(base_url('admin'), PHP_URL_PATH), '/'), 'attr') ?>"<?= $bare ? ' data-shell-bare' : '' ?>
 data-site-map-view="<?= esc(json_encode(site()->profile()['mapView']), 'attr') ?>"
data-country-views="<?= esc(json_encode($countryViews, JSON_UNESCAPED_UNICODE), 'attr') ?>"
data-country-bboxes="<?= esc(json_encode($countryBoxes, JSON_UNESCAPED_UNICODE), 'attr') ?>"
<?= $this->endSection() ?>

<?php if (! $bare): ?>
<?= $this->section('sidebar') ?>
<?php
// The count is the point of a queue's link: a verification queue nobody is
// reminded of is a queue people wait in. The dashboard's "Needs action"
// tiles read the same counts.
$waiting = App\Services\AdminDashboardService::queueCounts();
// Lost form submissions (FormHealthService, cached a minute) get a count of
// their own while there are any.
$formsLost = (new App\Services\FormHealthService())->recentLost()['count'];

$current   = trim(uri_string(), '/');
$isCurrent = static function (string $path, array $prefixes = []) use ($current): bool {
    if ($current === $path) {
        return true;
    }
    // "admin" itself is only ever an exact match, or every page would be the Dashboard.
    foreach ($path === 'admin' ? $prefixes : array_merge([$path], $prefixes) as $prefix) {
        if ($current === $prefix || str_starts_with($current, $prefix . '/')) {
            return true;
        }
    }

    return false;
};

// [label, path, icon, waiting count, prefixes that make it the current page, count label]
$groups = [
    null => [
        ['Dashboard', 'admin', 'layout-dashboard', 0, []],
        ['Profiles', 'admin/profiles', 'building-2', 0, ['admin/edit', 'admin/new', 'admin/preview']],
    ],
    'Queues' => [
        ['Verification', 'admin/verifications', 'badge-check', $waiting['verifications'], ['admin/verification', 'admin/plus']],
        ['Jobs', 'admin/jobs', 'briefcase', $waiting['jobs'], []],
        ['Reviews', 'admin/reviews', 'star', $waiting['reviews'], []],
        ['Referrals', 'admin/referrals', 'send', $waiting['referrals'], []],
        ['Partners', 'admin/partners', 'heart-handshake', $waiting['partners'], []],
        ['Careers', 'admin/careers', 'graduation-cap', $waiting['careers'], []],
        ['Setup orders', 'admin/profile-setup', 'wrench', $waiting['setup'], []],
    ],
    'Content' => [
        ['Categories', 'admin/categories', 'folder', 0, []],
        ['Venues', 'admin/venues', 'landmark', 0, []],
        ['Hero photos', 'admin/hero', 'images', 0, []],
        ['Comparison table', 'admin/comparison', 'scale', 0, []],
    ],
    'Business' => [
        ['Documents', 'admin/documents', 'file-text', 0, []],
        ['Badge funnel', 'admin/funnel', 'chart-column', 0, []],
        ['Discovery', 'admin/discovery', 'search', 0, []],
        ['Stats', 'admin/stats', 'chart-column', 0, []],
        ...(config('Bookings')->enabled() ? [['Online bookings', 'admin/bookings', 'calendar-days', 0, []]] : []),
    ],
    'System' => [
        ['Settings', 'admin/settings', 'settings', 0, []],
        ['Status and health', 'admin/status', 'info', 0, []],
        ['Form health', 'admin/forms', 'circle-alert', $formsLost, [], 'lost in the last 24 hours'],
    ],
];

$navGroups = [];
foreach ($groups as $heading => $items) {
    $navGroups[] = [
        'heading' => $heading === '' ? null : $heading,
        'items'   => array_map(static fn (array $i): array => [
            'label'      => $i[0],
            'path'       => $i[1],
            'icon'       => $i[2],
            'count'      => $i[3],
            'countLabel' => $i[5] ?? 'waiting',
            'active'     => $isCurrent($i[1], $i[4]),
        ], $items),
    ];
}

$footer = '<a class="shell-link" href="' . base_url('/') . '" target="_blank" rel="noopener">' . lucide('external-link', 'shell-link-icon h-5 w-5') . '<span class="shell-text">View site</span></a>'
    . '<form method="post" action="' . base_url('admin/logout') . '" data-no-spa>' . csrf_field()
    . '<button type="submit" class="shell-link w-full">' . lucide('log-out', 'shell-link-icon h-5 w-5') . '<span class="shell-text">Sign out</span></button></form>';
?>
<?= view('layouts/_sidebar', [
    'label'       => 'Admin',
    'home'        => 'admin',
    'context'     => '',
    'contextHref' => '',
    'groups'      => $navGroups,
    'footer'      => $footer,
], ['saveData' => false]) ?>
<?= $this->endSection() ?>

<?= $this->section('page_title') ?>
<?php
$pageTitle = 'Admin';
foreach ($navGroups as $group) {
    foreach ($group['items'] as $item) {
        if ($item['active']) {
            $pageTitle = $item['label'];
        }
    }
}
echo esc($pageTitle);
?>
<?= $this->endSection() ?>
<?php endif ?>

<?= $this->section('main_class') ?>pb-12<?= $this->endSection() ?>

<?= $this->section('main_top') ?>
<?php foreach (['success' => 'alert-success', 'error' => 'alert-error', 'info' => 'alert-info'] as $key => $cls): ?>
    <?php if (flash($key)): ?>
        <div class="container mt-5"><div class="alert <?= $cls ?>" role="<?= $key === 'error' ? 'alert' : 'status' ?>"><?= esc(flash($key)) ?></div></div>
    <?php endif ?>
<?php endforeach ?>
<?php // Upload problems are a list, and they accompany rather than replace the
      // success message: a save can succeed while some photos are rejected. ?>
<?php if ($uploadErrors = flash('upload_errors')): ?>
    <div class="container mt-5">
        <div class="alert alert-warning">
            <strong><?= count($uploadErrors) === 1 ? 'One image was not added:' : count($uploadErrors) . ' images were not added:' ?></strong>
            <ul class="alert-list">
                <?php foreach ($uploadErrors as $uploadError): ?>
                    <li><?= esc($uploadError) ?></li>
                <?php endforeach ?>
            </ul>
        </div>
    </div>
<?php endif ?>
<?php // The customer's link from Admin::sendPreview(), shown once: only its hash
      // is stored. The admin "Preview" button is behind Cloudflare Access and
      // is no use to a customer, so this is the one to pass on. ?>
<?php if (is_string($previewLink = session()->getFlashdata('preview_link')) && $previewLink !== ''): ?>
    <div class="container mt-5">
        <div class="alert alert-info">
            <p>Customer preview link (also emailed). Share only with the owner:</p>
            <p class="mt-2 break-all"><a href="<?= esc($previewLink, 'attr') ?>" target="_blank" rel="noopener noreferrer"><?= esc($previewLink) ?></a></p>
            <button type="button" class="btn btn-ghost btn-xs mt-2" data-copy="<?= esc($previewLink, 'attr') ?>">Copy link</button>
        </div>
    </div>
<?php endif ?>
<?= $this->endSection() ?>

<?= $this->section('end') ?>
<script defer src="<?= base_url('assets/directory.js') ?>?v=<?= @filemtime(FCPATH . 'assets/directory.js') ?: 0 ?>"></script>
<?= $this->endSection() ?>

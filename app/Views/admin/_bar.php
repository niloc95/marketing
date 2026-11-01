<?php
/**
 * Admin nav bar.
 *
 * Was copy-pasted byte-identically into index/categories/edit, which is how a
 * fourth link ends up on two pages out of three. One copy now.
 *
 * Two tiers. The queues, the pages where work waits, are always visible as
 * tabs, each with a count of what is waiting. Everything that is set up once
 * and visited rarely (taxonomy, hero photos, settings, diagnostics) sits in a
 * "More" menu, with Sign out at its foot. On a phone the tabs scroll sideways
 * and More stays put, so the bar is one line at every width.
 *
 * More is a <details>, so it opens with no script at all; directory.js only
 * adds closing it on an outside click or Escape.
 */

// The count is the point of the link: a verification queue nobody is reminded
// of is a queue people wait in. Counted here rather than passed in by each
// controller, so the pages that render this bar do not each have to remember
// to fetch it. The dashboard's "Needs action" tiles read the same counts.
$waiting = App\Services\AdminDashboardService::queueCounts();

// [label, path, waiting count, path prefixes that make it the current page]
$queues = [
    ['Dashboard', 'admin', 0, []],
    ['Profiles', 'admin/profiles', 0, ['admin/edit', 'admin/new', 'admin/preview']],
    ['Verification', 'admin/verifications', $waiting['verifications'], ['admin/verification']],
    ['Jobs', 'admin/jobs', $waiting['jobs'], []],
    ['Reviews', 'admin/reviews', $waiting['reviews'], []],
    ['Referrals', 'admin/referrals', $waiting['referrals'], []],
    ['Partners', 'admin/partners', $waiting['partners'], []],
    ['Careers', 'admin/careers', $waiting['careers'], []],
    ['Setup orders', 'admin/profile-setup', $waiting['setup'], []],
];

// Lost form submissions (FormHealthService, cached a minute) get a tab of
// their own while there are any, so nobody has to open More to notice.
$formsLost = (new App\Services\FormHealthService())->recentLost()['count'];
if ($formsLost > 0) {
    $queues[] = ['Form health', 'admin/forms', $formsLost, [], 'lost in the last 24 hours'];
}

$more = [
    'Content' => [
        ['Categories', 'admin/categories'],
        ['Venues', 'admin/venues'],
        ['Hero photos', 'admin/hero'],
        ['Comparison table', 'admin/comparison'],
    ],
    'Business' => [
        ['Documents', 'admin/documents'],
        ['Badge funnel', 'admin/funnel'],
        ['Discovery', 'admin/discovery'],
        ['Stats', 'admin/stats'],
    ],
    'System' => [
        ['Settings', 'admin/settings'],
        ['Status and health', 'admin/status'],
        ['Form health', 'admin/forms'],
    ],
];

$current = trim(uri_string(), '/');
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

$moreActive = null;
foreach ($more as $items) {
    foreach ($items as [$label, $path]) {
        if ($isCurrent($path)) {
            $moreActive = $label;
        }
    }
}
?>
<nav class="admin-bar" aria-label="Admin">
    <div class="container admin-bar-inner">
        <a class="admin-bar-brand" href="<?= base_url('admin') ?>">Admin</a>

        <div class="admin-bar-scroll">
            <ul class="admin-tabs">
                <?php foreach ($queues as $queue): ?>
                    <?php [$label, $path, $count, $prefixes] = $queue; $countLabel = $queue[4] ?? 'waiting'; ?>
                    <?php $active = $isCurrent($path, $prefixes); ?>
                    <li>
                        <a class="admin-tab<?= $active ? ' is-active' : '' ?>" href="<?= base_url($path) ?>"<?= $active ? ' aria-current="page"' : '' ?>>
                            <?= esc($label) ?>
                            <?php if ($count > 0): ?>
                                <span class="admin-count" title="<?= (int) $count ?> <?= esc($countLabel, 'attr') ?>"><?= $count > 99 ? '99+' : (int) $count ?><span class="sr-only"> <?= esc($countLabel) ?></span></span>
                            <?php endif; ?>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>

        <details class="admin-more" data-admin-more>
            <summary class="admin-tab<?= $moreActive !== null ? ' is-active' : '' ?>">
                <?= esc($moreActive ?? 'More') ?>
                <?= lucide('chevron-down', 'admin-more-chevron h-4 w-4') ?>
            </summary>
            <div class="admin-more-panel">
                <?php foreach ($more as $group => $items): ?>
                    <p class="admin-more-heading"><?= esc($group) ?></p>
                    <?php foreach ($items as [$label, $path]): ?>
                        <?php $active = $isCurrent($path); ?>
                        <a class="admin-more-link<?= $active ? ' is-active' : '' ?>" href="<?= base_url($path) ?>"<?= $active ? ' aria-current="page"' : '' ?>><?= esc($label) ?></a>
                    <?php endforeach; ?>
                <?php endforeach; ?>
                <form method="post" action="<?= base_url('admin/logout') ?>" class="admin-more-signout">
                    <?= csrf_field() ?>
                    <button type="submit" class="admin-more-link">Sign out</button>
                </form>
            </div>
        </details>
    </div>
</nav>

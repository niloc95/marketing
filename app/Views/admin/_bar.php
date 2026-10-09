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
// to fetch it.
$awaitingReview = (new App\Models\DirectoryVerificationModel())
    ->where('state', App\Models\DirectoryVerificationModel::STATE_SUBMITTED)
    ->countAllResults();
$jobsPending = (new App\Models\JobPostModel())
    ->where('status', App\Models\JobPostModel::STATUS_PENDING)
    ->countAllResults();
$reviewsPending = (new App\Models\DirectoryReviewModel())
    ->where('status', App\Models\DirectoryReviewModel::STATUS_PENDING)
    ->countAllResults();
$referralsPending = (new App\Models\DirectoryReferralModel())
    ->where('status', App\Models\DirectoryReferralModel::STATUS_PENDING)
    ->countAllResults();
$partnersPending = (new App\Models\DirectoryPartnerModel())
    ->where('status', App\Models\DirectoryPartnerModel::STATUS_APPLIED)
    ->countAllResults();

// [label, path, waiting count, path prefixes that make it the current page]
$queues = [
    ['Profiles', 'admin', 0, ['admin/edit', 'admin/new']],
    ['Verification', 'admin/verifications', $awaitingReview, ['admin/verification']],
    ['Jobs', 'admin/jobs', $jobsPending, []],
    ['Reviews', 'admin/reviews', $reviewsPending, []],
    ['Referrals', 'admin/referrals', $referralsPending, []],
    ['Partners', 'admin/partners', $partnersPending, []],
];

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
    ],
    'System' => [
        ['Settings', 'admin/settings'],
        ['Status and health', 'admin/status'],
    ],
];

$current = trim(uri_string(), '/');
$isCurrent = static function (string $path, array $prefixes = []) use ($current): bool {
    if ($current === $path) {
        return true;
    }
    // "admin" itself is only ever an exact match, or every page would be Profiles.
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
                <?php foreach ($queues as [$label, $path, $count, $prefixes]): ?>
                    <?php $active = $isCurrent($path, $prefixes); ?>
                    <li>
                        <a class="admin-tab<?= $active ? ' is-active' : '' ?>" href="<?= base_url($path) ?>"<?= $active ? ' aria-current="page"' : '' ?>>
                            <?= esc($label) ?>
                            <?php if ($count > 0): ?>
                                <span class="admin-count" title="<?= (int) $count ?> waiting"><?= $count > 99 ? '99+' : (int) $count ?><span class="sr-only"> waiting</span></span>
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

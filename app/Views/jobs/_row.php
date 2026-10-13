<?php
/**
 * One post in the /jobs list: a plain row, as on /careers.
 *
 * The Verified pill is a link of its own (to /verified), so the row cannot be
 * one <a>. The title's link is stretched over the whole row instead
 * (.posting-row-link::after), and the pill sits above it.
 *
 * @var array<string,mixed>          $p
 * @var App\Services\JobBoardService $svc
 */
$isJob  = $p['kind'] === App\Models\JobPostModel::KIND_JOB;
$place  = $svc->placeText($p);
$detail = array_filter($isJob
    ? [$svc->employmentLabel($p), $svc->salaryText($p)]
    : [! empty($p['budget_text']) ? 'Budget: ' . $p['budget_text'] : '']);
$posted = ! empty($p['published_at']) ? date('j M', strtotime((string) $p['published_at'])) : '';
$who    = $isJob || ! empty($p['listing_name']) ? $svc->hiringName($p) : '';
?>
<li>
    <div class="posting-row">
        <span class="posting-row-main">
            <span class="flex flex-wrap items-center gap-2">
                <span class="badge badge-category gap-1 <?= $isJob ? 'cat-tint-blue' : 'cat-tint-amber' ?>">
                    <?= lucide($isJob ? 'briefcase' : 'wrench', 'h-3 w-3 shrink-0') ?><?= $isJob ? 'Job' : 'Service needed' ?>
                </span>
                <?php if (! empty($p['category_name'])): ?>
                    <span class="text-xs text-slate-500 dark:text-slate-400"><?= esc($p['category_name']) ?></span>
                <?php endif; ?>
            </span>
            <a class="posting-row-title posting-row-link mt-1.5" href="<?= esc($svc->url($p), 'attr') ?>"><?= esc($p['title']) ?></a>
            <?php if ($who !== ''): ?>
                <span class="mt-0.5 block text-sm text-slate-600 dark:text-slate-300">
                    <?= esc($who) ?>
                    <?php if (listing_is_verified_business(['verified_until' => $p['listing_verified_until'] ?? null])): ?>
                        <span class="relative z-10"><?= verified_badge_pill('Verified') ?></span>
                    <?php endif; ?>
                </span>
            <?php endif; ?>
            <span class="posting-row-summary line-clamp-1"><?= esc(seo_excerpt((string) $p['description'], 160)) ?></span>
            <span class="posting-row-sub"><?= esc(implode(' · ', array_filter([$place, ...$detail, $posted]))) ?></span>
        </span>
        <span class="posting-row-meta">
            <?php if ($place !== ''): ?><span><?= esc($place) ?></span><?php endif; ?>
            <?php foreach ($detail as $d): ?><span><?= esc($d) ?></span><?php endforeach; ?>
            <?php if ($posted !== ''): ?><span class="text-slate-400">Posted <?= esc($posted) ?></span><?php endif; ?>
        </span>
        <?= lucide('chevron-right', 'posting-row-arrow h-5 w-5 shrink-0') ?>
    </div>
</li>

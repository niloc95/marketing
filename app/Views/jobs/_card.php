<?php
/**
 * One post in the /jobs list.
 *
 * @var array<string,mixed>          $p
 * @var App\Services\JobBoardService $svc
 */
$isJob   = $p['kind'] === App\Models\JobPostModel::KIND_JOB;
$place   = $svc->placeText($p);
$salary  = $isJob ? $svc->salaryText($p) : '';
$type    = $isJob ? $svc->employmentLabel($p) : '';
$posted  = ! empty($p['published_at']) ? date('j M', strtotime((string) $p['published_at'])) : '';
?>
<div class="card flex flex-col gap-2 p-4 transition-shadow hover:shadow-brand-lg">
    <div class="flex flex-wrap items-center gap-2">
        <span class="badge badge-category gap-1 <?= $isJob ? 'cat-tint-blue' : 'cat-tint-amber' ?>">
            <?= lucide($isJob ? 'briefcase' : 'wrench', 'h-3 w-3 shrink-0') ?><?= $isJob ? 'Job' : 'Service needed' ?>
        </span>
        <?php if (! empty($p['category_name'])): ?>
            <span class="text-xs text-slate-500 dark:text-slate-400"><?= esc($p['category_name']) ?></span>
        <?php endif; ?>
    </div>
    <h3 class="text-base font-semibold">
        <a class="text-slate-900 dark:text-white hover:text-primary-500 dark:hover:text-primary-300" href="<?= esc($svc->url($p), 'attr') ?>"><?= esc($p['title']) ?></a>
    </h3>
    <p class="text-sm text-slate-600 dark:text-slate-300">
        <?php if ($isJob || ! empty($p['listing_name'])): ?>
            <?= esc($svc->hiringName($p)) ?>
            <?php if (listing_is_verified_business(['verified_until' => $p['listing_verified_until'] ?? null])): ?>
                <span class="badge badge-verified gap-1"><?= lucide('badge-check', 'h-3.5 w-3.5 shrink-0') ?>Verified</span>
            <?php endif; ?>
        <?php endif; ?>
    </p>
    <div class="mt-auto flex flex-wrap items-center gap-x-3 gap-y-1 text-sm text-slate-500 dark:text-slate-400">
        <?php if ($place !== ''): ?><span class="inline-flex items-center gap-1"><?= lucide('map-pin', 'h-3.5 w-3.5 shrink-0') ?><?= esc($place) ?></span><?php endif; ?>
        <?php if ($type !== ''): ?><span><?= esc($type) ?></span><?php endif; ?>
        <?php if ($salary !== ''): ?><span><?= esc($salary) ?></span><?php endif; ?>
        <?php if (! $isJob && ! empty($p['budget_text'])): ?><span>Budget: <?= esc($p['budget_text']) ?></span><?php endif; ?>
        <?php if ($posted !== ''): ?><span class="inline-flex items-center gap-1"><?= lucide('calendar-days', 'h-3.5 w-3.5 shrink-0') ?><?= esc($posted) ?></span><?php endif; ?>
    </div>
</div>

<?php
/** @var array $l */
$name = $l['display_name'] ?? '';
$parts = preg_split('/\s+/', trim($name)) ?: [];
$initials = strtoupper(substr($parts[0] ?? 'W', 0, 1) . (count($parts) > 1 ? substr(end($parts), 0, 1) : ''));
$logo = $l['logo_path'] ?? '';
$logoUrl = $logo === '' ? '' : (preg_match('#^https?://#i', $logo) ? $logo : base_url($logo));
$place = trim(implode(', ', array_filter([$l['city'] ?? '', $l['province'] ?? ''])));
?>
<div class="card flex flex-col gap-3 p-4 transition-shadow hover:shadow-brand-lg">
    <div class="flex items-start gap-3">
        <div class="avatar">
            <?php if ($logoUrl !== ''): ?><img src="<?= esc($logoUrl, 'attr') ?>" alt=""><?php else: ?><?= esc($initials) ?><?php endif; ?>
        </div>
        <div class="min-w-0">
            <?php if (! empty($l['category_name'])): ?>
                <span class="badge mb-1"><?= esc($l['category_name']) ?></span>
            <?php endif; ?>
            <h3 class="truncate text-base font-semibold">
                <a class="text-slate-900 hover:text-primary-500" href="<?= base_url('directory/' . ($l['slug'] ?? '')) ?>"><?= esc($name) ?></a>
            </h3>
        </div>
    </div>

    <div class="mt-auto flex flex-wrap items-center gap-2 text-sm text-slate-500">
        <?php if ($place !== ''): ?><span>📍 <?= esc($place) ?></span><?php endif; ?>
        <?php if (! empty($l['is_featured'])): ?><span class="badge badge-featured">★ Featured</span><?php endif; ?>
    </div>
</div>

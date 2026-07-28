<?php
/** @var array $l */
$name = $l['display_name'] ?? '';
$parts = preg_split('/\s+/', trim($name)) ?: [];
$initials = strtoupper(substr($parts[0] ?? 'W', 0, 1) . (count($parts) > 1 ? substr(end($parts), 0, 1) : ''));
$logo = $l['logo_path'] ?? '';
$logoUrl = $logo === '' ? '' : (preg_match('#^https?://#i', $logo) ? $logo : base_url($logo));
$place = trim(implode(', ', array_filter([$l['city'] ?? '', $l['province'] ?? ''])));
?>
<div class="card">
    <div class="row">
        <div class="avatar">
            <?php if ($logoUrl !== ''): ?><img src="<?= esc($logoUrl, 'attr') ?>" alt=""><?php else: ?><?= esc($initials) ?><?php endif; ?>
        </div>
        <div>
            <?php if (! empty($l['profession_name'])): ?><span class="cat"><?= esc($l['profession_name']) ?></span><?php endif; ?>
            <h3><a href="<?= base_url('directory/' . ($l['slug'] ?? '')) ?>"><?= esc($name) ?></a></h3>
        </div>
    </div>
    <?php if ($place !== ''): ?><div class="meta">📍 <?= esc($place) ?></div><?php endif; ?>
    <?php if (! empty($l['is_featured'])): ?><div><span class="badge badge-featured">★ Featured</span></div><?php endif; ?>
</div>

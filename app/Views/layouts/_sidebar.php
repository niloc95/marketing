<?php
/**
 * The app shell's sidebar (layouts/_shell.php), shared by admin and
 * /business. Each layout builds its own items; this only draws them.
 *
 * - Phone: a drawer behind the Menu button. Tablet: a rail of icons that
 *   opens to the full width. Desktop: always open. All CSS (.shell-sidebar),
 *   with resources/shell/sidebar.js adding open and close.
 * - [data-shell-nav] and [data-shell-foot] are swapped with every page
 *   change (spa.js), so the current item and the counts always come from the
 *   server, and Sign out carries a CSRF token that is still good (it
 *   changes after every POST, Config\Security::$regenerate).
 *
 * @var string $label   aria label of the nav, also the brand line ("Admin")
 * @var string $home    where the brand links
 * @var string $context small line under the brand (a business name), or ''
 * @var string $contextHref where that line links, or ''
 * @var list<array{heading: ?string, items: list<array{label: string, path: string, icon: string, active: bool, count?: int, countLabel?: string, locked?: bool}>}> $groups
 * @var string $footer  rendered HTML for the foot (account, sign out)
 */
$brandUrl = static fn (string $path): string => base_url($path) . '?v=' . (@filemtime(FCPATH . $path) ?: 0);
?>
<aside id="shell-sidebar" class="shell-sidebar" data-shell-sidebar>
    <div class="shell-brand">
        <a class="shell-brand-link" href="<?= base_url($home) ?>">
            <img class="brand-mark" src="<?= $brandUrl('assets/brand/logo-64.png') ?>" alt="" width="32" height="32">
            <span class="shell-text">
                <span class="block font-bold">WebScheduler <span class="text-brand-orange">Local</span></span>
                <span class="block text-xs font-medium text-slate-500 dark:text-slate-400"><?= esc($label) ?></span>
            </span>
        </a>
        <button type="button" class="shell-icon-btn shell-close" data-shell-close aria-label="Close menu">
            <?= lucide('x', 'h-5 w-5') ?>
        </button>
    </div>

    <?php if ($context !== ''): ?>
        <a class="shell-context shell-text" href="<?= base_url($contextHref) ?>" title="Switch business"><?= esc($context) ?></a>
    <?php endif ?>

    <nav class="shell-nav" aria-label="<?= esc($label, 'attr') ?>" data-shell-nav>
        <?php foreach ($groups as $group): ?>
            <?php if ($group['items'] === []) {
                continue;
            } ?>
            <?php if ($group['heading'] !== null): ?>
                <p class="shell-group"><?= esc($group['heading']) ?></p>
            <?php endif ?>
            <ul class="shell-list">
                <?php foreach ($group['items'] as $item): ?>
                    <?php $count = (int) ($item['count'] ?? 0); $countLabel = $item['countLabel'] ?? 'waiting'; ?>
                    <li>
                        <a class="shell-link<?= $item['active'] ? ' is-active' : '' ?>" href="<?= base_url($item['path']) ?>" title="<?= esc($item['label'], 'attr') ?>"<?= $item['active'] ? ' aria-current="page"' : '' ?>>
                            <?= lucide($item['icon'], 'shell-link-icon h-5 w-5') ?>
                            <span class="shell-text"><?= esc($item['label']) ?></span>
                            <?php if (! empty($item['locked'])): ?>
                                <span class="shell-lock shell-text" title="Comes with an upgrade"><?= lucide('lock', 'h-3.5 w-3.5') ?><span class="sr-only"> (locked)</span></span>
                            <?php endif ?>
                            <?php if ($count > 0): ?>
                                <span class="shell-count" title="<?= $count ?> <?= esc($countLabel, 'attr') ?>"><?= $count > 99 ? '99+' : $count ?><span class="sr-only"> <?= esc($countLabel) ?></span></span>
                            <?php endif ?>
                        </a>
                    </li>
                <?php endforeach ?>
            </ul>
        <?php endforeach ?>
    </nav>

    <div class="shell-foot" data-shell-foot>
        <?= $footer ?>
    </div>
</aside>

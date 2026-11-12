<?php
/**
 * The section tabs of the profile editor, one look for the owner
 * (Business\Profile::tabs()) and admin (Admin::profileTabs()). A row of
 * .page-tabs: scrolls sideways on a phone, where the sidebar is a drawer
 * (resources/shell/tabs.js keeps the active one in view).
 *
 * Tabs marked inline are panes on this page: directory.js ("profile panes")
 * switches to them in place; the rest are ordinary links.
 *
 * @var list<array{href:string,label:string,active:bool,locked?:bool,inline?:bool,key?:string}> $tabs
 * @var string                                                         $label aria label
 */
?>
<nav class="page-tabs" aria-label="<?= esc($label ?? 'Profile sections', 'attr') ?>">
    <?php foreach ($tabs as $tab): ?>
        <a href="<?= esc($tab['href'], 'attr') ?>" class="page-tab<?= $tab['active'] ? ' is-active' : '' ?>"<?= $tab['active'] ? ' aria-current="page"' : '' ?>>
            <?= esc($tab['label']) ?>
            <?php if (! empty($tab['locked'])): ?>
                <span class="page-tab-lock" title="Comes with an upgrade"><?= lucide('lock', 'h-3.5 w-3.5') ?><span class="sr-only"> (locked)</span></span>
            <?php endif ?>
        </a>
    <?php endforeach ?>
</nav>

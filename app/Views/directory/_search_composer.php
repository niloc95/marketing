<?php
/**
 * THE search box. Every search on the site is this one composer: the home
 * page, the results page, category, province and venue pages, the header bar,
 * the 404 page and the jobs board. One wide pill, the field with no border of
 * its own, and a round send arrow inside it at the right (see "search
 * composer" in resources/directory.css).
 *
 * Only the box. The caller owns the <form> — its action, its hidden fields and
 * anything under the box (.search-refine) differ per page, and the box must not.
 *
 * Render it with ['saveData' => false]: see _search_input.php for what a shared
 * view-data array does to a partial that renders twice on one page (the header
 * and the hero both have a box).
 *
 * @var string       $listId      unique id for the typeahead listbox
 * @var string       $value       current q
 * @var string       $placeholder
 * @var string       $ariaLabel   the field's accessible name
 * @var string       $size        '' | 'lg' (home) | 'sm' (header bar)
 * @var string       $type        'search' in the header, else 'text'
 * @var list<string> $rotate      placeholders to cycle through (home only)
 * @var bool         $suggest     the business typeahead; false where the box
 *                                searches something else (the jobs board)
 */
$value       = $value       ?? '';
$placeholder = $placeholder ?? 'A business, service, person or place';
$ariaLabel   = $ariaLabel   ?? 'Search local businesses';
$size        = $size        ?? '';
$type        = $type        ?? 'text';
$rotate      = $rotate      ?? [];
$suggest     = $suggest     ?? true;

// Spelled out, never built as 'search-composer-' . $size: Tailwind keeps a
// component class only if it finds the whole name in a template, and a built
// one silently loses its styles in the compiled CSS.
$sizeClass = ['lg' => ' search-composer-lg', 'sm' => ' search-composer-sm'][$size] ?? '';
?>
<div class="search-composer<?= $sizeClass ?>">
    <?php if ($suggest): ?>
        <?= view('directory/_search_input', [
            'listId'      => $listId,
            'value'       => $value,
            'placeholder' => $placeholder,
            'ariaLabel'   => $ariaLabel,
            'type'        => $type,
            'rotate'      => $rotate,
        ], ['saveData' => false]) ?>
    <?php else: ?>
        <div class="search-suggest">
            <input type="<?= esc($type, 'attr') ?>" name="q" value="<?= esc($value, 'attr') ?>"
                   placeholder="<?= esc($placeholder, 'attr') ?>" aria-label="<?= esc($ariaLabel, 'attr') ?>">
        </div>
    <?php endif; ?>
    <?php // The word is the accessible name, so a screen reader hears the same
          // "Search" button the old boxes had. ?>
    <button class="search-composer-send" type="submit" aria-label="Search">
        <?= lucide('arrow-up', $size === 'sm' ? 'h-4 w-4' : 'h-5 w-5', ['aria-hidden' => 'true']) ?>
    </button>
</div>

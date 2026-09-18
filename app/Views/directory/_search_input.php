<?php
/**
 * One search box, with the typeahead listbox that belongs to it.
 *
 * Every search box on the site renders through here — the header bar and the
 * four hero .searchbar forms — so the markup contract the typeahead binds to
 * (public/assets/directory.js) exists in exactly one place. The module is a
 * no-op wherever this partial is absent.
 *
 * $listId is the one thing that cannot be shared, for the same reason it cannot
 * in _address_inputs.php: /directory renders TWO of these (the header bar and
 * the hero form), a listbox needs a real id for aria-controls, and duplicate
 * ids would point both comboboxes at the first list.
 *
 * The wrapper is what makes the dropdown position: .searchbar is a grid and
 * .header-search a flex row, neither of which is a positioning context. It also
 * becomes the grid/flex item in the input's place, which is why it carries the
 * full width rather than the input carrying it alone.
 *
 * @var string $listId      unique id for this box's suggestion listbox
 * @var string $value       current q, so a submitted search stays in the field
 * @var string $placeholder
 * @var string $ariaLabel   accessible name where no visible label exists
 * @var string $type        'search' in the header (it gets the clear button), else 'text'
 *
 * EVERY caller must pass ALL of these, even where the value is the default.
 * CodeIgniter renders the content view before the layout and shares one data
 * array between them, so a variable set by the hero's call is still set when the
 * header calls this afterwards: leaving one out does not fall back to the
 * default below, it silently inherits the other box's. That is how the header
 * bar — which is meant to start empty on every page — came back pre-filled with
 * the current search.
 */
$value       = $value       ?? '';
$placeholder = $placeholder ?? 'Name, service or keyword';
$ariaLabel   = $ariaLabel   ?? '';
$type        = $type        ?? 'text';
?>
<div class="search-suggest" data-search-suggest data-suggest-url="<?= base_url('directory/suggest') ?>">
    <?php // autocomplete="off" so the browser's own history dropdown does not
          // open on top of this one. ?>
    <input type="<?= esc($type, 'attr') ?>" name="q" value="<?= esc($value, 'attr') ?>"
           placeholder="<?= esc($placeholder, 'attr') ?>"
           <?php if ($ariaLabel !== ''): ?>aria-label="<?= esc($ariaLabel, 'attr') ?>"<?php endif; ?>
           autocomplete="off" data-search-suggest-input
           role="combobox" aria-autocomplete="list" aria-expanded="false"
           aria-controls="<?= esc($listId, 'attr') ?>">
    <ul class="search-suggest-list" id="<?= esc($listId, 'attr') ?>" role="listbox"
        data-search-suggest-list hidden></ul>
</div>

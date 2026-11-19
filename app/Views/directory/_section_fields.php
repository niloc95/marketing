<?php

use App\Services\ListingSectionService;

/**
 * Extra description cards, inside the listing form, right under the
 * description (card 1). Verified and Plus only: the caller draws this on
 * $showExtras. Same repeater as _location_fields.php (data-repeat, a
 * '__i__' clone template, Remove as a checkbox), and each body is the same
 * rich text editor as a team card's bio ([data-rich-mini], which directory.js
 * also mounts in rows the repeater adds).
 *
 * The marker input is what lets a save with every card removed clear them
 * (ListingSectionService::MARKER).
 *
 * @var array    $rows stored cards, or flashed input after a failed save
 * @var callable $err  fn(string $field): string, keys 'sections.0.heading' etc
 */
$max   = ListingSectionService::MAX_EXTRA;
$cap   = ListingSectionService::MAX_PLAIN_LENGTH;
$rows  = array_values(array_filter($rows, 'is_array'));
$used  = count($rows);
$slots = max(0, $max - $used);

$hasError = $err('sections') !== '';
foreach ($rows as $i => $_) {
    if ($err('sections.' . $i . '.heading') !== '' || $err('sections.' . $i . '.body') !== '') {
        $hasError = true;
    }
}

/** One card; $i = '__i__' builds the clone template. */
$row = function ($i, array $card = [], bool $spare = false) use ($err, $cap): string {
    $e  = static fn (string $k): string => is_string($i) ? '' : $err('sections.' . $i . '.' . $k);
    $id = 'section-' . $i;

    ob_start(); ?>
    <div class="repeat-row" data-repeat-item<?= $spare ? ' data-repeat-spare' : '' ?>>
        <?php if (! empty($card['id'])): ?>
            <input type="hidden" name="sections[<?= $i ?>][id]" value="<?= (int) $card['id'] ?>">
        <?php endif; ?>
        <div class="repeat-row-head">
            <h4 class="form-subhead">Card</h4>
            <label class="repeat-remove">
                <input type="checkbox" name="sections[<?= $i ?>][_remove]" value="1" data-repeat-remove aria-label="Remove this card">
                <span>Remove</span>
            </label>
        </div>
        <div class="field">
            <label for="<?= $id ?>-heading">Heading</label>
            <input type="text" id="<?= $id ?>-heading" name="sections[<?= $i ?>][heading]" maxlength="<?= ListingSectionService::MAX_HEADING_LENGTH ?>"
                   value="<?= esc((string) ($card['heading'] ?? ''), 'attr') ?>" placeholder="e.g. Procedures, Conditions we treat, Our approach">
            <?php if ($e('heading')): ?><div class="err"><?= esc($e('heading')) ?></div><?php endif; ?>
        </div>
        <div class="field" data-rich-mini data-rich-text-max="<?= $cap ?>">
            <label for="<?= $id ?>-body">Text</label>
            <textarea id="<?= $id ?>-body" name="sections[<?= $i ?>][body]" rows="6"><?= esc((string) ($card['body'] ?? '')) ?></textarea>
            <div class="rt-editor" hidden><div data-rich-text-for></div></div>
            <div class="rt-count" data-rich-text-count hidden></div>
            <div class="hint">Up to <?= number_format($cap) ?> characters.</div>
            <?php if ($e('body')): ?><div class="err"><?= esc($e('body')) ?></div><?php endif; ?>
        </div>
    </div>
    <?php
    return (string) ob_get_clean();
};
?>
<details class="disclosure" id="field-sections" <?= $used > 0 || $hasError ? 'open' : '' ?> data-repeat>
    <summary class="disclosure-summary">
        <span>More about you</span>
        <span class="hint"><?= $used > 0 ? $used . ' of ' . $max . ' cards' : 'Up to ' . $max . ' more cards, each with its own heading' ?></span>
    </summary>
    <div class="disclosure-body">
        <input type="hidden" name="<?= ListingSectionService::MARKER ?>" value="1">
        <p class="hint">
            Need more room? Add a card for each topic, such as procedures, conditions you treat or how a first visit works.
            Each card shows as its own panel under your description. Up to <?= (int) $max ?> cards of <?= number_format($cap) ?> characters each.
        </p>
        <?php if ($err('sections')): ?><div class="err"><?= esc($err('sections')) ?></div><?php endif; ?>
        <div data-repeat-list>
            <?php foreach ($rows as $i => $card): ?>
                <?= $row($i, $card) ?>
            <?php endforeach; ?>
            <?php if ($slots > 0): ?>
                <?= $row($used, [], $used > 0) ?>
            <?php endif; ?>
        </div>
        <template data-repeat-template><?= $row('__i__') ?></template>
        <button type="button" class="btn btn-ghost btn-xs" data-repeat-add
                data-repeat-max="<?= (int) $max ?>"
                data-repeat-full="You have added the maximum of <?= (int) $max ?> cards.">
            + Add a card
        </button>
    </div>
</details>

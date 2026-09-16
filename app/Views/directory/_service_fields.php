<?php

use App\Services\ServiceMenuService;

/**
 * "Services & prices" — one short row per service, inside the listing form.
 *
 * Same progressive-enhancement shape as _team_fields.php: a native <details>,
 * Remove as a real checkbox, and "Add another" cloning the <template> through
 * the shared data-repeat module in directory.js. Without JavaScript there are
 * still blank slots to type into, so a whole menu can be added over a save or
 * two rather than not at all.
 *
 * Rows carry no id: they hold no files, so ServiceMenuService replaces the whole
 * set on save, the way tags work.
 *
 * @var array    $rows stored service rows, or flashed input after a failed save
 * @var callable $err  fn(string $field): string — keys are 'services.0.name' etc
 */
$max = ServiceMenuService::MAX_SERVICES;

// Not filtered or re-keyed. Flashed input arrives with the indexes the errors
// are keyed on (0..n-1, since the add button indexes off the row count), and
// stored rows are never blank — so rendering them as given keeps each error on
// its own row.
$rows = array_values(array_filter(is_array($rows) ? $rows : [], 'is_array'));
$used = count(array_filter($rows, static fn (array $r): bool => trim((string) ($r['name'] ?? '')) !== ''));

// A few empty rows on a fresh form reads as "fill these in" far better than one,
// and costs nothing: a blank name is skipped on save.
$blanks = max(1, 3 - count($rows));
$blanks = min($blanks, max(0, $max - count($rows)));

$hasError = $err('services') !== '';
foreach (array_keys($rows) as $i) {
    $hasError = $hasError || $err('services.' . $i . '.name') !== '' || $err('services.' . $i . '.price_label') !== '';
}

$row = function ($i, array $m = []) use ($err): string {
    $val = static fn (string $k): string => (string) ($m[$k] ?? '');
    $e   = static fn (string $k): string => is_string($i) ? '' : $err('services.' . $i . '.' . $k);

    ob_start(); ?>
    <div class="service-row" data-repeat-item>
        <div class="field">
            <label class="sr-only" for="service-<?= $i ?>-name">Service</label>
            <input type="text" id="service-<?= $i ?>-name" name="services[<?= $i ?>][name]"
                   maxlength="<?= ServiceMenuService::MAX_NAME_LENGTH ?>"
                   value="<?= esc($val('name'), 'attr') ?>" placeholder="Service name">
            <?php if ($e('name')): ?><div class="err"><?= esc($e('name')) ?></div><?php endif; ?>
        </div>
        <div class="field">
            <label class="sr-only" for="service-<?= $i ?>-price">Price</label>
            <input type="text" id="service-<?= $i ?>-price" name="services[<?= $i ?>][price_label]"
                   maxlength="<?= ServiceMenuService::MAX_PRICE_LENGTH ?>"
                   value="<?= esc($val('price_label'), 'attr') ?>" placeholder="Price">
            <?php if ($e('price_label')): ?><div class="err"><?= esc($e('price_label')) ?></div><?php endif; ?>
        </div>
        <label class="repeat-remove">
            <input type="checkbox" name="services[<?= $i ?>][_remove]" value="1"
                   data-repeat-remove aria-label="Remove this service">
            <span>Remove</span>
        </label>
    </div>
    <?php
    return (string) ob_get_clean();
};
?>
<details class="disclosure" <?= $used > 0 || $hasError ? 'open' : '' ?> data-repeat>
    <summary class="disclosure-summary">
        <span>Services &amp; prices</span>
        <span class="hint"><?= $used > 0 ? $used . ' listed' : 'What you offer, e.g. classes, treatments, call-outs' ?></span>
    </summary>

    <div class="disclosure-body">
        <?php // Marker: tells the save this section was on the form, so an empty
              // list means "clear my services" rather than "not submitted". ?>
        <input type="hidden" name="<?= ServiceMenuService::SERVICES_MARKER ?>" value="1">
        <p class="hint">
            One line per service or class, e.g. &ldquo;Beginner yoga class&rdquo;. Prices are optional &mdash; &ldquo;R250&rdquo;,
            &ldquo;from R150&rdquo; or &ldquo;POA&rdquo; all work.
        </p>
        <?php if ($err('services')): ?><div class="err"><?= esc($err('services')) ?></div><?php endif; ?>

        <div data-repeat-list>
            <?php foreach ($rows as $i => $m): ?>
                <?= $row($i, $m) ?>
            <?php endforeach; ?>
            <?php for ($b = 0; $b < $blanks; $b++): ?>
                <?= $row(count($rows) + $b) ?>
            <?php endfor; ?>
        </div>

        <template data-repeat-template><?= $row('__i__') ?></template>

        <button type="button" class="btn btn-ghost btn-xs" data-repeat-add
                data-repeat-max="<?= (int) $max ?>"
                data-repeat-full="You have listed the maximum of <?= (int) $max ?> services.">
            + Add another service
        </button>
    </div>
</details>

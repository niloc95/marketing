<?php

use App\Services\PracticeLocationService;

/**
 * "Other locations" — extra branches, inside the listing form.
 *
 * Deliberately the same shape as _team_fields.php, down to the data- hooks, so
 * the one repeat script in directory.js drives both and a reader who has
 * understood one already understands this. See that file for why the disclosure
 * is a native <details>, why removal is a checkbox, and what happens with
 * JavaScript off.
 *
 * These rows carry no map pin. The listing's own address is the one that gets
 * geocoded and placed on the search map; a branch is an address and a phone
 * number on the profile, which is what the columns have always held.
 *
 * @var array    $rows      stored location rows, or flashed input after a failed save
 * @var array    $provinces DirectoryService::SA_PROVINCES
 * @var callable $err       fn(string $field): string — keys are 'locations.0.name' etc
 */
$max   = PracticeLocationService::MAX_LOCATIONS;
$rows  = array_values($rows);
$used  = count($rows);
$slots = max(0, $max - $used);

$hasError = $err('locations') !== '';
foreach ($rows as $i => $_) {
    foreach (['name', 'address_line', 'suburb', 'city', 'province', 'phone'] as $f) {
        if ($err('locations.' . $i . '.' . $f) !== '') {
            $hasError = true;
        }
    }
}

/** One row; $i = '__i__' builds the clone template. See _team_fields.php. */
$row = function ($i, array $loc = []) use ($err, $provinces): string {
    $val = static fn (string $k): string => (string) ($loc[$k] ?? '');
    $e   = static fn (string $k): string => is_string($i) ? '' : $err('locations.' . $i . '.' . $k);

    ob_start(); ?>
    <div class="repeat-row" data-repeat-item>
        <?php if (! empty($loc['id'])): ?>
            <input type="hidden" name="locations[<?= $i ?>][id]" value="<?= (int) $loc['id'] ?>">
        <?php endif; ?>

        <div class="repeat-row-head">
            <label class="repeat-remove">
                <input type="checkbox" name="locations[<?= $i ?>][_remove]" value="1"
                       data-repeat-remove aria-label="Remove this location">
                <span>Remove</span>
            </label>
        </div>

        <div class="form-row">
            <div class="field">
                <label>Branch name</label>
                <input type="text" name="locations[<?= $i ?>][name]" maxlength="200"
                       value="<?= esc($val('name'), 'attr') ?>" placeholder="e.g. Claremont office">
                <?php if ($e('name')): ?><div class="err"><?= esc($e('name')) ?></div><?php endif; ?>
            </div>
            <div class="field">
                <label>Phone</label>
                <input type="text" name="locations[<?= $i ?>][phone]" maxlength="40"
                       value="<?= esc($val('phone'), 'attr') ?>">
                <?php if ($e('phone')): ?><div class="err"><?= esc($e('phone')) ?></div><?php endif; ?>
            </div>
        </div>
        <div class="field">
            <label>Address</label>
            <input type="text" name="locations[<?= $i ?>][address_line]" maxlength="255"
                   value="<?= esc($val('address_line'), 'attr') ?>">
            <?php if ($e('address_line')): ?><div class="err"><?= esc($e('address_line')) ?></div><?php endif; ?>
        </div>
        <div class="form-row">
            <div class="field">
                <label>Suburb</label>
                <input type="text" name="locations[<?= $i ?>][suburb]" maxlength="120"
                       value="<?= esc($val('suburb'), 'attr') ?>">
                <?php if ($e('suburb')): ?><div class="err"><?= esc($e('suburb')) ?></div><?php endif; ?>
            </div>
            <div class="field">
                <label>City / town</label>
                <input type="text" name="locations[<?= $i ?>][city]" maxlength="120"
                       value="<?= esc($val('city'), 'attr') ?>">
                <?php if ($e('city')): ?><div class="err"><?= esc($e('city')) ?></div><?php endif; ?>
            </div>
        </div>
        <div class="field">
            <label>Province</label>
            <select name="locations[<?= $i ?>][province]">
                <option value="">Choose&hellip;</option>
                <?php foreach ($provinces as $prov): ?>
                    <option value="<?= esc($prov, 'attr') ?>" <?= $val('province') === $prov ? 'selected' : '' ?>><?= esc($prov) ?></option>
                <?php endforeach; ?>
            </select>
            <?php if ($e('province')): ?><div class="err"><?= esc($e('province')) ?></div><?php endif; ?>
        </div>
    </div>
    <?php
    return (string) ob_get_clean();
};
?>
<details class="disclosure" <?= $used > 0 || $hasError ? 'open' : '' ?> data-repeat>
    <summary class="disclosure-summary">
        <span>Add another location</span>
        <span class="hint"><?= $used > 0 ? $used . ' of ' . $max : 'Branches, second rooms, satellite offices' ?></span>
    </summary>

    <div class="disclosure-body">
        <p class="hint">
            Other places customers can find you. Your main address stays the one below &mdash;
            these appear as &ldquo;Other locations&rdquo; on your profile. Up to <?= (int) $max ?>.
        </p>
        <?php if ($err('locations')): ?><div class="err"><?= esc($err('locations')) ?></div><?php endif; ?>

        <div data-repeat-list>
            <?php foreach ($rows as $i => $loc): ?>
                <?= $row($i, is_array($loc) ? $loc : []) ?>
            <?php endforeach; ?>
            <?php if ($slots > 0): ?>
                <?= $row($used) ?>
            <?php endif; ?>
        </div>

        <template data-repeat-template><?= $row('__i__') ?></template>

        <button type="button" class="btn btn-ghost btn-xs" data-repeat-add
                data-repeat-max="<?= (int) $max ?>"
                data-repeat-full="You have listed the maximum of <?= (int) $max ?> locations.">
            + Add another location
        </button>
    </div>
</details>

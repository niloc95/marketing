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
 * A branch now carries the same field set as the listing's own address, and is
 * built from the same partials: _address_inputs.php and _hours_inputs.php, with
 * a 'locations[i]' name prefix. That is the whole point — a branch is not a
 * thinner kind of address, so it does not get a second, thinner form.
 *
 * Two deliberate differences from the primary, neither cosmetic:
 *
 *   - No pin picker and no address autocomplete. Both scripts bind one element
 *     per page, and the autocomplete's listbox id would be duplicated across
 *     rows. A branch is geocoded server-side on save instead —
 *     PracticeLocationService runs the same ListingGeocoder the listing does.
 *   - No "Copy Monday to every day" on the hours grid. That button is unhidden
 *     by directory.js per grid, but a cloned row's copy would be one more
 *     control inside an already-dense repeat row; the grid itself works either
 *     way, being plain inputs.
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
    foreach ([
        'name', 'contact_person', 'address_line', 'address_line_2', 'suburb',
        'city', 'province', 'postal_code', 'phone', 'phone_alt', 'email',
    ] as $f) {
        if ($err('locations.' . $i . '.' . $f) !== '') {
            $hasError = true;
        }
    }
}

/** One row; $i = '__i__' builds the clone template. See _team_fields.php. */
$row = function ($i, array $loc = []) use ($err, $provinces): string {
    // The three accessors the shared partials expect. $n turns a bare field
    // into this row's name — 'city' becomes 'locations[2][city]' — and $e is
    // blank for the clone template, whose '__i__' index has no errors of its own.
    //
    // $n's output is emitted unescaped while $val's is not, which is the right
    // way round: a name is built from literals and $i, and array_values() above
    // guarantees $i is an int (or the literal '__i__'), so there is no user data
    // in it. Values are user data. Running a name through esc(..., 'attr') would
    // also turn its brackets into &#x5B;, which works but reads as a bug.
    $n = static fn (string $f): string => 'locations[' . $i . '][' . $f . ']';
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
                <input type="text" name="<?= $n('name') ?>" maxlength="200"
                       value="<?= esc($val('name'), 'attr') ?>" placeholder="e.g. Claremont office">
                <?php if ($e('name')): ?><div class="err"><?= esc($e('name')) ?></div><?php endif; ?>
            </div>
            <div class="field">
                <label>Contact person</label>
                <input type="text" name="<?= $n('contact_person') ?>" maxlength="150"
                       value="<?= esc($val('contact_person'), 'attr') ?>">
                <div class="hint">Not shown on your profile.</div>
                <?php if ($e('contact_person')): ?><div class="err"><?= esc($e('contact_person')) ?></div><?php endif; ?>
            </div>
        </div>

        <div class="form-row">
            <div class="field">
                <label>Phone</label>
                <input type="text" name="<?= $n('phone') ?>" maxlength="40"
                       value="<?= esc($val('phone'), 'attr') ?>">
                <?php if ($e('phone')): ?><div class="err"><?= esc($e('phone')) ?></div><?php endif; ?>
            </div>
            <div class="field">
                <label>Alt phone</label>
                <input type="text" name="<?= $n('phone_alt') ?>" maxlength="40"
                       value="<?= esc($val('phone_alt'), 'attr') ?>">
                <?php if ($e('phone_alt')): ?><div class="err"><?= esc($e('phone_alt')) ?></div><?php endif; ?>
            </div>
        </div>
        <div class="field">
            <label>Email</label>
            <input type="email" name="<?= $n('email') ?>" maxlength="190"
                   value="<?= esc($val('email'), 'attr') ?>">
            <?php if ($e('email')): ?><div class="err"><?= esc($e('email')) ?></div><?php endif; ?>
        </div>

        <?php // The listing's own address block, same partial, prefixed names —
              // autocomplete included. $i is the row index (or '__i__' in the
              // clone template, which the repeat script rewrites), so every
              // row's listbox gets an id of its own. ?>
        <?= view('directory/_address_inputs', [
            'n'         => $n,
            'val'       => $val,
            'e'         => $e,
            'provinces' => $provinces,
            'listId'    => 'address-suggest-list-' . $i,
            // true, unlike the listing's: a branch has no pin picker to keep
            // inside the wrapper, so the partial can own it.
            'wrap'      => true,
        ]) ?>

        <?php // And the same seven-row hours grid. ?>
        <?= view('directory/_hours_inputs', [
            'n'     => $n,
            'hours' => is_array($loc['hours'] ?? null) ? $loc['hours'] : (hours_decode($loc['trading_hours'] ?? null) ?? []),
            'copy'  => false,
            'label' => 'Trading hours at this branch',
        ]) ?>
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

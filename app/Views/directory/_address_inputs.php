<?php
/**
 * The address block — address line, suburb, line 2, city, postal code, province.
 *
 * Extracted from _form_fields.php so a branch's address is edited by the same
 * inputs as the listing's own, differing only in the field-name prefix. Both
 * callers now get the full autocomplete: the hooks, the suggestion listbox and
 * the hidden coordinate inputs a picked suggestion writes into.
 *
 * $listId is the one thing that cannot be shared. The listbox needs a real id
 * for aria-controls, and every branch row renders one — so the caller supplies
 * a distinct id per row. A branch passes its row index, and the clone template
 * passes the literal '__i__', which the repeat script's global replace turns
 * into the new row's index along with every field name. Duplicate ids here
 * would be invalid HTML and would point every row's combobox at the first
 * row's listbox.
 *
 * $wrap decides whether this partial emits its own [data-address-autocomplete]
 * element. A branch wants one — it is the scope the script binds to. The
 * listing does not, because its pin picker has to live inside that same wrapper
 * and the picker is rendered by the caller, so the caller opens the wrapper
 * itself and this partial goes inside it.
 *
 * @var callable $n         fn(string $field): string — the input name
 * @var callable $val       fn(string $field): string — current value
 * @var callable $e         fn(string $field): string — error text, or ''
 * @var array    $provinces DirectoryService::SA_PROVINCES
 * @var string   $listId    unique id for this row's suggestion listbox
 * @var bool     $wrap      emit the [data-address-autocomplete] element here
 */
$listId = $listId ?? 'address-suggest-list';
$wrap   = $wrap ?? false;
?>
<?php if ($wrap): ?>
<div data-address-autocomplete data-suggest-url="<?= base_url('address-suggest') ?>">
<?php endif; ?>
    <div class="form-row">
        <div class="field relative">
            <label>Address</label>
            <input type="text" name="<?= $n('address_line') ?>" value="<?= esc($val('address_line'), 'attr') ?>" maxlength="255"
                   data-address-field="address_line" autocomplete="off"
                   role="combobox" aria-autocomplete="list" aria-expanded="false" aria-controls="<?= esc($listId, 'attr') ?>">
            <ul class="address-suggest-list" id="<?= esc($listId, 'attr') ?>" role="listbox" data-address-suggest-list hidden></ul>
            <?php if ($e('address_line')): ?><div class="err"><?= esc($e('address_line')) ?></div><?php endif; ?>
        </div>
        <div class="field">
            <label>Suburb</label>
            <input type="text" name="<?= $n('suburb') ?>" value="<?= esc($val('suburb'), 'attr') ?>" maxlength="120" data-address-field="suburb">
            <?php if ($e('suburb')): ?><div class="err"><?= esc($e('suburb')) ?></div><?php endif; ?>
        </div>
    </div>
    <?php // Unit, floor, building — detail that helps a customer find the door
          // but only confuses a geocoder, so it is stored and displayed and
          // deliberately left out of the lookup query. ?>
    <div class="field">
        <label>Address line 2 <span class="map-picker-optional">optional</span></label>
        <input type="text" name="<?= $n('address_line_2') ?>" value="<?= esc($val('address_line_2'), 'attr') ?>" maxlength="255"
               data-address-field="address_line_2" autocomplete="off"
               placeholder="Unit, floor, building">
        <?php if ($e('address_line_2')): ?><div class="err"><?= esc($e('address_line_2')) ?></div><?php endif; ?>
    </div>
    <div class="form-row">
        <div class="field">
            <label>City / town</label>
            <input type="text" name="<?= $n('city') ?>" value="<?= esc($val('city'), 'attr') ?>" maxlength="120" data-address-field="city">
            <?php if ($e('city')): ?><div class="err"><?= esc($e('city')) ?></div><?php endif; ?>
        </div>
        <div class="field">
            <label>Postal code</label>
            <input type="text" name="<?= $n('postal_code') ?>" value="<?= esc($val('postal_code'), 'attr') ?>"
                   inputmode="numeric" maxlength="4" data-address-field="postal_code">
            <?php if ($e('postal_code')): ?><div class="err"><?= esc($e('postal_code')) ?></div><?php endif; ?>
        </div>
    </div>
    <div class="field">
        <label>Province</label>
        <select name="<?= $n('province') ?>" data-address-field="province">
            <option value="">Choose&hellip;</option>
            <?php foreach ($provinces as $prov): ?>
                <option value="<?= esc($prov, 'attr') ?>" <?= $val('province') === $prov ? 'selected' : '' ?>><?= esc($prov) ?></option>
            <?php endforeach; ?>
        </select>
        <?php if ($e('province')): ?><div class="err"><?= esc($e('province')) ?></div><?php endif; ?>
    </div>

    <?php // Picking a suggestion captures the exact point the geocoder returned
          // for that entry, so the server can save it as-is instead of
          // re-deriving a pin from the address text — which is what fails for
          // the many South African suburbs no geocoder has heard of. The script
          // clears these the moment any address field is edited by hand, so a
          // stale pin can't survive an address change.
          //
          // A branch carries these too. PracticeLocationService hands the raw
          // row to the same ListingGeocoder, whose submittedCoords() reads them
          // straight off it — so a picked suggestion pins a branch without
          // spending a lookup on save. None of them are writable columns in
          // their own right: they reach the database only through that class,
          // which re-checks them against a South African bounding box. ?>
    <input type="hidden" name="<?= $n('latitude') ?>" value="<?= esc($val('latitude'), 'attr') ?>" data-address-coord="latitude">
    <input type="hidden" name="<?= $n('longitude') ?>" value="<?= esc($val('longitude'), 'attr') ?>" data-address-coord="longitude">
    <input type="hidden" name="<?= $n('geocode_precision') ?>" value="<?= esc($val('geocode_precision'), 'attr') ?>" data-address-coord="geocode_precision">
<?php if ($wrap): ?>
</div>
<?php endif; ?>

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
 * @var bool     $required  mark address, city, postal code and province as required
 * @var bool     $withCountry render the country select and the province/region
 *                            pair. The listing's own address does; a branch
 *                            row does not — a branch is another location of
 *                            the same business, so it inherits the country and
 *                            would only invite someone to contradict it.
 * @var array    $countries  Config\Countries::grouped(), required when $withCountry
 *
 * $required is false by default and every caller passes it explicitly, even
 * when false. Only public signup sets it: an existing profile must stay
 * editable, because most of the listings that predate the rule have no address
 * and their owners must still be able to fix a phone number. A branch row is
 * never required either — a listing's own address is the one we insist on.
 *
 * Passing it explicitly everywhere is not ceremony. CI4 renders the content
 * view before the layout and shares one data array, so an omitted variable
 * here inherits whatever the *other* caller on the page set, silently. That is
 * how the header search box once came back pre-filled with the hero's query.
 */
$listId      = $listId ?? 'address-suggest-list';
$wrap        = $wrap ?? false;
$required    = $required ?? false;
$withCountry = $withCountry ?? false;
$countries   = $countries ?? [];

// Owner edit passes true: country is not in OWNER_EDITABLE, so offering a
// select there would be a control that silently does nothing. Signup and the
// admin form pass false.
$lockCountry = $lockCountry ?? false;

// Which half of the province/region pair is live. The server decides it from
// the stored (or just-posted) country so the form is correct with JavaScript
// off; the script then swaps it live as the select changes.
$isLocal = ! $withCountry || trim($val('country')) === ''
    || trim($val('country')) === \Config\Countries::SOUTH_AFRICA;

// The server is the authority (DirectoryListingMutationService::validate()).
// These two only make the browser ask first, so nobody loses a filled-in form
// to a round trip.
$req  = $required ? ' required' : '';
$star = $required ? ' *' : '';

// Only Mapbox offers suggestions (Services::geocoder()). Without it there is
// no dropdown to credit and no suggest URL for the script to call.
$autocomplete = config('Directory')->addressAutocompleteEnabled();
?>
<?php if ($wrap): ?>
<div data-address-autocomplete
     <?php if ($autocomplete): ?>data-suggest-url="<?= base_url('address-suggest') ?>"<?php endif; ?>
     data-locate-url="<?= base_url('address-locate') ?>">
<?php endif; ?>
<?php if ($withCountry): ?>
    <?php // First, because it changes what the rest of the block asks for.
          //
          // Not owner-editable: updateOwn() ignores a posted country entirely
          // and keeps the stored one. It decides whether a listing needs an
          // International Listing subscription, and a field that decides that
          // cannot be writable by the person being charged. On the owner's
          // edit form this therefore renders as read-only text, not a select. ?>
    <div class="field">
        <label>Country<?= $star ?></label>
        <?php if (! empty($lockCountry)): ?>
            <input type="text" value="<?= esc($val('country') ?: \Config\Countries::SOUTH_AFRICA, 'attr') ?>" readonly disabled
                   class="bg-slate-100 text-slate-500 dark:bg-slate-800/60 dark:text-slate-400">
            <div class="hint">Contact us if your business has moved to another country.</div>
        <?php else: ?>
            <select name="<?= $n('country') ?>" data-address-field="country" data-country-select<?= $req ?>>
                <?php foreach ($countries as $group => $list): ?>
                    <?php if ($group !== ''): ?><optgroup label="<?= esc($group, 'attr') ?>"><?php endif; ?>
                    <?php foreach ($list as $c): ?>
                        <option value="<?= esc($c, 'attr') ?>" <?= ($val('country') ?: \Config\Countries::SOUTH_AFRICA) === $c ? 'selected' : '' ?>><?= esc($c) ?></option>
                    <?php endforeach; ?>
                    <?php if ($group !== ''): ?></optgroup><?php endif; ?>
                <?php endforeach; ?>
            </select>
        <?php endif; ?>
        <?php if ($e('country')): ?><div class="err"><?= esc($e('country')) ?></div><?php endif; ?>
    </div>
<?php endif; ?>
    <div class="form-row">
        <div class="field relative">
            <label>Address<?= $star ?></label>
            <input type="text" name="<?= $n('address_line') ?>" value="<?= esc($val('address_line'), 'attr') ?>" maxlength="255"
                   data-address-field="address_line" autocomplete="off"<?= $req ?>
                   role="combobox" aria-autocomplete="list" aria-expanded="false" aria-controls="<?= esc($listId, 'attr') ?>">
            <ul class="address-suggest-list" id="<?= esc($listId, 'attr') ?>" role="listbox" data-address-suggest-list hidden></ul>
            <?php if ($autocomplete): ?>
                <?php // Mapbox's terms ask for attribution wherever its results
                      // are shown off a Mapbox map. ?>
                <div class="hint address-suggest-credit">Start typing for suggestions. Address search by <a href="https://www.mapbox.com/about/maps/" target="_blank" rel="noopener">Mapbox</a>.</div>
            <?php endif; ?>
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
            <label>City / town<?= $star ?></label>
            <input type="text" name="<?= $n('city') ?>" value="<?= esc($val('city'), 'attr') ?>" maxlength="120" data-address-field="city"<?= $req ?>>
            <?php if ($e('city')): ?><div class="err"><?= esc($e('city')) ?></div><?php endif; ?>
        </div>
        <div class="field">
            <label>Postal code<?= $star ?></label>
            <?php // Four numeric digits is the South African shape, and the
                  // script relaxes both attributes for any other country.
                  // Rendered from the server's verdict so no-JS agrees. ?>
            <input type="text" name="<?= $n('postal_code') ?>" value="<?= esc($val('postal_code'), 'attr') ?>"
                   <?= $isLocal ? 'inputmode="numeric" maxlength="4"' : 'maxlength="20"' ?>
                   data-address-field="postal_code"<?= $req ?>>
            <?php if ($e('postal_code')): ?><div class="err"><?= esc($e('postal_code')) ?></div><?php endif; ?>
        </div>
    </div>
    <?php // Province and region are mutually exclusive, and the inactive one is
          // BOTH hidden and disabled — disabled so it posts nothing at all,
          // which is what keeps a South African listing from carrying a stale
          // "Bavaria" in region (and the reverse). Toggling `hidden` and
          // `disabled` as properties is also the only way to do this under the
          // enforcing CSP: style="" attributes are dropped by style-src-attr.
          //
          // Server-rendered from the country, so this is correct with no
          // JavaScript. Change the country with the script off and the pair
          // does not swap — the server then answers with a field error and the
          // re-rendered form shows the right half. One round trip, no data loss. ?>
    <div class="field" data-address-province <?= $isLocal ? '' : 'hidden' ?>>
        <label>Province<?= $star ?></label>
        <select name="<?= $n('province') ?>" data-address-field="province"<?= $req ?> <?= $isLocal ? '' : 'disabled' ?>>
            <option value="">Choose&hellip;</option>
            <?php foreach ($provinces as $prov): ?>
                <option value="<?= esc($prov, 'attr') ?>" <?= $val('province') === $prov ? 'selected' : '' ?>><?= esc($prov) ?></option>
            <?php endforeach; ?>
        </select>
        <?php if ($e('province')): ?><div class="err"><?= esc($e('province')) ?></div><?php endif; ?>
    </div>
<?php if ($withCountry): ?>
    <div class="field" data-address-region <?= $isLocal ? 'hidden' : '' ?>>
        <label>State / region<?= $star ?></label>
        <input type="text" name="<?= $n('region') ?>" value="<?= esc($val('region'), 'attr') ?>" maxlength="120"
               data-address-field="region"<?= $req ?> <?= $isLocal ? 'disabled' : '' ?>>
        <?php if ($e('region')): ?><div class="err"><?= esc($e('region')) ?></div><?php endif; ?>
    </div>
<?php endif; ?>

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

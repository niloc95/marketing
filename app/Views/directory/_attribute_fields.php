<?php

use App\Services\ServiceMenuService;

/**
 * "Features & amenities" — tick-boxes, inside the listing form.
 *
 * Three kinds of box share this panel, because to the person filling it in they
 * are all the same question ("what's true about your business?"):
 *
 *  1. The three capability columns on the listing itself, plus the booking link
 *     that implies one of them. Same names as before; the save paths are
 *     unchanged for these.
 *  2. The common features from Config\ListingAttributes, offered to everyone.
 *  3. One fieldset per category group. Only the chosen category's group is
 *     shown; the rest are `hidden` AND `disabled`. Disabled matters, not just
 *     hidden: several groups share keys (home_visits, free_quotes…), and an
 *     enabled hidden copy that is still ticked would keep submitting a feature
 *     the owner just unticked in the visible one. directory.js swaps the group
 *     when the category changes.
 *
 * With no category chosen yet (a fresh signup) every group renders enabled, so
 * the form still works with JavaScript off; the server keeps only the keys the
 * chosen category allows — see ServiceMenuService::allowedKeys().
 *
 * @var callable           $v          the form's value reader
 * @var callable           $err
 * @var array              $categories rows with id and group_name
 * @var array<int,string>  $selected   ticked feature keys (old input wins)
 */
$config   = config('ListingAttributes');
$selected = array_flip(array_filter(is_array($selected ?? null) ? $selected : [], 'is_string'));

$currentGroup = null;
foreach ($categories as $c) {
    if ((string) $c['id'] === (string) $v('category_id')) {
        $currentGroup = $c['group_name'] ?? null;
        break;
    }
}

$box = static function (string $key, string $label, string $idSuffix) use ($selected): string {
    $id = 'attr-' . $key . '-' . $idSuffix;

    return '<label class="attr-option" for="' . esc($id, 'attr') . '">'
        . '<input type="checkbox" id="' . esc($id, 'attr') . '" name="attributes[]" value="' . esc($key, 'attr') . '"'
        . (isset($selected[$key]) ? ' checked' : '') . '> '
        . esc($label)
        . '</label>';
};

$ticked = count($selected)
    + (int) (bool) $v('accepts_card_payments')
    + (int) (bool) $v('offers_delivery')
    + (int) (bool) $v('offers_online_booking');
$open = $ticked > 0 || $v('booking_url') !== '' || $err('booking_url') !== '';
?>
<details class="disclosure" <?= $open ? 'open' : '' ?> data-attributes>
    <summary class="disclosure-summary">
        <span>Features &amp; amenities</span>
        <span class="hint"><?= $ticked > 0 ? $ticked . ' ticked' : 'Parking, card payments, walk-ins…' ?></span>
    </summary>

    <div class="disclosure-body">
        <input type="hidden" name="<?= ServiceMenuService::ATTRIBUTES_MARKER ?>" value="1">
        <p class="hint">Tick everything that applies. Each one shows on your profile with a check mark.</p>

        <div class="attr-grid">
            <label class="attr-option"><input type="checkbox" name="accepts_card_payments" value="1" <?= $v('accepts_card_payments') ? 'checked' : '' ?>> Accepts card payments</label>
            <label class="attr-option"><input type="checkbox" name="offers_delivery" value="1" <?= $v('offers_delivery') ? 'checked' : '' ?>> Delivery / mobile service</label>
            <label class="attr-option"><input type="checkbox" name="offers_online_booking" value="1" <?= $v('offers_online_booking') ? 'checked' : '' ?>> Online booking</label>
            <?php foreach ($config->common as $key => $label): ?>
                <?= $box($key, $label, 'common') ?>
            <?php endforeach; ?>
        </div>

        <?php foreach ($config->byGroup as $group => $set): ?>
            <?php $off = $currentGroup !== null && $group !== $currentGroup; ?>
            <fieldset class="attr-group" data-attr-group="<?= esc($group, 'attr') ?>" <?= $off ? 'hidden disabled' : '' ?>>
                <legend><?= esc($group) ?></legend>
                <div class="attr-grid">
                    <?php foreach ($set as $key => $label): ?>
                        <?= $box($key, $label, substr(md5($group), 0, 6)) ?>
                    <?php endforeach; ?>
                </div>
            </fieldset>
        <?php endforeach; ?>

        <?php // Always visible rather than revealed by the checkbox, so it works with
              // JavaScript off. Filling it in ticks the box server-side anyway. ?>
        <div class="field mt-4">
            <label for="booking_url">Online booking page</label>
            <input type="text" id="booking_url" name="booking_url" value="<?= esc($v('booking_url'), 'attr') ?>" maxlength="255" placeholder="https://…">
            <div class="hint">Where customers book an appointment. Shown as a <em>Book online</em> button on your profile.</div>
            <?php if ($err('booking_url')): ?><div class="err"><?= esc($err('booking_url')) ?></div><?php endif; ?>
        </div>
    </div>
</details>

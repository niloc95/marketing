<?php

use App\Services\ListingFacetService;

/**
 * The structured facts panel inside the listing form — ages taken, curriculum,
 * grades offered, fees from; for a restaurant, how to order and meals served.
 *
 * Sibling of _attribute_fields.php and it copies that file's two hard-won
 * rules, because the failure modes are identical:
 *
 *  1. **One fieldset per facet SET, and only the chosen category's is shown;
 *     the rest are `hidden` AND `disabled`.** Disabled is the half that matters.
 *     Sets share facet keys — 'ages' and 'curriculum' are in both the school and
 *     the preschool set with different options — so an enabled hidden copy would
 *     keep submitting a value the owner just changed in the visible one, and the
 *     server would store whichever came last in the request.
 *  2. **With no category chosen yet every set renders enabled**, so a fresh
 *     signup works with JavaScript off; ListingFacetService::allowedFor() keeps
 *     only what the chosen category actually offers.
 *
 * Sets are keyed here by SET NAME rather than by category slug, unlike the
 * attributes panel's groups: fourteen education categories share three sets, and
 * rendering one fieldset per category would put the same inputs on the page
 * fourteen times over — which is failure mode 1 at scale.
 *
 * @var callable          $v          the form's value reader
 * @var callable          $err
 * @var array             $categories rows with id, slug and group_name
 * @var array<string,mixed> $facetValues stored rows keyed by facet (old input wins)
 */
$config = config('ListingFacets');

// Which set the chosen category draws from, '' when it has none, and null when
// no category has been chosen yet. The three are genuinely different states:
// null renders every set enabled (the JavaScript-off fallback that
// _attribute_fields.php uses for the same reason), '' renders none.
$current = null;
foreach ($categories as $c) {
    if ((string) $c['id'] === (string) $v('category_id')) {
        $current = facet_set_for($c['group_name'] ?? null, $c['slug'] ?? null);
        break;
    }
}

$stored = is_array($facetValues ?? null) ? $facetValues : [];

/** Values chosen for one facet, as a lookup. */
$chosen = static function (string $key) use ($stored): array {
    return array_flip(array_column($stored[$key] ?? [], 'value'));
};

$bound = static function (string $key, string $which) use ($stored): string {
    $n = $stored[$key][0][$which] ?? null;

    return $n === null ? '' : (string) $n;
};

$filled = 0;
foreach ($stored as $rows) {
    $filled += count($rows);
}
?>
<details class="disclosure" <?= $filled > 0 ? 'open' : '' ?> data-facets>
    <summary class="disclosure-summary">
        <?php // Generic on purpose: the same panel carries a school's ages and fees
              // and a restaurant's takeaway and meal times. ?>
        <span>Details visitors filter on</span>
        <span class="hint"><?= $filled > 0 ? 'Filled in' : 'Helps customers find you' ?></span>
    </summary>

    <div class="disclosure-body">
        <input type="hidden" name="<?= ListingFacetService::FACETS_MARKER ?>" value="1">
        <p class="hint">
            These are what visitors filter on, so a listing that fills them in is found by
            far more searches. Free, like everything else here. Leave anything you are
            unsure of empty rather than guessing.
        </p>

        <?php foreach ($config->sets as $setName => $set): ?>
            <?php $off = $current !== null && $setName !== $current; ?>
            <fieldset class="facet-set" data-facet-set="<?= esc($setName, 'attr') ?>" <?= $off ? 'hidden disabled' : '' ?>>
                <?php foreach ($set as $key => $facet): ?>
                    <?php $id = 'facet-' . $setName . '-' . $key; ?>
                    <div class="field">
                        <label for="<?= esc($id, 'attr') ?>"><?= esc($facet['label']) ?></label>

                        <?php if ($facet['type'] === 'range'): ?>
                            <div class="facet-range">
                                <input type="number" inputmode="numeric" id="<?= esc($id, 'attr') ?>"
                                       name="facets[<?= esc($key, 'attr') ?>][low]"
                                       value="<?= esc($bound($key, 'num_low'), 'attr') ?>"
                                       min="<?= (int) $facet['min'] ?>" max="<?= (int) $facet['max'] ?>"
                                       placeholder="<?= empty($facet['single']) ? 'From' : 'Amount' ?>">
                                <?php if (empty($facet['single'])): ?>
                                    <span aria-hidden="true">–</span>
                                    <input type="number" inputmode="numeric"
                                           name="facets[<?= esc($key, 'attr') ?>][high]"
                                           value="<?= esc($bound($key, 'num_high'), 'attr') ?>"
                                           min="<?= (int) $facet['min'] ?>" max="<?= (int) $facet['max'] ?>"
                                           placeholder="To">
                                <?php endif; ?>
                                <span class="hint"><?= $facet['unit'] === 'months' ? 'months' : ($facet['unit'] === 'rand-hour' ? 'rand an hour' : 'rand a month') ?></span>
                            </div>

                        <?php elseif ($facet['type'] === 'one'): ?>
                            <?php $picked = $chosen($key); ?>
                            <div class="attr-grid">
                                <?php foreach ($facet['options'] as $value => $label): ?>
                                    <label class="attr-option">
                                        <input type="radio" name="facets[<?= esc($key, 'attr') ?>]"
                                               value="<?= esc($value, 'attr') ?>" <?= isset($picked[$value]) ? 'checked' : '' ?>>
                                        <?= esc($label) ?>
                                    </label>
                                <?php endforeach; ?>
                                <?php // Radios cannot be un-picked once one is chosen, and an
                                      // owner who ticked the wrong one must be able to take it
                                      // back without the answer sticking for good. ?>
                                <label class="attr-option">
                                    <input type="radio" name="facets[<?= esc($key, 'attr') ?>]" value=""
                                        <?= $picked === [] ? 'checked' : '' ?>>
                                    Rather not say
                                </label>
                            </div>

                        <?php else: ?>
                            <?php $picked = $chosen($key); ?>
                            <div class="attr-grid">
                                <?php foreach ($facet['options'] as $value => $label): ?>
                                    <label class="attr-option">
                                        <input type="checkbox" name="facets[<?= esc($key, 'attr') ?>][]"
                                               value="<?= esc($value, 'attr') ?>" <?= isset($picked[$value]) ? 'checked' : '' ?>>
                                        <?= esc($label) ?>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>

                        <?php if (! empty($facet['hint'])): ?>
                            <div class="hint"><?= esc($facet['hint']) ?></div>
                        <?php endif; ?>
                        <?php if ($err('facets.' . $key)): ?>
                            <div class="err"><?= esc($err('facets.' . $key)) ?></div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </fieldset>
        <?php endforeach; ?>

        <?php // Shown when the chosen category asks nothing — most of the site —
              // so the panel is never a bordered box with nothing in it. ?>
        <p class="hint" data-facet-empty <?= $current === '' ? '' : 'hidden' ?>>
            There are no extra questions for this category yet. Everything about this
            business goes in the sections above.
        </p>
    </div>
</details>

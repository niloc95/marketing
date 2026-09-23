<?php
/**
 * The filter sidebar on a search-results page — "Ages, curriculum, grades".
 *
 * Renders only inside a category. An unscoped /directory has nothing sensible
 * to offer: "IEB" is not a question you can ask of every business in the
 * country, and a rail of filters that apply to fourteen categories out of a
 * hundred and seventy is noise on every other page. Yelp does the same thing
 * for the same reason — category filters appear once you are in a category.
 * The early return here and listing_has_facet_rail(), which the two results
 * views ask before laying themselves out in two columns, are the same test.
 *
 * **Built to match _facet_fields.php, the same questions on the listing form.**
 * Same .panel surface as .form-card, same .field blocks, same .attr-grid of
 * .attr-option tick-boxes, same heading size. Two renderings of one set of
 * questions that look like two different products is what this replaces. The
 * one deliberate difference is fieldset/legend instead of the form's <label>:
 * a label heading a group of checkboxes points at nothing, so the legend is the
 * correct element and .facet-field legend is styled to .field label's values.
 *
 * Plain checkboxes and number inputs inside a GET form, so it works with
 * JavaScript off. Values round-trip as ?f[key][]=value, which is why the option
 * keys in Config\ListingFacets are treated as slugs.
 *
 * **Rendered once per page, never twice.** The option ids below are global, so
 * a second copy — a mobile drawer beside a desktop sidebar, say — would mint
 * duplicate ids and labels that tick the wrong box. It is one element that
 * restyles at the breakpoint: a sidebar from lg up, a collapsed panel above the
 * results below it.
 *
 * **No counts.** A count beside each option is a query per option — twenty-odd
 * extra queries on the most expensive public read in the app — and the number
 * is wrong the moment any other filter changes. Revisit with one grouped query
 * if it proves worth it.
 *
 * @var array  $category the resolved category row, never null here
 * @var array  $facets   what sanitiseFacets() honoured: key => values|int
 * @var string $action   where the form posts
 * @var array  $carry    other filters to carry as hidden inputs
 */
$offered = config('ListingFacets')->filterableFor($category['group_name'] ?? null, $category['slug'] ?? null);
if ($offered === []) {
    return;
}

$chosen = static function (string $key) use ($facets): array {
    $v = $facets[$key] ?? [];

    return is_array($v) ? array_flip($v) : [];
};

$applied = count($facets);
?>
<?php // `open` in the markup, always: with JavaScript off this must be usable at
      // every width, and from lg up the summary is hidden because the sidebar is
      // always shown. directory.js is what collapses it on a phone. ?>
<details class="panel results-filter-panel" open data-facet-rail>
    <summary class="results-filter-summary">
        <span>Narrow these results</span>
        <span class="hint"><?= $applied === 0
            ? 'Ages, curriculum, fees'
            : $applied . ' filter' . ($applied === 1 ? '' : 's') . ' applied' ?></span>
    </summary>

    <form method="get" action="<?= esc($action, 'attr') ?>">
        <?php // Everything the sidebar is not itself responsible for. Without these
              // a narrowed search silently loses its province or its near-me
              // position the first time someone ticks a box. ?>
        <?php foreach ($carry as $name => $value): ?>
            <?php if ($value !== '' && $value !== null): ?>
                <input type="hidden" name="<?= esc((string) $name, 'attr') ?>" value="<?= esc((string) $value, 'attr') ?>">
            <?php endif; ?>
        <?php endforeach; ?>

        <?php foreach ($offered as $key => $facet): ?>
            <fieldset class="field facet-field">
                <legend><?= esc($facet['label']) ?></legend>

                <?php if ($facet['type'] === 'range'): ?>
                    <?php // One number, not two: a visitor is asking "does it take
                          // my six-year-old", not "show me schools whose range is
                          // 5 to 7". applyFacets() turns it into an overlap test. ?>
                    <input type="number" inputmode="numeric"
                           name="f[<?= esc($key, 'attr') ?>]"
                           value="<?= esc((string) ($facets[$key] ?? ''), 'attr') ?>"
                           min="<?= (int) $facet['min'] ?>" max="<?= (int) $facet['max'] ?>"
                           placeholder="<?= $facet['unit'] === 'months' ? 'Age in months' : 'Rand' ?>">
                    <div class="hint"><?= $facet['unit'] === 'months'
                        ? 'e.g. 36 for a three-year-old'
                        : 'Shows anyone starting at or below this' ?></div>

                <?php else: ?>
                    <?php $picked = $chosen($key); ?>
                    <div class="attr-grid">
                        <?php foreach ($facet['options'] as $value => $label): ?>
                            <?php $id = 'ff-' . $key . '-' . $value; ?>
                            <label class="attr-option" for="<?= esc($id, 'attr') ?>">
                                <input type="checkbox" id="<?= esc($id, 'attr') ?>"
                                       name="f[<?= esc($key, 'attr') ?>][]"
                                       value="<?= esc((string) $value, 'attr') ?>"
                                       <?= isset($picked[$value]) ? 'checked' : '' ?>>
                                <span><?= esc($label) ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </fieldset>
        <?php endforeach; ?>

        <div class="results-filter-actions">
            <button type="submit" class="btn btn-primary btn-xs">Apply</button>
            <?php if ($facets !== []): ?>
                <a class="btn btn-ghost btn-xs" href="<?= esc($action . ($carry ? '?' . http_build_query(array_filter($carry, static fn ($v): bool => $v !== '' && $v !== null)) : ''), 'attr') ?>">Clear</a>
            <?php endif; ?>
        </div>
    </form>
</details>

<?php
/**
 * "Sort: Best match | Newest" above a results list — Yelp's "New Restaurants",
 * for every category.
 *
 * Plain links, not a form: two orders is a choice between two URLs, and a link
 * works with JavaScript off. Both links drop ?page, because page 3 of one order
 * is an arbitrary slice of the other.
 *
 * The chips reuse .near-chip so the row sits beside the radius chips as one
 * family rather than a second pill style.
 *
 * Callers MUST pass ['saveData' => false], for the reason _chip.php gives.
 *
 * @var string              $base  the page's own URL, without a query string
 * @var array<string,mixed> $query every other parameter to keep (facets as 'f')
 * @var string              $sort  '' or 'new'
 */
$keep = array_filter($query, static fn ($v): bool => $v !== '' && $v !== null && $v !== []);
unset($keep['sort'], $keep['page']);

$href = static function (string $sort) use ($base, $keep): string {
    $q = $sort === '' ? $keep : $keep + ['sort' => $sort];

    return $base . ($q === [] ? '' : '?' . http_build_query($q));
};

$options = ['' => 'Best match', 'new' => 'Newest'];
?>
<div class="sort-toggle" role="group" aria-label="Sort results">
    <span class="near-bar-label">Sort</span>
    <?php foreach ($options as $value => $label): ?>
        <?php if ($value === $sort): ?>
            <span class="near-chip is-active" aria-current="true"><?= esc($label) ?></span>
        <?php else: ?>
            <a class="near-chip" href="<?= esc($href($value), 'attr') ?>" rel="nofollow"><?= esc($label) ?></a>
        <?php endif; ?>
    <?php endforeach; ?>
</div>

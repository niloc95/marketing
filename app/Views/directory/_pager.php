<?php
/**
 * The pager under every list of business profiles: /directory, category
 * landings, provinces, countries and venues. It replaces five copies of a loop
 * that printed one link per page, which was 161 links at 1,924 profiles.
 *
 * It shows a "Showing 13 to 24 of 1,924" line, Previous and Next, and the
 * pages pager_window() picks. Phones get Previous, "Page 5 of 161" and Next
 * only. Past seven pages a Go to page form jumps straight to any page.
 *
 * Callers pass ['saveData' => false] as view()'s third argument, as for
 * _chip.php, so one page's pager never inherits another's query.
 *
 * @var array  $result  browse() output: page, perPage, total, totalPages
 * @var string $baseUrl the page's own URL, no query string
 * @var array  $query   everything but page that has to survive paging (facets
 *                      nest as f[...][], which http_build_query keeps intact)
 */
$totalPages = (int) $result['totalPages'];
if ($totalPages <= 1) {
    return;
}

$page    = min(max(1, (int) $result['page']), $totalPages);
$perPage = (int) $result['perPage'];
$total   = (int) $result['total'];
$query   = array_filter($query ?? [], static fn ($v): bool => $v !== '' && $v !== null && $v !== []);
unset($query['page']);

// Page 1 has no ?page=1, so its URL is the same one every other link on the
// site uses for it.
$href = static function (int $n) use ($baseUrl, $query): string {
    $params = $n > 1 ? $query + ['page' => $n] : $query;

    return $params === [] ? $baseUrl : $baseUrl . '?' . http_build_query($params);
};

$first = ($page - 1) * $perPage + 1;
$last  = min($page * $perPage, $total);
?>
<nav class="pager" aria-label="Pages">
    <p class="pager-summary">
        Showing <b><?= number_format($first) ?></b> to <b><?= number_format($last) ?></b>
        of <b><?= number_format($total) ?></b> business profiles
    </p>

    <div class="pager-controls">
        <?php if ($page > 1): ?>
            <a class="pager-step" href="<?= esc($href($page - 1)) ?>" rel="prev"><?= lucide('chevron-left', 'h-4 w-4') ?><span>Previous</span></a>
        <?php else: ?>
            <span class="pager-step" aria-disabled="true"><?= lucide('chevron-left', 'h-4 w-4') ?><span>Previous</span></span>
        <?php endif; ?>

        <span class="pager-status">Page <?= number_format($page) ?> of <?= number_format($totalPages) ?></span>

        <ol class="pager-pages">
            <?php foreach (pager_window($page, $totalPages) as $n): ?>
                <li>
                    <?php if ($n === '…'): ?>
                        <span class="pager-gap" aria-hidden="true">…</span>
                    <?php elseif ($n === $page): ?>
                        <span class="pager-page current" aria-current="page"><?= number_format($n) ?></span>
                    <?php else: ?>
                        <a class="pager-page" href="<?= esc($href($n)) ?>" aria-label="Page <?= $n ?>"><?= number_format($n) ?></a>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ol>

        <?php if ($page < $totalPages): ?>
            <a class="pager-step" href="<?= esc($href($page + 1)) ?>" rel="next"><span>Next</span><?= lucide('chevron-right', 'h-4 w-4') ?></a>
        <?php else: ?>
            <span class="pager-step" aria-disabled="true"><span>Next</span><?= lucide('chevron-right', 'h-4 w-4') ?></span>
        <?php endif; ?>
    </div>

    <?php if ($totalPages > 7): ?>
        <?php // A plain GET form, so it works without JS. The rest of the query
              // rides along as hidden fields, flattened from the same
              // http_build_query the links use so f[...][] facets survive. ?>
        <form class="pager-jump" action="<?= esc($baseUrl) ?>" method="get">
            <?php foreach ($query === [] ? [] : explode('&', http_build_query($query)) as $pair): ?>
                <?php [$k, $v] = array_map('urldecode', explode('=', $pair, 2) + [1 => '']); ?>
                <input type="hidden" name="<?= esc($k) ?>" value="<?= esc($v) ?>">
            <?php endforeach; ?>
            <label for="pager-jump-page">Go to page</label>
            <input id="pager-jump-page" type="number" name="page" min="1" max="<?= $totalPages ?>" value="<?= $page ?>" inputmode="numeric" required>
            <button type="submit">Go</button>
        </form>
    <?php endif; ?>
</nav>

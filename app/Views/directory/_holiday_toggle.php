<?php
/**
 * "Open on public holidays" above a results list: one chip that adds or
 * removes ?holidays=1 (DirectoryService::applyHolidays()). A link, like the
 * sort toggle beside it, so it works with JavaScript off. On a public holiday
 * it reads "Open today" with the holiday's name, because that is what it
 * filters on then.
 *
 * Callers MUST pass ['saveData' => false], for the reason _chip.php gives.
 *
 * @var string              $base  the page's own URL, without a query string
 * @var array<string,mixed> $query every other parameter to keep (facets as 'f')
 * @var bool                $on    the filter is applied
 */
helper('directory_hours');

$keep = array_filter($query, static fn ($v): bool => $v !== '' && $v !== null && $v !== []);
unset($keep['holidays'], $keep['page']);
$q    = $on ? $keep : $keep + ['holidays' => '1'];
$href = $base . ($q === [] ? '' : '?' . http_build_query($q));

$today = hours_calendar()->on(hours_now()->format('Y-m-d'));
$label = $today !== null ? 'Open today, ' . App\Libraries\PublicHolidays::label($today) : 'Open on public holidays';
?>
<a class="near-chip<?= $on ? ' is-active' : '' ?>" href="<?= esc($href) ?>" rel="nofollow"<?= $on ? ' aria-pressed="true"' : ' aria-pressed="false"' ?>>
    <?= lucide($on ? 'check' : 'calendar', 'h-3.5 w-3.5 shrink-0') ?><?= esc($label) ?>
</a>

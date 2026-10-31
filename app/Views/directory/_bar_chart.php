<?php

/**
 * A one series bar chart as inline SVG: the owner's views by month, and the
 * admin stats page. Classes only, never style="" (CSP).
 *
 * Bars stand on a zero baseline, rounded at the top only. A <title> per bar is
 * the hover, and the table under the chart is the same data for anyone who
 * cannot see it.
 *
 * @var list<array{label:string,value:int,title:string}> $bars  label '' leaves the axis blank there
 * @var string                                             $name  what the bars count, for the aria label
 * @var list<string>                                       $columns table headings: first column, then one per extra
 * @var list<list<string>>                                 $rows    table rows, already formatted
 */
$bars = $bars ?? [];
$w    = 600;
$h    = 160;
$base = 136;
$top  = 16;
$max  = max(1, ...array_map(static fn (array $b): int => (int) $b['value'], $bars ?: [['value' => 0]]));
$slot = $w / max(1, count($bars));
$bar  = min(36.0, $slot * 0.6);
$n    = static fn (float $v): string => (string) round($v, 1);
?>
<svg class="stats-chart" viewBox="0 0 <?= $w ?> <?= $h ?>" role="img"
     aria-label="<?= esc($name . ': ' . implode(', ', array_map(static fn (array $b): string => $b['title'], $bars)), 'attr') ?>">
    <line class="stats-chart-axis" x1="0" y1="<?= $base ?>" x2="<?= $w ?>" y2="<?= $base ?>"></line>
    <?php foreach ($bars as $i => $b): ?>
        <?php
        $value = (int) $b['value'];
        $x     = $i * $slot + ($slot - $bar) / 2;
        $bh    = $value > 0 ? max(4.0, ($base - $top) * $value / $max) : 0;
        $y     = $base - $bh;
        $r     = min(4.0, $bh / 2);
        ?>
        <g>
            <title><?= esc($b['title']) ?></title>
            <rect class="stats-chart-hit" x="<?= $n($i * $slot) ?>" y="0" width="<?= $n($slot) ?>" height="<?= $base ?>"></rect>
            <?php if ($bh > 0): ?>
                <path class="stats-chart-bar" d="M<?= $n($x) ?>,<?= $base ?> V<?= $n($y + $r) ?> Q<?= $n($x) ?>,<?= $n($y) ?> <?= $n($x + $r) ?>,<?= $n($y) ?> H<?= $n($x + $bar - $r) ?> Q<?= $n($x + $bar) ?>,<?= $n($y) ?> <?= $n($x + $bar) ?>,<?= $n($y + $r) ?> V<?= $base ?> Z"></path>
            <?php endif; ?>
            <?php if ($b['label'] !== ''): ?>
                <text class="stats-chart-label" x="<?= $n($i * $slot + $slot / 2) ?>" y="<?= $h - 6 ?>" text-anchor="middle"><?= esc($b['label']) ?></text>
            <?php endif; ?>
        </g>
    <?php endforeach; ?>
</svg>
<details class="stats-table-toggle">
    <summary>Show as a table</summary>
    <table class="stats-table">
        <thead><tr><?php foreach ($columns as $c): ?><th scope="col"><?= esc($c) ?></th><?php endforeach; ?></tr></thead>
        <tbody>
            <?php foreach ($rows as $row): ?>
                <tr>
                    <?php foreach ($row as $i => $cell): ?>
                        <?php if ($i === 0): ?><th scope="row"><?= esc($cell) ?></th><?php else: ?><td><?= esc($cell) ?></td><?php endif; ?>
                    <?php endforeach; ?>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</details>

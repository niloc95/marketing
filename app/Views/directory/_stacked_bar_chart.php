<?php

/**
 * _bar_chart.php with two or more series stacked in each bar: the admin
 * dashboard's signups (free and paid) and form submissions (saved and not).
 * Same rules: classes only, never style="" (CSP); a <title> per bar is the
 * hover; the table under the chart is the same data; axis labels are HTML
 * because the SVG stretches.
 *
 * The first series sits on the baseline. Series colours are the .chart-s1…
 * classes ($colour, two so far), given in a fixed order and never by rank, with a legend above the
 * chart so colour is never the only way to tell them apart. A 2 unit gap
 * separates the segments, and only the top segment is rounded.
 *
 * @var list<array{key:string,label:string}>                            $series bottom first; colour follows position
 * @var list<array{label:string,values:array<string,int>,title:string}> $bars   label '' leaves the axis blank there
 * @var string                                                          $name   what the bars count, for the aria label
 * @var list<string>                                                    $columns table headings
 * @var list<list<string>>                                              $rows    table rows, already formatted
 */
$bars = $bars ?? [];
$w    = 600;
$h    = 120;
$base = 120;
$top  = 8;
$gap  = 2;
$max  = 1;
foreach ($bars as $b) {
    $max = max($max, array_sum(array_map('intval', $b['values'])));
}
$slot = $w / max(1, count($bars));
$bar  = min(36.0, $slot * 0.6);
$n    = static fn (float $v): string => (string) round($v, 1);
// Written out in full: Tailwind purges a class name it only sees concatenated.
$colour = ['chart-s1', 'chart-s2'];
?>
<ul class="chart-legend">
    <?php foreach ($series as $i => $s): ?>
        <li><span class="chart-key <?= $colour[$i] ?>" aria-hidden="true"></span><?= esc($s['label']) ?></li>
    <?php endforeach; ?>
</ul>
<svg class="stats-chart" viewBox="0 0 <?= $w ?> <?= $h ?>" preserveAspectRatio="none" role="img"
     aria-label="<?= esc($name . ': ' . implode(', ', array_map(static fn (array $b): string => $b['title'], $bars)), 'attr') ?>">
    <line class="stats-chart-axis" vector-effect="non-scaling-stroke" x1="0" y1="<?= $base ?>" x2="<?= $w ?>" y2="<?= $base ?>"></line>
    <?php foreach ($bars as $i => $b): ?>
        <?php
        $x = $i * $slot + ($slot - $bar) / 2;
        // Heights first, so the last non-empty segment knows it is the top.
        $parts = [];
        foreach ($series as $si => $s) {
            $value = (int) ($b['values'][$s['key']] ?? 0);
            if ($value > 0) {
                $parts[] = [$si, max(3.0, ($base - $top) * $value / $max)];
            }
        }
        ?>
        <g class="chart-stack">
            <title><?= esc($b['title']) ?></title>
            <rect class="stats-chart-hit" x="<?= $n($i * $slot) ?>" y="0" width="<?= $n($slot) ?>" height="<?= $base ?>"></rect>
            <?php $y0 = $base; ?>
            <?php foreach ($parts as $pi => [$si, $sh]): ?>
                <?php
                $isTop = $pi === count($parts) - 1;
                $y1    = $y0 - $sh;
                // The gap comes off the top of every segment but the last.
                $yTop  = $isTop ? $y1 : $y1 + $gap;
                $r     = $isTop ? min(4.0, ($y0 - $yTop) / 2, $bar / 2) : 0.0;
                ?>
                <path class="<?= $colour[$si] ?>" d="M<?= $n($x) ?>,<?= $n($y0) ?> V<?= $n($yTop + $r) ?> Q<?= $n($x) ?>,<?= $n($yTop) ?> <?= $n($x + $r) ?>,<?= $n($yTop) ?> H<?= $n($x + $bar - $r) ?> Q<?= $n($x + $bar) ?>,<?= $n($yTop) ?> <?= $n($x + $bar) ?>,<?= $n($yTop + $r) ?> V<?= $n($y0) ?> Z"></path>
                <?php $y0 = $y1; ?>
            <?php endforeach; ?>
        </g>
    <?php endforeach; ?>
</svg>
<div class="stats-chart-labels" aria-hidden="true">
    <?php foreach ($bars as $b): ?><span><?= esc($b['label']) ?></span><?php endforeach; ?>
</div>
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

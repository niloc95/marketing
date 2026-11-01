<?php

/**
 * Horizontal bars, one per row, for a handful of counts that share a scale:
 * setup orders by status, badge applications by state on the admin
 * dashboard. The label and the number are HTML, so the number is always
 * readable; the bar is a one rect SVG (classes only, CSP), stretched to the
 * row.
 *
 * @var list<array{label:string,value:int,href?:string}> $meters
 * @var string                                           $name   what the rows count, for the list's aria label
 */
$max = max(1, ...array_map(static fn (array $m): int => (int) $m['value'], $meters ?: [['value' => 0]]));
?>
<ul class="meter-rows" aria-label="<?= esc($name, 'attr') ?>">
    <?php foreach ($meters as $m): ?>
        <?php $pct = round(100 * (int) $m['value'] / $max, 1); ?>
        <li class="meter-row">
            <?php if (! empty($m['href'])): ?>
                <a class="meter-label" href="<?= esc($m['href']) ?>"><?= esc($m['label']) ?></a>
            <?php else: ?>
                <span class="meter-label"><?= esc($m['label']) ?></span>
            <?php endif; ?>
            <svg class="meter-track" viewBox="0 0 100 8" preserveAspectRatio="none" aria-hidden="true">
                <rect class="meter-rail" x="0" y="0" width="100" height="8"></rect>
                <?php if ($pct > 0): ?><rect class="stats-chart-bar" x="0" y="0" width="<?= max(1, $pct) ?>" height="8"></rect><?php endif; ?>
            </svg>
            <span class="meter-value"><?= number_format((int) $m['value']) ?></span>
        </li>
    <?php endforeach; ?>
</ul>

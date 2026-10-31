<?php

/**
 * Profile statistics: how often the profile is seen and contacted, and what
 * the numbers say is holding it back. Data from ListingStatsService::forOwner().
 *
 * Every owner sees the last 30 days and the tips. Verified (co.za) and Plus
 * (.app) owners, and the admin, also see the trend, taps by kind, the words
 * people discovered the profile with and its Google search numbers.
 *
 * Rules: the tips never suggest anything paid (rule 1 in _strength_panel.php);
 * the one line about the full view names the plan and links to its page but
 * never a price, so .app shows no rand figure; the chart is _bar_chart.php.
 *
 * @var array<string,mixed> $stats
 * @var string              $audience 'owner' (default) or 'admin'
 * @var string              $upgradeUrl  the plan page for a free owner, '' for none
 * @var string              $upgradeName 'Verified' or 'Plus'
 */
$audience    = ($audience ?? 'owner') === 'admin' ? 'admin' : 'owner';
$upgradeUrl  = (string) ($upgradeUrl ?? '');
$upgradeName = (string) ($upgradeName ?? '');
$current     = $stats['current'];
$previous    = $stats['previous'];
$counted     = (int) $stats['counted'];
$your        = $audience === 'admin' ? 'This' : 'Your';

$change = static function (int $now, ?int $before): string {
    if ($before === null) {
        return '';
    }
    if ($before === 0) {
        return $now > 0 ? 'New in the last 30 days' : '';
    }
    $pct = (int) round(($now - $before) * 100 / $before);

    return $pct === 0 ? 'Same as the 30 days before' : ($pct > 0 ? 'Up ' : 'Down ') . abs($pct) . '% on the 30 days before';
};
$tiles = [
    ['Profile views', $current['views'], $previous['views'] ?? null, 'Times someone opened the profile page.'],
    ['Shown in discovery results', $current['appearances'], $previous['appearances'] ?? null, 'Times the profile appeared in discovery results, category and area pages.'],
    ['Taps to get in touch', $current['taps'], $previous['taps'] ?? null, 'Taps on call, WhatsApp, website, directions, booking and email.'],
];
$kindLabels = [
    'call'       => 'Call',
    'whatsapp'   => 'WhatsApp',
    'website'    => 'Website',
    'directions' => 'Directions',
    'booking'    => 'Booking',
    'email'      => 'Email shown',
];
$date = static fn (string $d): string => date('j M Y', strtotime($d));
?>
<div class="panel stats-panel" id="profile-stats">
    <div class="mb-1 flex flex-wrap items-baseline justify-between gap-2">
        <h2 class="strength-panel-title"><?= $your ?> profile in the last 30 days</h2>
        <?php if ($stats['full']): ?>
            <span class="text-xs font-semibold text-emerald-700 dark:text-emerald-300">Full statistics</span>
        <?php endif; ?>
    </div>

    <?php if ($counted < \App\Services\ListingStatsService::PERIOD_DAYS): ?>
        <p class="strength-panel-note">
            <?php if ($stats['since'] === null): ?>
                Counting starts with the first visit, so the numbers fill in over the coming days.
            <?php else: ?>
                We started counting on <?= esc($date($stats['since'])) ?>, so this covers <?= $counted ?> day<?= $counted === 1 ? '' : 's' ?> so far.
            <?php endif; ?>
        </p>
    <?php endif; ?>

    <dl class="stats-tiles">
        <?php foreach ($tiles as [$label, $value, $before, $help]): ?>
            <div class="stats-tile">
                <dt class="stats-tile-label"><?= esc($label) ?></dt>
                <dd class="stats-tile-value"><?= number_format((int) $value) ?></dd>
                <?php $line = $change((int) $value, $before === null ? null : (int) $before); ?>
                <dd class="stats-tile-change"><?= esc($line !== '' ? $line : $help) ?></dd>
            </div>
        <?php endforeach; ?>
    </dl>

    <?php if ($stats['tips'] !== []): ?>
        <h3 class="stats-heading">What is holding <?= $audience === 'admin' ? 'it' : 'you' ?> back</h3>
        <ul class="stats-tips">
            <?php foreach ($stats['tips'] as $tip): ?>
                <li class="stats-tip" data-tip="<?= esc($tip['key'], 'attr') ?>">
                    <p class="stats-tip-title"><?= esc($tip['title']) ?></p>
                    <p class="stats-tip-text"><?= esc($tip['text']) ?></p>
                    <p class="stats-tip-steps">
                        <?= $audience === 'admin' ? 'Missing:' : 'Add:' ?>
                        <?php foreach ($tip['steps'] as $i => $step): ?>
                            <?= $i > 0 ? '&middot;' : '' ?>
                            <?php if ($audience === 'admin'): ?>
                                <?= esc($step['label']) ?>
                            <?php else: ?>
                                <a class="strength-step-link" href="#<?= esc($step['anchor'], 'attr') ?>"><?= esc($step['label']) ?></a>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </p>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php elseif ($counted >= \App\Services\ListingStatsService::TIPS_AFTER_DAYS): ?>
        <p class="strength-panel-lead">Nothing in the numbers stands out. Keep the profile up to date and it keeps working.</p>
    <?php endif; ?>

    <?php if ($stats['full']): ?>
        <?php $months = $stats['months']; ?>
        <?php if (count($months) >= 2): ?>
            <h3 class="stats-heading">Profile views by month</h3>
            <?= view('directory/_bar_chart', [
                'name'    => 'Profile views by month',
                'bars'    => array_map(static fn (array $m): array => [
                    'label' => date('M', strtotime($m['month'])),
                    'value' => $m['views'],
                    'title' => date('F Y', strtotime($m['month'])) . ': ' . number_format($m['views']) . ' views, ' . number_format($m['taps']) . ' taps',
                ], $months),
                'columns' => ['Month', 'Views', 'Shown', 'Taps'],
                'rows'    => array_map(static fn (array $m): array => [
                    date('F Y', strtotime($m['month'])), number_format($m['views']), number_format($m['appearances']), number_format($m['taps']),
                ], $months),
            ]) ?>
        <?php endif; ?>

        <div class="stats-columns">
            <div>
                <h3 class="stats-heading">How people got in touch</h3>
                <?php if (array_sum($stats['byKind']) === 0): ?>
                    <p class="stats-empty">No taps yet in the last 30 days.</p>
                <?php else: ?>
                    <ul class="stats-list">
                        <?php foreach ($stats['byKind'] as $kind => $n): ?>
                            <?php if ($n > 0): ?>
                                <li><span><?= esc($kindLabels[$kind] ?? $kind) ?></span><span class="stats-list-n"><?= number_format($n) ?></span></li>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
            <div>
                <h3 class="stats-heading">Words people discovered <?= $audience === 'admin' ? 'it' : 'you' ?> with</h3>
                <?php if ($stats['words'] === []): ?>
                    <p class="stats-empty">Nobody has opened the profile from Discover in the last 90 days yet.</p>
                <?php else: ?>
                    <ul class="stats-list">
                        <?php foreach ($stats['words'] as $word): ?>
                            <li><span><?= esc($word['query']) ?></span><span class="stats-list-n"><?= number_format($word['opens']) ?></span></li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </div>

        <h3 class="stats-heading">On Google search</h3>
        <?php $g = $stats['google']; ?>
        <?php if ($g === null || ($g['impressions'] === 0 && $g['queries'] === [])): ?>
            <p class="stats-empty">No Google search numbers for this profile yet. Google takes a few weeks to show a new page in its results.</p>
        <?php else: ?>
            <p class="strength-panel-lead">
                <?= esc($date($g['from'])) ?> to <?= esc($date($g['to'])) ?>:
                shown <strong><?= number_format($g['impressions']) ?></strong> times,
                clicked <strong><?= number_format($g['clicks']) ?></strong> times<?php if ($g['position'] !== null): ?>,
                average position <strong><?= esc(number_format((float) $g['position'], 1)) ?></strong><?php endif; ?>.
            </p>
            <?php if ($g['queries'] !== []): ?>
                <ul class="stats-list">
                    <?php foreach ($g['queries'] as $q): ?>
                        <li><span><?= esc($q['query']) ?></span><span class="stats-list-n"><?= number_format($q['impressions']) ?> shown &middot; <?= number_format($q['clicks']) ?> clicks</span></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
            <p class="strength-panel-note">From Google Search Console. Google leaves out searches that very few people make, so the words may not add up to the total.</p>
        <?php endif; ?>
    <?php elseif ($upgradeUrl !== ''): ?>
        <p class="strength-panel-note">
            <?= esc($upgradeName) ?> profiles also see views by month, which contact buttons people tap,
            the words they discovered the profile with, and how it does on Google search.
            <a class="strength-step-link" href="<?= esc($upgradeUrl, 'attr') ?>">About <?= esc($upgradeName) ?></a>
        </p>
    <?php endif; ?>
</div>

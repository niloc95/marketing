<?= $this->extend('layouts/public') ?>

<?= $this->section('head') ?>
<?= seo_meta(['title' => 'Stats | Admin']) ?>
<meta name="robots" content="noindex, nofollow">
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<?php
/**
 * @var string                         $site    'za' or 'app'
 * @var int                            $days
 * @var list<int>                      $periods
 * @var string                         $from
 * @var string                         $to
 * @var ?string                        $since   first day counted
 * @var list<array<string,int|string>> $daily
 * @var array<string,int>              $previous
 * @var list<array<string,mixed>>      $top
 * @var array{count:int,rows:list<array<string,mixed>>} $unseen
 * @var bool                           $googleConfigured
 * @var ?array<string,mixed>           $gsc
 * @var ?array<string,mixed>           $ga4
 */
$here      = static fn (array $query): string => base_url('admin/stats') . ($query === [] ? '' : '?' . http_build_query($query));
$siteQuery = $site === 'app' ? ['site' => 'app'] : [];
$date      = static fn (string $d): string => date('j M Y', strtotime($d));
$num       = static fn (int $n): string => number_format($n);

$columns = ['views', 'appearances', 'taps', 'taps_call', 'taps_whatsapp', 'taps_website', 'taps_directions', 'taps_booking', 'taps_email'];
$totals  = array_fill_keys($columns, 0);
foreach ($daily as $d) {
    foreach ($columns as $c) {
        $totals[$c] += (int) $d[$c];
    }
}
$change = static function (int $now, int $before): string {
    if ($before === 0) {
        return '';
    }
    $pct = (int) round(($now - $before) * 100 / $before);

    return ($pct > 0 ? '+' : '') . $pct . '% on the period before';
};

// Every day of the period gets a bar, counted or not, so gaps show as gaps.
$byDay = array_column($daily, null, 'day');
$bars  = [];
for ($t = strtotime($from); $t <= strtotime($to); $t += DAY) {
    $day    = date('Y-m-d', $t);
    $views  = (int) ($byDay[$day]['views'] ?? 0);
    $bars[] = [
        'label' => count($bars) % max(1, (int) ceil($days / 6)) === 0 ? date('j M', $t) : '',
        'value' => $views,
        'title' => date('D j M', $t) . ': ' . number_format($views) . ' views, ' . number_format((int) ($byDay[$day]['taps'] ?? 0)) . ' taps',
    ];
}
$kinds = ['taps_call' => 'Call', 'taps_whatsapp' => 'WhatsApp', 'taps_website' => 'Website', 'taps_directions' => 'Directions', 'taps_booking' => 'Booking', 'taps_email' => 'Email shown'];
?>
<?= view('admin/_bar') ?>

<section class="section">
    <div class="container">
        <h1 class="mb-1 text-xl font-bold text-slate-900 dark:text-white">Stats</h1>
        <p class="mb-4 text-sm text-slate-500 dark:text-slate-400">
            Our own counters first: profile views, appearances in discovery results and taps to get in touch, without
            crawlers, previews, the admin or owners looking at their own profile.
            <?php if ($since !== null): ?>Counting started on <?= esc($date($since)) ?>.<?php endif; ?>
            Google numbers come last and are pulled every night by <code>directory:stats</code>.
        </p>

        <div class="tabs">
            <a class="<?= $site === 'za' ? 'active' : '' ?>" href="<?= esc($here(array_filter(['days' => $days === 30 ? null : $days]))) ?>">local.webscheduler.co.za</a>
            <a class="<?= $site === 'app' ? 'active' : '' ?>" href="<?= esc($here(array_filter(['site' => 'app', 'days' => $days === 30 ? null : $days]))) ?>">local.webscheduler.app</a>
        </div>
        <p class="mt-3 flex flex-wrap gap-2 text-sm">
            <?php foreach ($periods as $p): ?>
                <a class="btn btn-xs <?= $p === $days ? 'btn-primary' : 'btn-ghost' ?>" href="<?= esc($here($siteQuery + ($p === 30 ? [] : ['days' => $p]))) ?>">Last <?= $p ?> days</a>
            <?php endforeach; ?>
        </p>

        <div class="panel stats-panel mt-4">
            <h2 class="strength-panel-title"><?= esc($date($from)) ?> to <?= esc($date($to)) ?></h2>
            <dl class="stats-tiles">
                <?php foreach (['views' => 'Profile views', 'appearances' => 'Shown in discovery results', 'taps' => 'Taps to get in touch'] as $key => $label): ?>
                    <div class="stats-tile">
                        <dt class="stats-tile-label"><?= esc($label) ?></dt>
                        <dd class="stats-tile-value"><?= $num($totals[$key]) ?></dd>
                        <dd class="stats-tile-change"><?= esc($change($totals[$key], $previous[$key])) ?></dd>
                    </div>
                <?php endforeach; ?>
            </dl>

            <h3 class="stats-heading">Profile views per day</h3>
            <?= view('directory/_bar_chart', [
                'name'    => 'Profile views per day',
                'bars'    => $bars,
                'columns' => ['Day', 'Views', 'Shown', 'Taps'],
                'rows'    => array_map(static fn (array $d): array => [
                    date('D j M', strtotime((string) $d['day'])), number_format((int) $d['views']), number_format((int) $d['appearances']), number_format((int) $d['taps']),
                ], array_reverse($daily)),
            ]) ?>

            <h3 class="stats-heading">Taps by kind</h3>
            <ul class="stats-list">
                <?php foreach ($kinds as $c => $label): ?>
                    <li><span><?= esc($label) ?></span><span class="stats-list-n"><?= $num($totals[$c]) ?></span></li>
                <?php endforeach; ?>
            </ul>
        </div>

        <h2 class="mt-6 mb-2 text-lg font-semibold text-slate-900 dark:text-white">Most viewed profiles</h2>
        <?php if ($top === []): ?>
            <div class="empty">Nothing counted in this period.</div>
        <?php else: ?>
            <div class="tablewrap mb-6">
                <table class="table">
                    <thead><tr><th>Profile</th><th>Views</th><th>Shown</th><th>Taps</th></tr></thead>
                    <tbody>
                    <?php foreach ($top as $row): ?>
                        <tr>
                            <td><a class="text-primary-600 hover:underline dark:text-primary-300" href="<?= esc(base_url('admin/edit/' . $row['id'])) ?>#profile-stats"><?= esc($row['display_name']) ?></a></td>
                            <td><?= $num($row['views']) ?></td><td><?= $num($row['appearances']) ?></td><td><?= $num($row['taps']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>

        <h2 class="mt-6 mb-2 text-lg font-semibold text-slate-900 dark:text-white">Published, but nobody opened them</h2>
        <p class="mb-2 text-sm text-slate-500 dark:text-slate-400">
            <?= $num($unseen['count']) ?> published profile<?= $unseen['count'] === 1 ? '' : 's' ?> had no views in this period. Newest first: these are the ones to help.
        </p>
        <?php if ($unseen['rows'] !== []): ?>
            <div class="tablewrap mb-6">
                <table class="table">
                    <thead><tr><th>Profile</th><th>Published</th></tr></thead>
                    <tbody>
                    <?php foreach ($unseen['rows'] as $row): ?>
                        <tr>
                            <td><a class="text-primary-600 hover:underline dark:text-primary-300" href="<?= esc(base_url('admin/edit/' . $row['id'])) ?>"><?= esc($row['display_name']) ?></a></td>
                            <td><?= esc($row['published_at'] ? $date((string) $row['published_at']) : '') ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>

        <h2 class="mt-8 mb-2 text-lg font-semibold text-slate-900 dark:text-white">Google search (Search Console)</h2>
        <?php if (! $googleConfigured): ?>
            <div class="empty">Google is not set up: add the <code>google.*</code> settings to .env.</div>
        <?php elseif ($gsc === null): ?>
            <div class="empty">Nothing pulled from Search Console for this site yet.</div>
        <?php else: ?>
            <p class="mb-3 text-sm text-slate-500 dark:text-slate-400">
                <?= esc($date($gsc['from'])) ?> to <?= esc($date($gsc['to'])) ?> (Google finishes a day about three days later).
                Shown <strong><?= $num($gsc['totals']['impressions']) ?></strong> times, clicked <strong><?= $num($gsc['totals']['clicks']) ?></strong>
                times<?php if ($gsc['totals']['position'] !== null): ?>, average position <strong><?= esc(number_format($gsc['totals']['position'], 1)) ?></strong><?php endif; ?>.
            </p>
            <div class="grid gap-6 lg:grid-cols-2">
                <div class="tablewrap">
                    <table class="table">
                        <thead><tr><th>Searched for (since <?= esc(date('j M', strtotime(substr($gsc['from'], 0, 7) . '-01'))) ?>)</th><th>Shown</th><th>Clicks</th></tr></thead>
                        <tbody>
                        <?php foreach ($gsc['queries'] as $q): ?>
                            <tr><td><?= esc($q['label']) ?></td><td><?= $num($q['value']) ?></td><td><?= $num($q['value2']) ?></td></tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div class="tablewrap">
                    <table class="table">
                        <thead><tr><th>Profile on Google</th><th>Shown</th><th>Clicks</th><th>Position</th></tr></thead>
                        <tbody>
                        <?php foreach ($gsc['listings'] as $l): ?>
                            <tr>
                                <td><a class="text-primary-600 hover:underline dark:text-primary-300" href="<?= esc(base_url('admin/edit/' . $l['id'])) ?>#profile-stats"><?= esc($l['display_name']) ?></a></td>
                                <td><?= $num($l['impressions']) ?></td><td><?= $num($l['clicks']) ?></td><td><?= $l['position'] === null ? '' : esc(number_format($l['position'], 1)) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>

        <h2 class="mt-8 mb-2 text-lg font-semibold text-slate-900 dark:text-white">Visitors (Google Analytics)</h2>
        <?php if (! $googleConfigured): ?>
            <div class="empty">Google is not set up.</div>
        <?php elseif ($ga4 === null): ?>
            <div class="empty">Nothing pulled from Google Analytics for this site yet.</div>
        <?php else: ?>
            <p class="mb-3 text-sm text-slate-500 dark:text-slate-400">
                <?= esc($date($ga4['from'])) ?> to <?= esc($date($ga4['to'])) ?>:
                <strong><?= $num($ga4['totals']['sessions']) ?></strong> sessions,
                <strong><?= $num($ga4['totals']['views']) ?></strong> page views,
                <strong><?= $num($ga4['totals']['users']) ?></strong> daily users added up.
                Only visitors who accepted analytics cookies are counted, so this is lower than the real number.
            </p>
            <div class="grid gap-6 lg:grid-cols-2">
                <div class="tablewrap">
                    <table class="table">
                        <thead><tr><th>Where sessions came from</th><th>Sessions</th></tr></thead>
                        <tbody>
                        <?php foreach ($ga4['channels'] as $c): ?>
                            <tr><td><?= esc($c['label']) ?></td><td><?= $num($c['value']) ?></td></tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div class="tablewrap">
                    <table class="table">
                        <thead><tr><th>Landing page</th><th>Sessions</th></tr></thead>
                        <tbody>
                        <?php foreach ($ga4['landing'] as $c): ?>
                            <tr><td class="break-all"><?= esc($c['label']) ?></td><td><?= $num($c['value']) ?></td></tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <p class="mt-2 text-xs text-slate-500 dark:text-slate-400">Channels, landing pages and search words are kept per month, so they cover whole months from the start of the period.</p>
        <?php endif; ?>
    </div>
</section>
<?= $this->endSection() ?>

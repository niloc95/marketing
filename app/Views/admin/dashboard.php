<?= $this->extend('layouts/public') ?>

<?= $this->section('head') ?>
<?= seo_meta(['title' => 'Dashboard | Admin']) ?>
<meta name="robots" content="noindex, nofollow">
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<?php
/**
 * The admin landing page (AdminDashboard, AdminDashboardService::overview()).
 * Every section links to the page with the detail; nothing here is a number
 * that page would not show.
 *
 * @var string               $site    'za' or 'app'
 * @var int                  $days
 * @var list<int>            $periods
 * @var string               $from
 * @var string               $to
 * @var array<string,mixed>  $health
 * @var array<string,int>    $queues
 * @var array<string,mixed>  $traffic
 * @var array<string,mixed>  $signups
 * @var array<string,mixed>  $plan
 * @var array<string,mixed>  $forms
 * @var array<string,mixed>  $setup
 */
$isApp     = $site === 'app';
$siteQuery = $isApp ? ['site' => 'app'] : [];
$here      = static fn (array $query): string => base_url('admin') . ($query === [] ? '' : '?' . http_build_query($query));
$withSite  = static fn (string $path, array $query = []): string => base_url($path) . (($q = $query + $siteQuery) === [] ? '' : '?' . http_build_query($q));
$date      = static fn (string $d): string => date('j M Y', strtotime($d));
$num       = static fn (int $n): string => number_format($n);
$change    = static function (int $now, int $before): string {
    if ($before === 0) {
        return $now > 0 ? 'none in the period before' : '';
    }
    $pct = (int) round(($now - $before) * 100 / $before);

    return ($pct > 0 ? '+' : '') . $pct . '% on the period before';
};

/**
 * One entry per day of the window, counted or not, so gaps show as gaps,
 * labelled at about six points along the axis.
 *
 * @param list<array<string,int|string>> $daily
 * @param callable(array<string,int|string>,int):array{values:array<string,int>,title:string} $fill
 */
$everyDay = static function (array $daily, callable $fill) use ($from, $to, $days): array {
    $byDay = array_column($daily, null, 'day');
    $out   = [];
    for ($t = strtotime($from); $t <= strtotime($to); $t += DAY) {
        $out[] = ['label' => count($out) % max(1, (int) ceil($days / 6)) === 0 ? date('j M', $t) : ''] + $fill($byDay[date('Y-m-d', $t)] ?? [], $t);
    }

    return $out;
};

// Traffic totals
$tTotals = ['views' => 0, 'appearances' => 0, 'taps' => 0];
foreach ($traffic['daily'] as $d) {
    foreach ($tTotals as $k => $_) {
        $tTotals[$k] += (int) $d[$k];
    }
}
// Signup totals
$sTotals = ['total' => 0, 'paid' => 0, 'free' => 0, 'published' => 0];
foreach ($signups['daily'] as $d) {
    foreach ($sTotals as $k => $_) {
        $sTotals[$k] += (int) $d[$k];
    }
}
$planLabel = (string) $plan['label'];

$signupBars = $everyDay($signups['daily'], static fn (array $d, int $t): array => [
    'values' => ['free' => (int) ($d['free'] ?? 0), 'paid' => (int) ($d['paid'] ?? 0)],
    'title'  => date('D j M', $t) . ': ' . (int) ($d['total'] ?? 0) . ' new, ' . (int) ($d['free'] ?? 0) . ' free, ' . (int) ($d['paid'] ?? 0) . ' ' . $planLabel,
]);
$viewBars = array_map(static fn (array $b): array => ['label' => $b['label'], 'value' => $b['values']['views'], 'title' => $b['title']],
    $everyDay($traffic['daily'], static fn (array $d, int $t): array => [
        'values' => ['views' => (int) ($d['views'] ?? 0)],
        'title'  => date('D j M', $t) . ': ' . number_format((int) ($d['views'] ?? 0)) . ' views, ' . number_format((int) ($d['taps'] ?? 0)) . ' taps',
    ]));
$formBars = $everyDay($forms['daily'], static fn (array $d, int $t): array => [
    'values' => ['ok' => (int) ($d['ok'] ?? 0), 'not' => (int) ($d['arrived'] ?? 0) - (int) ($d['ok'] ?? 0)],
    'title'  => date('D j M', $t) . ': ' . (int) ($d['arrived'] ?? 0) . ' arrived, ' . (int) ($d['ok'] ?? 0) . ' saved, '
        . (int) ($d['invalid'] ?? 0) . ' refused, ' . (int) ($d['lost'] ?? 0) . ' lost',
]);
$fTiles = $forms['tiles'];

$queueLinks = [
    'verifications' => ['Verification to review', 'admin/verifications'],
    'setup'         => ['Setup orders to do', 'admin/profile-setup'],
    'jobs'          => ['Job posts to approve', 'admin/jobs'],
    'reviews'       => ['Reviews to approve', 'admin/reviews'],
    'referrals'     => ['Recommendations', 'admin/referrals'],
    'partners'      => ['Partner applications', 'admin/partners'],
    'careers'       => ['New career applications', 'admin/careers'],
];
$waiting = array_filter($queues, static fn (int $n): bool => $n > 0);

$checkLabels = ['database' => 'Database', 'cache' => 'Cache', 'storage' => 'Storage', 'mail' => 'Mail'];
$pills       = [];
foreach ($health['checks'] as $key => $check) {
    $pills[] = [$checkLabels[$key] ?? ucfirst($key), $check['ok'] && ! ($key === 'mail' && $health['mailFailing']), $check['ok'] ? 'ok' : $check['detail'], 'admin/status'];
}
$lost    = (int) $health['formsLost']['count'];
$pills[] = ['Forms', $lost === 0, $lost === 0 ? 'nothing lost in 24 hours' : $lost . ' lost in 24 hours', 'admin/forms?outcome=lost&days=1'];
$pills[] = ['.app', (bool) $health['appEnabled'], $health['appEnabled'] ? 'switched on' : 'switched off', 'admin/status'];

$planStates = ['submitted' => 'Waiting for review', 'approved' => 'Approved, not paid', 'active' => 'Active', 'lapsed' => 'Lapsed', 'rejected' => 'Rejected'];
$orderStatuses = \App\Models\ProfileSetupOrderModel::STATUSES;
?>
<?= view('admin/_bar') ?>

<?php if (! empty($health['mailError'])): ?>
    <div class="container mt-5">
        <div class="alert alert-error">
            <strong>Outbound email is failing.</strong>
            Verification and manage links are not reaching people. Signups will sit in Pending.
            Last failure <?= esc(date('j M Y H:i', $health['mailError']['at'])) ?>:
            <?= esc($health['mailError']['reason']) ?>
        </div>
    </div>
<?php endif; ?>

<?php if ($lost > 0): ?>
    <div class="container mt-5">
        <div class="alert alert-error">
            <strong><?= $lost ?> form submission<?= $lost === 1 ? ' was' : 's were' ?> lost in the last 24 hours.</strong>
            Last one on <?= esc(config('Forms')->label((string) $health['formsLost']['form'])) ?> at <?= esc(date('j M H:i', strtotime((string) $health['formsLost']['last']))) ?> UTC.
            <a class="underline" href="<?= esc(base_url('admin/forms?outcome=lost&days=1') . '#events') ?>">Open form health</a>
        </div>
    </div>
<?php endif; ?>

<section class="section">
    <div class="container">
        <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
            <h1 class="text-xl font-bold text-slate-900 dark:text-white">Dashboard</h1>
            <a class="btn btn-ghost btn-xs" href="<?= base_url('admin/profiles') ?>">All profiles <?= lucide('arrow-right', 'h-4 w-4 shrink-0') ?></a>
        </div>

        <ul class="dash-health" aria-label="Health">
            <?php foreach ($pills as [$label, $ok, $detail, $path]): ?>
                <li>
                    <a class="dash-pill <?= $ok ? 'is-ok' : 'is-bad' ?>" href="<?= esc(base_url($path)) ?>" title="<?= esc($detail) ?>">
                        <?= lucide($ok ? 'check' : 'x', 'h-3.5 w-3.5 shrink-0') ?>
                        <?= esc($label) ?><span class="sr-only">: <?= esc($ok ? 'ok' : $detail) ?></span>
                    </a>
                </li>
            <?php endforeach; ?>
            <?php if (($health['stalePending'] ?? 0) > 0): ?>
                <li><a class="dash-pill is-warn" href="<?= esc(base_url('admin/profiles?status=pending')) ?>"><?= (int) $health['stalePending'] ?> pending with an expired verify link</a></li>
            <?php endif; ?>
        </ul>

        <h2 class="dash-heading">Needs action</h2>
        <?php if ($waiting === []): ?>
            <p class="stats-empty">Nothing waiting in any queue.</p>
        <?php else: ?>
            <div class="dash-queues">
                <?php foreach ($queueLinks as $key => [$label, $path]): ?>
                    <?php if (($queues[$key] ?? 0) > 0): ?>
                        <a class="dash-queue" href="<?= esc(base_url($path)) ?>">
                            <span class="stats-tile-value"><?= $num($queues[$key]) ?></span>
                            <span class="stats-tile-label"><?= esc($label) ?></span>
                        </a>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <div class="tabs mt-8">
            <a class="<?= $isApp ? '' : 'active' ?>" href="<?= esc($here(array_filter(['days' => $days === 30 ? null : $days]))) ?>">local.webscheduler.co.za</a>
            <a class="<?= $isApp ? 'active' : '' ?>" href="<?= esc($here(array_filter(['site' => 'app', 'days' => $days === 30 ? null : $days]))) ?>">local.webscheduler.app</a>
        </div>
        <p class="mt-3 flex flex-wrap gap-2 text-sm">
            <?php foreach ($periods as $p): ?>
                <a class="btn btn-xs <?= $p === $days ? 'btn-primary' : 'btn-ghost' ?>" href="<?= esc($here($siteQuery + ($p === 30 ? [] : ['days' => $p]))) ?>">Last <?= $p ?> days</a>
            <?php endforeach; ?>
            <span class="self-center text-slate-500 dark:text-slate-400"><?= esc($date($from)) ?> to <?= esc($date($to)) ?></span>
        </p>

        <dl class="stats-tiles dash-tiles">
            <?php foreach ([
                ['New profiles', $sTotals['total'], $change($sTotals['total'], $signups['previous']['total'])],
                ['Applied for ' . $planLabel, $sTotals['paid'], $change($sTotals['paid'], $signups['previous']['paid'])],
                [$planLabel . ' active now', (int) $plan['counts']['active'], (int) $plan['counts']['submitted'] . ' waiting for review'],
                ['Profile views', $tTotals['views'], $change($tTotals['views'], $traffic['previous']['views'])],
                ['Taps to get in touch', $tTotals['taps'], $change($tTotals['taps'], $traffic['previous']['taps'])],
                ['Forms saved', (int) $fTiles['saved'], $fTiles['rate'] === null ? 'nothing arrived' : $fTiles['rate'] . '% of ' . $num((int) $fTiles['arrived']) . ' arrived'],
            ] as [$label, $value, $note]): ?>
                <div class="stats-tile">
                    <dt class="stats-tile-label"><?= esc($label) ?></dt>
                    <dd class="stats-tile-value"><?= $num((int) $value) ?></dd>
                    <dd class="stats-tile-change"><?= esc($note) ?></dd>
                </div>
            <?php endforeach; ?>
        </dl>

        <div class="dash-grid">
            <div class="panel">
                <div class="dash-panel-head">
                    <h2 class="strength-panel-title">New profiles per day</h2>
                    <a class="dash-more" href="<?= esc(base_url('admin/profiles')) ?>">Profiles</a>
                </div>
                <?= view('directory/_stacked_bar_chart', [
                    'name'    => 'New profiles per day',
                    'series'  => [['key' => 'free', 'label' => 'Free'], ['key' => 'paid', 'label' => 'Applied for ' . $planLabel]],
                    'bars'    => $signupBars,
                    'columns' => ['Day', 'New', 'Free', $planLabel, 'Published'],
                    'rows'    => array_map(static fn (array $d): array => [
                        date('D j M', strtotime($d['day'])), (string) $d['total'], (string) $d['free'], (string) $d['paid'], (string) $d['published'],
                    ], array_reverse($signups['daily'])),
                ]) ?>
            </div>

            <div class="panel">
                <div class="dash-panel-head">
                    <h2 class="strength-panel-title">Profile views per day</h2>
                    <a class="dash-more" href="<?= esc($withSite('admin/stats', $days === 30 ? [] : ['days' => $days])) ?>">Stats</a>
                </div>
                <?php if ($traffic['since'] === null): ?>
                    <p class="stats-empty">Nothing counted yet.</p>
                <?php else: ?>
                    <?= view('directory/_bar_chart', [
                        'name'    => 'Profile views per day',
                        'bars'    => $viewBars,
                        'columns' => ['Day', 'Views', 'Shown', 'Taps'],
                        'rows'    => array_map(static fn (array $d): array => [
                            date('D j M', strtotime((string) $d['day'])), $num((int) $d['views']), $num((int) $d['appearances']), $num((int) $d['taps']),
                        ], array_reverse($traffic['daily'])),
                    ]) ?>
                <?php endif; ?>
            </div>

            <div class="panel">
                <div class="dash-panel-head">
                    <h2 class="strength-panel-title">Form submissions per day</h2>
                    <a class="dash-more" href="<?= esc($withSite('admin/forms', ['days' => $days])) ?>">Form health</a>
                </div>
                <p class="mb-2 text-xs text-slate-500 dark:text-slate-400">
                    <?= $num((int) $fTiles['lost']) ?> lost, <?= $num((int) $fTiles['never']) ?> pressed but never arrived in this period.
                </p>
                <?= view('directory/_stacked_bar_chart', [
                    'name'    => 'Form submissions per day',
                    'series'  => [['key' => 'ok', 'label' => 'Saved'], ['key' => 'not', 'label' => 'Not saved (refused, lost, other)']],
                    'bars'    => $formBars,
                    'columns' => ['Day', 'Arrived', 'Saved', 'Refused', 'Lost'],
                    'rows'    => array_map(static fn (array $d): array => [
                        date('D j M', strtotime($d['day'])), (string) $d['arrived'], (string) $d['ok'], (string) $d['invalid'], (string) $d['lost'],
                    ], array_reverse($forms['daily'])),
                ]) ?>
            </div>

            <div class="panel">
                <div class="dash-panel-head">
                    <h2 class="strength-panel-title"><?= esc($planLabel) ?> applications</h2>
                    <a class="dash-more" href="<?= esc(base_url($isApp ? 'admin/plus' : 'admin/verifications')) ?>">Queue</a>
                </div>
                <?= view('directory/_meter_rows', [
                    'name'   => $planLabel . ' applications by state',
                    'meters' => array_map(static fn (string $state, string $label): array => [
                        'label' => $label,
                        'value' => (int) ($plan['counts'][$state] ?? 0),
                        'href'  => base_url($isApp ? 'admin/plus' : 'admin/verifications') . '?state=' . $state,
                    ], array_keys($planStates), $planStates),
                ]) ?>

                <div class="dash-panel-head mt-6">
                    <h2 class="strength-panel-title">Setup orders (both sites)</h2>
                    <a class="dash-more" href="<?= esc(base_url('admin/profile-setup')) ?>">Orders</a>
                </div>
                <?= view('directory/_meter_rows', [
                    'name'   => 'Setup orders by status',
                    'meters' => array_map(static fn (string $status, string $label): array => [
                        'label' => $label,
                        'value' => (int) ($setup['counts'][$status] ?? 0),
                        'href'  => base_url('admin/profile-setup') . '?status=' . $status,
                    ], array_keys($orderStatuses), $orderStatuses),
                ]) ?>
            </div>
        </div>

        <div class="dash-grid">
            <div class="panel">
                <h2 class="strength-panel-title">Latest profiles</h2>
                <?php if ($signups['latest'] === []): ?>
                    <p class="stats-empty">No profiles yet.</p>
                <?php else: ?>
                    <ul class="stats-list dash-list mt-3">
                        <?php foreach ($signups['latest'] as $row): ?>
                            <li>
                                <a class="min-w-0 truncate text-primary-600 hover:underline dark:text-primary-300" href="<?= esc(base_url('admin/edit/' . $row['id'])) ?>"><?= esc($row['display_name']) ?></a>
                                <span class="stats-list-n">
                                    <?= esc(ucfirst($row['status'])) ?><?= $row['paid'] ? ', ' . esc($planLabel) : ', Free' ?><?= $row['source'] !== '' ? ', ' . esc($row['source']) : '' ?>,
                                    <?= esc(date('j M', strtotime($row['created_at']))) ?>
                                </span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>

            <div class="panel">
                <h2 class="strength-panel-title">Latest setup orders</h2>
                <?php if ($setup['latest'] === []): ?>
                    <p class="stats-empty">No orders yet.</p>
                <?php else: ?>
                    <ul class="stats-list dash-list mt-3">
                        <?php foreach ($setup['latest'] as $order): ?>
                            <li>
                                <a class="min-w-0 truncate text-primary-600 hover:underline dark:text-primary-300" href="<?= esc(base_url('admin/profile-setup') . '?status=' . $order['status'] . '#order-' . $order['id']) ?>"><?= esc((string) ($order['business_name'] ?: $order['name'])) ?></a>
                                <span class="stats-list-n"><?= esc($orderStatuses[$order['status']] ?? $order['status']) ?>, <?= esc(date('j M', strtotime((string) $order['created_at']))) ?></span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>

                <h2 class="strength-panel-title mt-6">Most viewed profiles</h2>
                <?php if ($traffic['top'] === []): ?>
                    <p class="stats-empty">Nothing counted in this period.</p>
                <?php else: ?>
                    <ul class="stats-list dash-list mt-3">
                        <?php foreach ($traffic['top'] as $row): ?>
                            <li>
                                <a class="min-w-0 truncate text-primary-600 hover:underline dark:text-primary-300" href="<?= esc(base_url('admin/edit/' . $row['id']) . '#profile-stats') ?>"><?= esc($row['display_name']) ?></a>
                                <span class="stats-list-n"><?= $num($row['views']) ?> view<?= $row['views'] === 1 ? '' : 's' ?>, <?= $num($row['taps']) ?> tap<?= $row['taps'] === 1 ? '' : 's' ?></span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </div>
    </div>
</section>
<?= $this->endSection() ?>

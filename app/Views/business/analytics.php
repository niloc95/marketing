<?php
/**
 * /business/analytics (Phase 7): how bookings went over a period, by tab.
 * Charts are the inline SVG partials the admin dashboard uses
 * (directory/_bar_chart.php and friends), never Chart.js: no new script, and
 * a table under each chart for anyone who cannot see it.
 *
 * @var array<string,string>      $tabs
 * @var string                    $tab
 * @var string                    $period
 * @var int                       $provider 0 for everyone in scope
 * @var list<array<string,mixed>> $people   empty for a team of one
 * @var array<string,mixed>       $report   AnalyticsService::report()
 */
use App\Services\Bookings\AnalyticsService;
use App\Services\Bookings\CatalogueService;

$t        = $report['totals'];
$money    = static fn (int $cents): string => CatalogueService::priceText($cents, (string) $report['currency']);
$num      = static fn (int|float $n): string => number_format((float) $n, is_float($n) && floor($n) !== $n ? 1 : 0);
$hours    = static fn (int $minutes): string => $minutes < 60 ? $minutes . ' min' : $num(round($minutes / 60, 1)) . ' h';
$change   = static fn (?float $pct): string => $pct === null ? 'nothing to compare with' : ($pct > 0 ? '+' : '') . $pct . '% on the period before';
$link     = static fn (array $q): string => base_url('business/analytics') . '?' . http_build_query(array_filter($q + ['tab' => $tab, 'period' => $period, 'provider' => $provider], static fn ($v): bool => $v !== '' && $v !== 0 && $v !== null));
$date     = static fn (string $d): string => date('j M Y', (int) strtotime($d));
$series   = $report['series'];
$every    = max(1, (int) ceil(count($series) / 6)); // axis labels: about six, or they overlap on a phone
// Literal classes, so Tailwind sees them: tiles per row on a wide screen.
$tileCols = [3 => 'sm:grid-cols-3', 6 => 'sm:grid-cols-3 lg:grid-cols-6'];
?>
<?= $this->extend('layouts/business') ?>
<?= $this->section('content') ?>
<p class="mt-1 text-sm text-slate-500 dark:text-slate-400"><?= esc($date($report['from'])) ?> to <?= esc($date($report['to'])) ?>. Bookings count on the day they start. Value is worked out from your prices; it is not money received.</p>

<form method="get" action="<?= base_url('business/analytics') ?>" class="mt-4 flex flex-wrap items-end gap-3">
    <input type="hidden" name="tab" value="<?= esc($tab, 'attr') ?>">
    <div class="field mb-0">
        <label for="an-period">Period</label>
        <select id="an-period" name="period" data-autosubmit>
            <?php foreach (AnalyticsService::PERIODS as $key => [, $label]): ?>
                <option value="<?= esc($key, 'attr') ?>"<?= $key === $period ? ' selected' : '' ?>><?= esc($label) ?></option>
            <?php endforeach ?>
        </select>
    </div>
    <?php if ($people !== []): ?>
        <div class="field mb-0">
            <label for="an-provider">Who</label>
            <select id="an-provider" name="provider" data-autosubmit>
                <option value="">Everyone</option>
                <?php foreach ($people as $p): ?>
                    <option value="<?= (int) $p['id'] ?>"<?= (int) $p['id'] === $provider ? ' selected' : '' ?>><?= esc((string) $p['name']) ?><?= $p['archived_at'] ? ' (archived)' : '' ?></option>
                <?php endforeach ?>
            </select>
        </div>
    <?php endif ?>
    <button type="submit" class="btn btn-ghost btn-sm" data-autosubmit-button>Show</button>
</form>

<nav class="tabs mt-6" aria-label="Analytics">
    <?php foreach ($tabs as $key => $label): ?>
        <a class="<?= $key === $tab ? 'active' : '' ?>" href="<?= esc($link(['tab' => $key]), 'attr') ?>"<?= $key === $tab ? ' aria-current="page"' : '' ?>><?= esc($label) ?></a>
    <?php endforeach ?>
</nav>

<?php if ($tab === 'overview'): ?>
    <dl class="stats-tiles biz-tiles <?= $tileCols[6] ?>">
        <?php foreach ([
            ['Booked', $num($t['booked']), $change($report['change']['booked'])],
            ['Value', $money((int) $t['value_cents']), $change($report['change']['value'])],
            ['Customers', $num($t['customers']), $change($report['change']['customers'])],
            ['Booked online', $t['online_share'] . '%', $num($t['online']) . ' of ' . $num($t['booked'])],
            ['Cancelled', $t['cancel_rate'] . '%', $num($t['cancelled']) . ' cancelled'],
            ['No shows', $t['no_show_rate'] . '%', $num($t['no_show']) . ' of those who were due'],
        ] as [$label, $value, $note]): ?>
            <div class="stats-tile">
                <dt class="stats-tile-label"><?= esc($label) ?></dt>
                <dd class="stats-tile-value"><?= esc($value) ?></dd>
                <dd class="stats-tile-change"><?= esc($note) ?></dd>
            </div>
        <?php endforeach ?>
    </dl>

    <div class="dash-grid">
        <div class="panel">
            <h2 class="strength-panel-title"><?= $period === '12m' ? 'Booked per month' : 'Booked per day' ?></h2>
            <?= view('directory/_stacked_bar_chart', [
                'name'    => $period === '12m' ? 'Booked per month' : 'Booked per day',
                'series'  => [['key' => 'online', 'label' => 'Online'], ['key' => 'business', 'label' => 'By the business']],
                'bars'    => array_map(static fn (array $b, int $i): array => [
                    'label'  => $i % $every === 0 ? $b['short'] : '',
                    'values' => ['online' => $b['online'], 'business' => $b['business']],
                    'title'  => $b['label'] . ': ' . ($b['online'] + $b['business']) . ' booked, ' . $b['online'] . ' online',
                ], $series, array_keys($series)),
                'columns' => [$period === '12m' ? 'Month' : 'Day', 'Online', 'By the business', 'Value'],
                'rows'    => array_map(static fn (array $b): array => [$b['label'], (string) $b['online'], (string) $b['business'], $money((int) $b['value_cents'])], array_reverse($series)),
            ]) ?>
        </div>
        <div class="panel">
            <h2 class="strength-panel-title">By status</h2>
            <?= view('directory/_meter_rows', [
                'name'   => 'Appointments by status',
                'meters' => array_map(static fn (array $s): array => ['label' => App\Services\Bookings\AppointmentStatus::short($s['status']), 'value' => $s['count']], $report['statuses']),
            ]) ?>
            <p class="mt-4 text-sm text-slate-600 dark:text-slate-300">
                <?= esc($hours((int) $t['minutes'])) ?> booked<?= $t['average_cents'] > 0 ? ', ' . esc($money((int) $t['average_cents'])) . ' on average' : '' ?>.
            </p>
        </div>
    </div>

    <div class="dash-grid">
        <div class="panel">
            <h2 class="strength-panel-title">Busiest days</h2>
            <?= view('directory/_meter_rows', [
                'name'   => 'Booked by day of the week',
                'meters' => array_map(static fn (array $d): array => ['label' => $d['label'], 'value' => $d['count']], $report['weekdays']),
            ]) ?>
        </div>
        <div class="panel">
            <h2 class="strength-panel-title">Busiest hours</h2>
            <?php if ($report['hours'] === []): ?>
                <p class="stats-empty">Nothing booked in this period.</p>
            <?php else: ?>
                <?= view('directory/_meter_rows', [
                    'name'   => 'Booked by starting hour',
                    'meters' => array_map(static fn (array $h): array => ['label' => $h['label'], 'value' => $h['count']], $report['hours']),
                ]) ?>
            <?php endif ?>
        </div>
    </div>

<?php elseif ($tab === 'services' || $tab === 'team'): ?>
    <?php $rows = $report[$tab]; ?>
    <div class="panel">
        <?php if ($rows === []): ?>
            <p class="stats-empty">Nothing booked in this period.</p>
        <?php else: ?>
            <?= view('directory/_meter_rows', [
                'name'   => $tab === 'services' ? 'Booked by service' : 'Booked by person',
                'meters' => array_map(static fn (array $r): array => ['label' => $r['name'], 'value' => $r['booked']], $rows),
            ]) ?>
            <div class="mt-6 overflow-x-auto">
                <table class="stats-table">
                    <thead>
                        <tr>
                            <th scope="col"><?= $tab === 'services' ? 'Service' : 'Who' ?></th>
                            <th scope="col">Booked</th>
                            <th scope="col">Share</th>
                            <th scope="col">Completed</th>
                            <th scope="col">No shows</th>
                            <th scope="col">Cancelled</th>
                            <th scope="col">Time</th>
                            <th scope="col">Value</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rows as $r): ?>
                            <tr>
                                <th scope="row">
                                    <?php if ($tab === 'team'): ?>
                                        <a class="underline" href="<?= esc($link(['tab' => 'overview', 'provider' => $r['id']]), 'attr') ?>"><?= esc($r['name']) ?></a>
                                    <?php else: ?>
                                        <?= esc($r['name']) ?>
                                    <?php endif ?>
                                </th>
                                <td><?= $num($r['booked']) ?></td>
                                <td><?= esc($r['share'] . '%') ?></td>
                                <td><?= $num($r['completed']) ?></td>
                                <td><?= $num($r['no_show']) ?></td>
                                <td><?= $num($r['cancelled']) ?></td>
                                <td><?= esc($hours((int) $r['minutes'])) ?></td>
                                <td><?= esc($money((int) $r['value_cents'])) ?></td>
                            </tr>
                        <?php endforeach ?>
                    </tbody>
                </table>
            </div>
        <?php endif ?>
    </div>

<?php else: ?>
    <dl class="stats-tiles biz-tiles <?= $tileCols[3] ?>">
        <?php foreach ([
            ['Customers', $num($t['customers']), $change($report['change']['customers'])],
            ['New', $num($t['new_customers']), 'first booking in this period'],
            ['Returning', $num($t['returning_customers']), 'booked before this period'],
        ] as [$label, $value, $note]): ?>
            <div class="stats-tile">
                <dt class="stats-tile-label"><?= esc($label) ?></dt>
                <dd class="stats-tile-value"><?= esc($value) ?></dd>
                <dd class="stats-tile-change"><?= esc($note) ?></dd>
            </div>
        <?php endforeach ?>
    </dl>
    <div class="panel">
        <h2 class="strength-panel-title">Most visits</h2>
        <?php if ($report['top'] === []): ?>
            <p class="stats-empty">Nothing booked in this period.</p>
        <?php else: ?>
            <ul class="stats-list dash-list mt-3">
                <?php foreach ($report['top'] as $c): ?>
                    <li>
                        <a class="min-w-0 truncate text-primary-600 hover:underline dark:text-primary-300" href="<?= base_url('business/customers/' . $c['hash']) ?>"><?= esc($c['name'] !== '' ? $c['name'] : 'No name') ?></a>
                        <span class="stats-list-n"><?= $num($c['booked']) ?> booked<?= $c['value_cents'] > 0 ? ', ' . esc($money((int) $c['value_cents'])) : '' ?></span>
                    </li>
                <?php endforeach ?>
            </ul>
        <?php endif ?>
    </div>
<?php endif ?>
<?= $this->endSection() ?>

<?= $this->section('shell_title') ?>Analytics<?= $this->endSection() ?>

<?= $this->extend('layouts/admin') ?>

<?= $this->section('head') ?>
<?= seo_meta(['title' => 'Form health | Admin']) ?>
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<?php
/**
 * @var string               $site    'za' or 'app'
 * @var int                  $days
 * @var list<int>            $periods
 * @var ?string              $form
 * @var ?string              $outcome
 * @var \Config\Forms        $config
 * @var array<string,mixed>  $data    FormHealthService::dashboard()
 */
use App\Services\FormHealthService;

$base = array_filter([
    'site' => $site === 'app' ? 'app' : null,
    'days' => $days === 7 ? null : $days,
    'form' => $form,
]);
$here = static fn (array $query): string => base_url('admin/forms') . ($query === [] ? '' : '?' . http_build_query($query));
$num  = static fn (int $n): string => number_format($n);
$time = static fn (string $d): string => date('j M H:i', strtotime($d));

$tiles    = $data['tiles'];
$previous = $data['previous'];
$change   = static function (?int $now, ?int $before, string $unit = '%'): string {
    if ($now === null || $before === null || $before === 0) {
        return '';
    }
    if ($unit === 'pts') {
        $d = $now - $before;

        return $d === 0 ? 'same as the period before' : ($d > 0 ? '+' : '') . $d . ' points on the period before';
    }
    $pct = (int) round(($now - $before) * 100 / $before);

    return ($pct > 0 ? '+' : '') . $pct . '% on the period before';
};

// Classes written out in full so Tailwind keeps them.
$chip = static fn (string $o): string => match ($o) {
    'ok'                                 => 'badge bg-emerald-100 text-emerald-800',
    'lost', 'exception', 'send_failed', 'js_error' => 'badge bg-red-100 text-red-800',
    'invalid', 'blocked'                 => 'badge bg-amber-100 text-amber-800',
    'csrf', 'expired'                    => 'badge bg-sky-100 text-sky-800',
    default                              => 'badge',
};

// Every day of the period gets a bar, so a day with nothing shows as a gap.
$byDay = array_column($data['daily'], null, 'day');
$bars  = [];
for ($t = strtotime(substr($data['from'], 0, 10)); $t <= strtotime(substr($data['to'], 0, 10)); $t += DAY) {
    $day    = date('Y-m-d', $t);
    $d      = $byDay[$day] ?? ['arrived' => 0, 'ok' => 0, 'invalid' => 0, 'lost' => 0];
    $bars[] = [
        'label' => count($bars) % max(1, (int) ceil($days / 6)) === 0 ? date('j M', $t) : '',
        'value' => (int) $d['arrived'],
        'title' => date('D j M', $t) . ': ' . $num((int) $d['arrived']) . ' arrived, ' . $num((int) $d['ok']) . ' saved, '
            . $num((int) $d['invalid']) . ' refused, ' . $num((int) $d['lost']) . ' lost',
    ];
}

$columns = [
    'pressed'     => ['Pressed', 'attempt'],
    'arrived'     => ['Arrived', null],
    'ok'          => ['Saved', 'ok'],
    'invalid'     => ['Refused', 'invalid'],
    'blocked'     => ['Blocked', 'blocked'],
    'throttled'   => ['Throttled', 'throttled'],
    'csrf'        => ['Page expired', 'csrf'],
    'expired'     => ['Signed out', 'expired'],
    'send_failed' => ['Mail failed', 'send_failed'],
    'lost'        => ['Lost', 'lost'],
    'bot'         => ['Bots', 'bot'],
];
$alarming = ['lost', 'send_failed'];
$banner   = $data['banner'];
?>

<div class="shell-page">
    <p class="mb-4 text-sm text-slate-500 dark:text-slate-400">
        What happened to every form people sent: saved, refused with something to fix, throttled, sent from a page
        that had expired, or lost. "Pressed" comes from the browser, "Arrived" from the server; the gap between them
        is submits that never reached us. No names, emails or typed values are kept, only field names.
    </p>

    <div class="tabs">
        <a class="<?= $site === 'za' ? 'active' : '' ?>" href="<?= esc($here(array_diff_key($base, ['site' => 1]))) ?>">local.webscheduler.co.za</a>
        <a class="<?= $site === 'app' ? 'active' : '' ?>" href="<?= esc($here(['site' => 'app'] + $base)) ?>">local.webscheduler.app</a>
    </div>
    <p class="mt-3 flex flex-wrap gap-2 text-sm">
        <?php foreach ($periods as $p): ?>
            <a class="btn btn-xs <?= $p === $days ? 'btn-primary' : 'btn-ghost' ?>" href="<?= esc($here(array_filter(['days' => $p === 7 ? null : $p] + $base))) ?>"><?= $p === 1 ? 'Today' : 'Last ' . $p . ' days' ?></a>
        <?php endforeach; ?>
        <?php if ($form !== null): ?>
            <a class="btn btn-xs btn-ghost" href="<?= esc($here(array_diff_key($base, ['form' => 1]))) ?>">All forms (now: <?= esc($config->label($form)) ?>)</a>
        <?php endif; ?>
    </p>

    <?php if ($banner['count'] > 0): ?>
        <div class="alert alert-error mt-4">
            <strong><?= $num($banner['count']) ?> submission<?= $banner['count'] === 1 ? ' was' : 's were' ?> lost in the last 24 hours.</strong>
            Last one on <a class="underline" href="<?= esc($here(['form' => $banner['form'], 'outcome' => 'lost', 'days' => 1] + $base) . '#events') ?>"><?= esc($config->label((string) $banner['form'])) ?></a>
            at <?= esc($time((string) $banner['last'])) ?>.
        </div>
    <?php elseif ($data['any']): ?>
        <div class="alert alert-success mt-4">No lost submissions in the last 24 hours.</div>
    <?php endif; ?>

    <?php if (! $data['any']): ?>
        <div class="empty mt-4">Nothing recorded yet. Counting started when this release went live.</div>
    <?php else: ?>

    <div class="panel stats-panel mt-4">
        <h2 class="strength-panel-title"><?= esc(date('j M Y', strtotime($data['from']))) ?> to <?= esc(date('j M Y', strtotime($data['to']))) ?></h2>
        <dl class="stats-tiles">
            <div class="stats-tile">
                <dt class="stats-tile-label">Submits pressed</dt>
                <dd class="stats-tile-value"><?= $num($tiles['pressed']) ?></dd>
                <dd class="stats-tile-change"><?= esc($change($tiles['pressed'], $previous['pressed'])) ?></dd>
            </div>
            <div class="stats-tile">
                <dt class="stats-tile-label">Arrived</dt>
                <dd class="stats-tile-value"><?= $num($tiles['arrived']) ?></dd>
                <dd class="stats-tile-change"><?= esc($change($tiles['arrived'], $previous['arrived'])) ?></dd>
            </div>
            <div class="stats-tile">
                <dt class="stats-tile-label">Saved</dt>
                <dd class="stats-tile-value"><?= $num($tiles['saved']) ?></dd>
                <dd class="stats-tile-change"><?= esc($change($tiles['saved'], $previous['saved'])) ?></dd>
            </div>
            <div class="stats-tile">
                <dt class="stats-tile-label">Success rate</dt>
                <dd class="stats-tile-value"><?= $tiles['rate'] === null ? 'none yet' : $tiles['rate'] . '%' ?></dd>
                <dd class="stats-tile-change"><?= esc($change($tiles['rate'], $previous['rate'], 'pts')) ?></dd>
            </div>
            <div class="stats-tile">
                <dt class="stats-tile-label">Lost</dt>
                <dd class="stats-tile-value <?= $tiles['lost'] > 0 ? 'text-red-700 dark:text-red-300' : '' ?>"><?= $num($tiles['lost']) ?></dd>
                <dd class="stats-tile-change"><?= esc($change($tiles['lost'], $previous['lost'])) ?></dd>
            </div>
            <div class="stats-tile">
                <dt class="stats-tile-label">Pressed, never arrived</dt>
                <dd class="stats-tile-value <?= $tiles['never'] > 0 ? 'text-amber-700 dark:text-amber-300' : '' ?>"><?= $num($tiles['never']) ?></dd>
                <dd class="stats-tile-change"><?= esc($change($tiles['never'], $previous['never'])) ?></dd>
            </div>
        </dl>

        <h3 class="stats-heading">Arrived per day</h3>
        <?= view('directory/_bar_chart', [
            'name'    => 'Form submissions arrived per day',
            'bars'    => $bars,
            'columns' => ['Day', 'Arrived', 'Saved', 'Refused', 'Lost'],
            'rows'    => array_map(static fn (array $d): array => [
                date('D j M', strtotime((string) $d['day'])), $num((int) $d['arrived']), $num((int) $d['ok']), $num((int) $d['invalid']), $num((int) $d['lost']),
            ], array_reverse($data['daily'])),
        ]) ?>
    </div>

    <h2 class="mt-6 mb-2 section-title">Every form</h2>
    <p class="mb-2 text-sm text-slate-500 dark:text-slate-400">Problems first. Click a number to see those submissions.</p>
    <div class="tablewrap mb-6">
        <table class="table">
            <thead>
                <tr>
                    <th>Form</th>
                    <?php foreach ($columns as [$label]): ?><th><?= esc($label) ?></th><?php endforeach; ?>
                    <th>Success</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($data['forms'] as $row): ?>
                <tr>
                    <td><a class="text-primary-600 hover:underline dark:text-primary-300" href="<?= esc($here(['form' => $row['key']] + $base)) ?>"><?= esc($row['label']) ?></a></td>
                    <?php foreach ($columns as $col => [, $filter]): ?>
                        <?php $n = (int) $row[$col]; ?>
                        <td class="<?= $n > 0 && in_array($col, $alarming, true) ? 'font-semibold text-red-700 dark:text-red-300' : '' ?>">
                            <?php if ($n > 0 && $filter !== null): ?>
                                <a class="hover:underline" href="<?= esc($here(['form' => $row['key'], 'outcome' => $filter] + $base) . '#events') ?>"><?= $num($n) ?></a>
                            <?php else: ?>
                                <?= $num($n) ?>
                            <?php endif; ?>
                        </td>
                    <?php endforeach; ?>
                    <td><?= $row['rate'] === null ? '' : (int) $row['rate'] . '%' ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <h2 class="mt-6 mb-2 section-title">What trips people up</h2>
    <p class="mb-2 text-sm text-slate-500 dark:text-slate-400">The fields a form came back on, and the ones the browser stopped a submit for, most often first.</p>
    <?php if ($data['fields'] === []): ?>
        <div class="empty">No refused fields in this period.</div>
    <?php else: ?>
        <div class="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
            <?php foreach ($data['fields'] as $key => $fields): ?>
                <div class="panel">
                    <h3 class="stats-heading"><?= esc($config->label((string) $key)) ?></h3>
                    <ul class="stats-list">
                        <?php foreach ($fields as $f): ?>
                            <li><span><?= esc($f['label']) ?></span><span class="stats-list-n"><?= $num($f['count']) ?></span></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <h2 id="events" class="mt-8 mb-2 text-lg font-semibold text-slate-900 dark:text-white">Latest submissions</h2>
    <p class="mb-2 flex flex-wrap gap-2 text-sm">
        <?php foreach (['lost' => 'Lost', 'send_failed' => 'Mail failed', 'csrf' => 'Page expired', 'invalid' => 'Refused', 'blocked' => 'Blocked', 'js_error' => 'Script errors', 'ok' => 'Saved'] as $o => $label): ?>
            <a class="btn btn-xs <?= $outcome === $o ? 'btn-primary' : 'btn-ghost' ?>" href="<?= esc($here(['outcome' => $o] + $base) . '#events') ?>"><?= esc($label) ?></a>
        <?php endforeach; ?>
        <?php if ($outcome !== null): ?>
            <a class="btn btn-xs btn-ghost" href="<?= esc($here($base) . '#events') ?>">Everything</a>
        <?php endif; ?>
    </p>
    <?php if ($data['recent'] === []): ?>
        <div class="empty">Nothing in this period.</div>
    <?php else: ?>
        <div class="tablewrap mb-6">
            <table class="table">
                <thead><tr><th>When (UTC)</th><th>Form</th><th>What happened</th><th>From</th><th>Detail</th><th>Page</th></tr></thead>
                <tbody>
                <?php foreach ($data['recent'] as $e): ?>
                    <tr>
                        <td class="whitespace-nowrap"><?= esc($time((string) $e['created_at'])) ?></td>
                        <td><?= esc($config->label((string) $e['form'])) ?></td>
                        <td><span class="<?= $chip((string) $e['outcome']) ?>"><?= esc(FormHealthService::outcomeLabel((string) $e['outcome'])) ?></span></td>
                        <td><?= $e['source'] === 'browser' ? 'browser' : 'server' ?></td>
                        <td class="break-all">
                            <?php if (in_array($e['outcome'], ['invalid', 'blocked'], true) && $e['detail'] !== null && ! str_starts_with((string) $e['detail'], 'http_')): ?>
                                <?= esc(implode(', ', array_map([$config, 'fieldLabel'], explode(',', (string) $e['detail'])))) ?>
                            <?php else: ?>
                                <?= esc((string) $e['detail']) ?>
                            <?php endif; ?>
                        </td>
                        <td class="break-all"><?= esc('/' . $e['path']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

    <h2 class="mt-8 mb-2 section-title">Alert emails</h2>
    <p class="mb-2 text-sm text-slate-500 dark:text-slate-400">
        Sent at once for a lost submission, a crash or a failed send, and for <?= FormHealthService::CSRF_ALERT_AT ?> expired pages on one form in an hour;
        at most one an hour for each form and problem. The summary of every form goes out each morning.
    </p>
    <?php if ($data['alerts'] === []): ?>
        <div class="empty">No alerts sent.</div>
    <?php else: ?>
        <div class="tablewrap mb-6">
            <table class="table">
                <thead><tr><th>When (UTC)</th><th>Form</th><th>Problem</th><th>Email</th></tr></thead>
                <tbody>
                <?php foreach ($data['alerts'] as $a): ?>
                    <tr>
                        <td class="whitespace-nowrap"><?= esc($time((string) $a['created_at'])) ?></td>
                        <td><?= esc($config->label((string) $a['form'])) ?></td>
                        <td><span class="<?= $chip((string) $a['outcome']) ?>"><?= esc(FormHealthService::outcomeLabel((string) $a['outcome'])) ?></span></td>
                        <td class="<?= $a['detail'] === 'delivered' ? '' : 'font-semibold text-red-700 dark:text-red-300' ?>"><?= esc((string) $a['detail']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

    <?php endif; ?>
</div>
<?= $this->endSection() ?>

<?= $this->section('shell_title') ?>Form health<?= $this->endSection() ?>

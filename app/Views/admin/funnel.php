<?= $this->extend('layouts/public') ?>

<?= $this->section('head') ?>
<?= seo_meta(['title' => 'Funnel | Admin']) ?>
<meta name="robots" content="noindex, nofollow">
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<?php
$pct = static fn (int $n, int $of) => $of > 0 ? number_format(100 * $n / $of, 1) . '%' : 'n/a';
$top = (int) ($funnel['stages'][0]['count'] ?? 0);
$convTable = static function (array $rows) use ($pct): string {
    $html = '';
    foreach ($rows as $r) {
        $html .= '<tr><td>' . esc($r['label']) . '</td>'
            . '<td>' . (int) $r['listings'] . '</td>'
            . '<td>' . (int) $r['applied'] . '</td>'
            . '<td>' . (int) $r['paid'] . '</td>'
            . '<td>' . esc($pct((int) $r['paid'], (int) $r['listings'])) . '</td></tr>';
    }
    return $html;
};
?>
<?= view('admin/_bar') ?>

<section class="section">
    <div class="container">
        <h1 class="mb-1 text-xl font-bold text-slate-900 dark:text-white">Verified Business funnel</h1>
        <p class="mb-5 text-sm text-slate-500 dark:text-slate-400">
            From signup to renewal, badge plan only. International Profile subscriptions and deleted profiles are left out.
        </p>

        <div class="panel mb-8">
            <h3>Pace to target</h3>
            <p class="text-sm">
                <strong><?= number_format($pace['active']) ?></strong> active of <?= number_format($pace['target']) ?>
                by <?= esc(date('j M Y', strtotime($pace['by']))) ?>.
                <?php if ($pace['remaining'] === 0): ?>
                    Target reached.
                <?php elseif ($pace['perWeek'] === null): ?>
                    The target date has passed; <?= number_format($pace['remaining']) ?> short.
                <?php else: ?>
                    Needs <strong><?= number_format($pace['perWeek']) ?> net new a week</strong>
                    for the next <?= esc((string) $pace['weeksLeft']) ?> weeks.
                <?php endif; ?>
            </p>
        </div>

        <h2 class="mb-3 text-base font-semibold text-slate-900 dark:text-white">Funnel</h2>
        <div class="tablewrap mb-3">
            <table class="table">
                <thead><tr><th>Stage</th><th>Count</th><th>Of previous</th><th>Of listings</th></tr></thead>
                <tbody>
                <?php $prev = null; foreach ($funnel['stages'] as $s): ?>
                    <tr>
                        <td><?= esc($s['label']) ?></td>
                        <td><?= number_format($s['count']) ?></td>
                        <td><?= $prev === null ? 'n/a' : esc($pct($s['count'], $prev)) ?></td>
                        <td><?= esc($pct($s['count'], $top)) ?></td>
                    </tr>
                <?php $prev = $s['count']; endforeach; ?>
                </tbody>
            </table>
        </div>
        <p class="mb-8 text-xs text-slate-500 dark:text-slate-400">
            Of the active badges, <?= (int) $funnel['cancelling'] ?> have cancelled and run to their paid date.
            <?= (int) $funnel['churned'] ?> have lapsed or cancelled in total.
        </p>

        <h2 class="mb-3 text-base font-semibold text-slate-900 dark:text-white">By week</h2>
        <div class="tablewrap mb-8">
            <table class="table">
                <thead><tr><th>Week of</th><th>New listings</th><th>Applications</th><th>First payments</th><th>Renewals</th><th>Cancellations</th></tr></thead>
                <tbody>
                <?php foreach ($weekly as $w): ?>
                    <tr>
                        <td><?= esc(date('j M', strtotime($w['week']))) ?></td>
                        <td><?= (int) $w['listings'] ?></td>
                        <td><?= (int) $w['applications'] ?></td>
                        <td><?= (int) $w['first_payments'] ?></td>
                        <td><?= (int) $w['renewals'] ?></td>
                        <td><?= (int) $w['cancellations'] ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <h2 class="mb-3 text-base font-semibold text-slate-900 dark:text-white">By signup channel</h2>
        <div class="tablewrap mb-8">
            <table class="table">
                <thead><tr><th>Channel</th><th>Listings</th><th>Applied</th><th>Paid</th><th>Listing → paid</th></tr></thead>
                <tbody><?= $convTable($bySource) ?></tbody>
            </table>
        </div>

        <h2 class="mb-3 text-base font-semibold text-slate-900 dark:text-white">By category</h2>
        <div class="tablewrap mb-8">
            <table class="table">
                <thead><tr><th>Category</th><th>Listings</th><th>Applied</th><th>Paid</th><th>Listing → paid</th></tr></thead>
                <tbody><?= $convTable($byCategory) ?></tbody>
            </table>
        </div>

        <ul class="alert-list text-xs text-slate-500 dark:text-slate-400">
            <li>"Paid (ever)" includes manual (EFT) activations, which leave no PayFast event.</li>
            <li>There is no record of when an email was confirmed, so the weekly table has no column for it.</li>
            <li>An expired badge is not timestamped, so weekly churn counts cancellations only.</li>
            <li>Signup channel is recorded only for signups since it was introduced; older profiles show as "(before tracking)".</li>
            <li>Target and date come from <code>directory.funnelTarget</code> / <code>directory.funnelTargetDate</code>.</li>
        </ul>
    </div>
</section>
<?= $this->endSection() ?>

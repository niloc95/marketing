<?php
/**
 * @var ?list<array<string,mixed>>                     $tables
 * @var ?array<string,list<array{label:string,n:int}>> $breakdowns
 * @var ?array<string,mixed>                           $migrations
 */
$scopes = [
    'site'     => 'Site (country_code)',
    'profile'  => 'Profile (listing_id)',
    'business' => 'Business (listing_id, scoped model)',
    'global'   => 'Global',
];
$size = static fn (int $b): string => $b >= 1048576 ? number_format($b / 1048576, 1) . ' MB' : number_format($b / 1024) . ' KB';
?>
<div class="prose-legal">
    <p>
        One MySQL 8 database (<code>webscheduler_directory</code> on production and dev, <code>webscheduler_directory_tests</code>
        for the suite), InnoDB, <code>utf8mb4</code>, every table prefixed <code>xs_</code>. The schema is built only by migrations
        in <code>app/Database/Migrations/</code>; never edit a table by hand. Row counts below are exact (<code>COUNT(*)</code>),
        "next id" is the table's AUTO_INCREMENT.
    </p>
    <?php if ($migrations !== null): ?>
        <p>
            Migrations: <strong><?= number_format($migrations['applied']) ?></strong> applied of <?= number_format($migrations['files']) ?> files,
            <?= number_format($migrations['batches']) ?> batches<?php if ($migrations['latest'] !== null): ?>, latest
            <code><?= esc($migrations['latest']['version']) ?></code> (<?= esc(basename(str_replace('\\', '/', (string) $migrations['latest']['class']))) ?>)<?php endif; ?>.
            <?php if ($migrations['pending'] !== []): ?>
                <span class="text-brand-crimson">Pending: <?= esc(implode(', ', $migrations['pending'])) ?>.</span>
            <?php endif; ?>
        </p>
    <?php endif; ?>
</div>

<?php if ($tables === null): ?>
    <p class="text-sm text-brand-crimson">The table list is unavailable right now.</p>
<?php else: ?>
    <?php
    $total = array_sum(array_column($tables, 'rows'));
    $bytes = array_sum(array_column($tables, 'bytes'));
    $missing = array_values(array_filter($tables, static fn ($t) => $t['written'] === null));
    ?>
    <p class="mb-3 text-sm text-slate-600 dark:text-slate-400">
        <strong><?= count($tables) ?></strong> tables, <strong><?= number_format($total) ?></strong> rows, <?= esc($size($bytes)) ?> on disk.
        <?php if ($missing !== []): ?>
            <span class="text-brand-crimson">Not described in Config\Handbook: <?= esc(implode(', ', array_column($missing, 'name'))) ?>.</span>
        <?php endif; ?>
    </p>

    <div class="tablewrap">
        <table class="table">
            <thead><tr><th>Table</th><th>What it holds</th><th>Written by</th><th>Scope</th><th class="text-right">Rows</th><th class="text-right">Highest id</th><th class="text-right">Next id</th><th class="text-right">Size</th></tr></thead>
            <tbody>
            <?php foreach ($tables as $t): ?>
                <tr>
                    <td><a href="#table-<?= esc($t['name'], 'attr') ?>"><code><?= esc($t['name']) ?></code></a><?= ($t['written']['product'] ?? '') === 'bookings' ? ' <span class="text-xs text-slate-500">Bookings</span>' : '' ?></td>
                    <td><?= esc($t['written']['purpose'] ?? '') ?></td>
                    <td class="text-xs"><?= esc($t['written']['owner'] ?? '') ?></td>
                    <td class="text-xs"><?= esc($scopes[$t['scope']] ?? $t['scope']) ?></td>
                    <td class="text-right"><?= number_format($t['rows']) ?></td>
                    <td class="text-right"><?= $t['max_id'] === null ? '' : number_format($t['max_id']) ?></td>
                    <td class="text-right"><?= $t['next_id'] === null ? '' : number_format($t['next_id']) ?></td>
                    <td class="text-right text-xs"><?= esc($size($t['bytes'])) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php if ($breakdowns !== null): ?>
        <h3 class="mb-2 mt-8 text-lg font-semibold text-slate-900 dark:text-white">Counts that matter</h3>
        <div class="handbook-columns">
            <?php foreach ($breakdowns as $label => $rows): ?>
                <?php if ($rows === []) {
                    continue;
                } ?>
                <div class="tablewrap">
                    <table class="table">
                        <thead><tr><th><?= esc($label) ?></th><th class="text-right">Rows</th></tr></thead>
                        <tbody>
                        <?php foreach ($rows as $r): ?>
                            <tr><td><?= esc($r['label']) ?></td><td class="text-right"><?= number_format($r['n']) ?></td></tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <h3 class="mb-2 mt-8 text-lg font-semibold text-slate-900 dark:text-white">Every table, column by column</h3>
    <?php foreach ($tables as $t): ?>
        <details class="faq-item" id="table-<?= esc($t['name'], 'attr') ?>">
            <summary><code><?= esc($t['name']) ?></code> <span class="text-sm font-normal text-slate-500"><?= number_format($t['rows']) ?> rows, <?= count($t['columns']) ?> columns</span></summary>
            <div class="faq-answer">
                <?php if ($t['written'] !== null): ?>
                    <p class="mb-2"><?= esc($t['written']['purpose']) ?>. Written by <?= esc($t['written']['owner']) ?>.
                        <?php if ($t['written']['personal'] !== null): ?><strong>Personal data:</strong> <?= esc($t['written']['personal']) ?>.<?php endif; ?></p>
                <?php endif; ?>
                <p class="mb-2 text-sm">Primary key <code><?= esc($t['primary'] !== '' ? $t['primary'] : 'none') ?></code>, <?= esc($t['engine']) ?>, <?= esc($t['collation']) ?>.</p>
                <div class="tablewrap">
                    <table class="table">
                        <thead><tr><th>Column</th><th>Type</th><th>Null</th><th>Default</th><th>Key</th><th>Extra</th></tr></thead>
                        <tbody>
                        <?php foreach ($t['columns'] as $c): ?>
                            <tr>
                                <td><code><?= esc($c['name']) ?></code></td>
                                <td class="text-xs"><?= esc($c['type']) ?></td>
                                <td class="text-xs"><?= $c['nullable'] === 'YES' ? 'yes' : '' ?></td>
                                <td class="text-xs"><?= esc((string) ($c['def'] ?? '')) ?></td>
                                <td class="text-xs"><?= esc((string) $c['k']) ?></td>
                                <td class="text-xs"><?= esc((string) $c['extra']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php if ($t['indexes'] !== []): ?>
                    <p class="mb-1 mt-3 text-sm"><strong>Indexes:</strong>
                        <?= esc(implode('; ', array_map(static fn ($i) => $i['name'] . ' (' . $i['cols'] . ($i['non_unique'] ? '' : ', unique') . ($i['type'] !== 'BTREE' ? ', ' . strtolower($i['type']) : '') . ')', $t['indexes']))) ?></p>
                <?php endif; ?>
                <?php if ($t['foreign'] !== []): ?>
                    <p class="mb-1 text-sm"><strong>Foreign keys:</strong>
                        <?= esc(implode('; ', array_map(static fn ($f) => $f['col'] . ' → ' . $f['ref_table'] . '.' . $f['ref_col'], $t['foreign']))) ?></p>
                <?php endif; ?>
            </div>
        </details>
    <?php endforeach; ?>
<?php endif; ?>

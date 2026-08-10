<?= $this->extend('layouts/public') ?>

<?= $this->section('head') ?>
<?= seo_meta(['title' => 'System status — Admin']) ?>
<meta name="robots" content="noindex, nofollow">
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<?php
/**
 * Everything rendered here is escaped, including inside <pre> — log bodies
 * carry uploaded filenames, CSP report URLs and SQL literals, all of which are
 * attacker-influenced, and a <script> tag inside <pre> still executes.
 */
$pill = static fn (bool $ok) => $ok ? 'pill pill-published' : 'pill pill-pending';
$when = static fn (?int $ts) => $ts ? date('j M Y H:i', $ts) : '—';
?>
<?= view('admin/_bar') ?>

<section class="section">
    <div class="container">
        <h1 class="mb-1 text-xl font-bold text-slate-900 dark:text-white">System status</h1>
        <p class="mb-5 text-sm text-slate-500 dark:text-slate-400">
            The same checks <code>/health</code> reports to the uptime monitor, plus what the app knows about its own storage and configuration.
        </p>

        <?php if (! $allOk): ?>
            <div class="alert alert-error mb-5"><strong>Something is broken.</strong> See the failing check below.</div>
        <?php endif; ?>

        <div class="card-grid mb-8">
            <!-- ---------------------------------------------------- health -->
            <div class="panel">
                <h3>Health</h3>
                <?php foreach ($checks as $name => $c): ?>
                    <div class="kv">
                        <span class="k"><?= esc(ucfirst($name)) ?></span>
                        <span class="<?= $pill($c['ok']) ?>"><?= $c['ok'] ? 'OK' : 'FAIL' ?></span>
                    </div>
                    <?php if (! $c['ok']): ?>
                        <p class="mt-1 text-xs text-brand-crimson dark:text-red-400"><?= esc($c['detail']) ?></p>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>

            <!-- ------------------------------------------------------ mail -->
            <div class="panel">
                <h3>Outbound email</h3>
                <div class="kv"><span class="k">Consecutive failures</span><span><?= (int) $mailFailures ?></span></div>
                <div class="kv"><span class="k">Last successful send</span><span><?= esc($when($mailLastOk)) ?></span></div>
                <?php if ($mailError): ?>
                    <div class="kv"><span class="k">Last failure</span><span><?= esc($when($mailError['at'])) ?></span></div>
                    <p class="mt-2 text-xs text-brand-crimson dark:text-red-400"><?= esc($mailError['reason']) ?></p>
                    <form method="post" action="<?= base_url('admin/status/clear-mail') ?>" class="mt-3"
                          data-confirm="Clear the recorded mail failure? Do this only after fixing the cause.">
                        <?= csrf_field() ?>
                        <button class="btn btn-ghost btn-xs">Clear mail status</button>
                    </form>
                <?php else: ?>
                    <p class="mt-2 text-xs text-slate-500 dark:text-slate-400">No failures recorded.</p>
                <?php endif; ?>
            </div>

            <!-- ------------------------------------------------------ data -->
            <div class="panel">
                <h3>Profiles</h3>
                <?php // 'all' excludes trashed — counts() sums the non-deleted GROUP BY. ?>
                <div class="kv"><span class="k">Live (excl. trash)</span><span><?= (int) ($counts['all'] ?? 0) ?></span></div>
                <div class="kv"><span class="k">Published</span><span><?= (int) ($counts['published'] ?? 0) ?></span></div>
                <div class="kv"><span class="k">Pending</span><span><?= (int) ($counts['pending'] ?? 0) ?></span></div>
                <div class="kv"><span class="k">Unpublished</span><span><?= (int) ($counts['unpublished'] ?? 0) ?></span></div>
                <div class="kv"><span class="k">In trash</span><span><?= (int) ($counts['trashed'] ?? 0) ?></span></div>
                <div class="kv">
                    <span class="k">Pending, link expired</span>
                    <span class="<?= $pill(($stalePending ?? 0) === 0) ?>"><?= $stalePending === null ? '?' : (int) $stalePending ?></span>
                </div>
                <?php if (($stalePending ?? 0) > 0): ?>
                    <p class="mt-2 text-xs text-slate-500 dark:text-slate-400">
                        These owners can no longer verify — the link expired and there is no self-serve way to request another.
                        A climbing number is the fingerprint of a mail outage. Publish them by hand, or delete them.
                    </p>
                <?php endif; ?>
            </div>

            <!-- --------------------------------------- verified business -->
            <div class="panel">
                <h3>Verified Business</h3>
                <div class="kv">
                    <span class="k">Awaiting review</span>
                    <?php // Not an error, but it is the number someone has to act on —
                          // amber once anyone is waiting, so it reads as a queue. ?>
                    <span class="<?= $pill(($verifCounts['submitted'] ?? 0) === 0) ?>"><?= (int) ($verifCounts['submitted'] ?? 0) ?></span>
                </div>
                <div class="kv"><span class="k">Approved, unpaid</span><span><?= (int) ($verifCounts['approved'] ?? 0) ?></span></div>
                <div class="kv"><span class="k">Active (paying)</span><span><?= (int) ($verifCounts['active'] ?? 0) ?></span></div>
                <div class="kv"><span class="k">Lapsed</span><span><?= (int) ($verifCounts['lapsed'] ?? 0) ?></span></div>
                <div class="kv"><span class="k">Rejected</span><span><?= (int) ($verifCounts['rejected'] ?? 0) ?></span></div>
                <?php if (($verifCounts['submitted'] ?? 0) > 0): ?>
                    <p class="mt-2 text-xs text-slate-500 dark:text-slate-400">
                        Owners waiting on a decision. Nobody has been charged yet — payment only follows approval.
                        <a class="text-primary-500 dark:text-primary-300 hover:underline" href="<?= base_url('admin/verifications') ?>">Open the queue</a>.
                    </p>
                <?php endif; ?>
            </div>
        </div>

        <div class="card-grid mb-8">
            <!-- --------------------------------------------------- storage -->
            <div class="panel">
                <h3>Uploaded files</h3>
                <div class="kv"><span class="k">Logos</span><span><?= (int) $storage['logos']['count'] ?> &middot; <?= esc($svc->bytes($storage['logos']['bytes'])) ?></span></div>
                <div class="kv"><span class="k">Gallery photos</span><span><?= (int) $storage['gallery']['count'] ?> &middot; <?= esc($svc->bytes($storage['gallery']['bytes'])) ?></span></div>
                <div class="kv">
                    <span class="k">Orphaned files</span>
                    <span class="<?= $pill(($storage['orphans'] ?? 0) === 0) ?>"><?= $storage['orphans'] === null ? '?' : (int) $storage['orphans'] ?></span>
                </div>
                <?php if (($storage['orphans'] ?? 0) > 0): ?>
                    <p class="mt-2 text-xs text-slate-500 dark:text-slate-400">
                        Files on disk that no profile or photo row refers to. Deleting a profile removes its files first, so this should be zero.
                    </p>
                <?php endif; ?>
            </div>

            <!-- --------------------------------------------------- backups -->
            <div class="panel">
                <h3>Backups</h3>
                <p class="mb-3 text-xs text-slate-500 dark:text-slate-400">
                    <strong>This app does not take backups and cannot see whether yours ran.</strong>
                    Hostinger's hPanel is the source of truth. Below is what would be lost.
                </p>
                <div class="kv"><span class="k">Database</span><span><?= esc($svc->bytes($dbBytes)) ?></span></div>
                <div class="kv"><span class="k">Uploads</span><span><?= esc($svc->bytes($storage['logos']['bytes'] + $storage['gallery']['bytes'])) ?></span></div>
                <ul class="alert-list mt-3 text-xs text-slate-500 dark:text-slate-400">
                    <li>Check hPanel for what your plan covers and how far back it goes.</li>
                    <li>Confirm it covers the database <em>and</em> <code>public/assets/listings</code> — a database-only backup restores a site where every logo is broken.</li>
                    <li>Do one restore into a scratch database before you need it.</li>
                </ul>
            </div>

            <!-- ---------------------------------------------------- config -->
            <div class="panel">
                <h3>Configuration</h3>
                <?php foreach ($configRows as $row): ?>
                    <div class="kv">
                        <span class="k"><?= esc($row['label']) ?><?= $row['env'] ? '' : ' *' ?></span>
                        <span class="<?= $row['warn'] ? 'text-brand-crimson dark:text-red-400' : '' ?>"><?= esc($row['value']) ?></span>
                    </div>
                <?php endforeach; ?>
                <p class="mt-2 text-xs text-slate-500 dark:text-slate-400">* not overridable from <code>.env</code> — change requires a deploy.</p>
            </div>
        </div>

        <!-- ------------------------------------------------------------ log -->
        <h2 class="mb-3 mt-8 text-base font-semibold text-slate-900 dark:text-white">Recent log</h2>
        <p class="mb-3 text-xs text-slate-500 dark:text-slate-400">
            Warnings and above. <code>DEBUG</code> is never shown — it is noise, and this page is only as trusted as the admin session.
        </p>

        <form method="get" action="<?= base_url('admin/status') ?>" class="mb-4 flex flex-wrap items-end gap-3">
            <div class="field mb-0">
                <label class="text-xs">Day</label>
                <select name="file" class="w-52">
                    <?php foreach ($logFiles as $f): ?>
                        <option value="<?= esc($f['name'], 'attr') ?>" <?= $f['name'] === $logFile ? 'selected' : '' ?>>
                            <?= esc($f['date']) ?> (<?= esc($svc->bytes($f['size'])) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field mb-0">
                <label class="text-xs">Minimum level</label>
                <select name="level" class="w-40">
                    <?php foreach ([3 => 'Critical', 4 => 'Error', 5 => 'Warning', 6 => 'Notice', 7 => 'Info'] as $v => $label): ?>
                        <option value="<?= $v ?>" <?= (int) $logLevel === $v ? 'selected' : '' ?>><?= esc($label) ?> and above</option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button class="btn btn-primary btn-xs">Show</button>
        </form>

        <?php if ($logEntries === []): ?>
            <div class="empty">Nothing at this level in this file. That is the good outcome.</div>
        <?php else: ?>
            <div class="grid gap-2">
                <?php foreach ($logEntries as $e): ?>
                    <?php $bad = in_array($e['level'], ['EMERGENCY', 'ALERT', 'CRITICAL', 'ERROR'], true); ?>
                    <div class="card p-3">
                        <div class="mb-1 flex flex-wrap items-center gap-2 text-xs">
                            <span class="<?= $pill(! $bad) ?>"><?= esc($e['level']) ?></span>
                            <span class="text-slate-500 dark:text-slate-400"><?= esc($e['time']) ?></span>
                        </div>
                        <?php // esc() inside <pre> is not optional: <script> runs there too. ?>
                        <pre class="overflow-x-auto whitespace-pre-wrap break-words text-xs text-slate-700 dark:text-slate-300"><?= esc($e['message']) ?></pre>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</section>
<?= $this->endSection() ?>

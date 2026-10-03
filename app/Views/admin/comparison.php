<?= $this->extend('layouts/public') ?>

<?= $this->section('head') ?>
<?= seo_meta(['title' => 'Comparison — ' . config('Directory')->siteName()]) ?>
<meta name="robots" content="noindex, nofollow">
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<?php

use Config\Comparison;

/**
 * The /compare table, editable without a deploy. Saved whole by
 * ComparisonService::save(), which refuses the lot if any field is wrong, so a
 * rejected save comes back with exactly what was typed ($old) rather than the
 * stored table.
 *
 * No JavaScript: rows are ordered by their Position number, a row is deleted
 * by clearing its label, and three blank rows at the end are for adding.
 *
 * @var array      $table      ComparisonService::table(), placeholders intact
 * @var array|null $lastChange DirectorySettings::lastChange()
 * @var array      $errors
 * @var array|null $old        the rejected POST
 */
$columns   = $old['columns'] ?? $table['columns'];
$checkedOn = $old['checked_on'] ?? $table['checkedOn'];

if (is_array($old)) {
    $rows = array_values(array_filter((array) ($old['rows'] ?? []), 'is_array'));
} else {
    $rows = [];
    foreach ($table['rows'] as $i => $r) {
        $rows[] = ['position' => ($i + 1) * 10] + $r;
    }
    for ($n = 0; $n < 3; $n++) {
        $rows[] = ['position' => (count($table['rows']) + $n + 1) * 10, 'key' => '', 'label' => '', 'cells' => []];
    }
}

$changed = '';
if (! empty($lastChange['at'])) {
    $ts      = strtotime((string) $lastChange['at']);
    $changed = 'Last changed ' . ($ts ? date('j M Y H:i', $ts) : (string) $lastChange['at'])
        . (empty($lastChange['by']) ? '' : ' by ' . $lastChange['by']) . '.';
}
?>
<?= view('admin/_bar') ?>

<section class="section">
    <div class="container">
        <div class="form-card">
            <h1 class="mb-1.5 text-xl font-bold text-slate-900 dark:text-white">Comparison table</h1>
            <p class="mb-2 text-sm text-slate-500 dark:text-slate-400">
                What <a class="text-primary-500 hover:underline" href="<?= base_url('compare') ?>" target="_blank" rel="noopener">/compare</a>
                shows. Saving takes effect immediately.
                <?= ! empty($table['custom']) ? 'Showing your edited table.' : 'Showing the built-in defaults.' ?>
                <?= esc($changed) ?>
            </p>
            <ul class="mb-6 list-disc pl-5 text-sm text-slate-500 dark:text-slate-400">
                <li>Plain text only. <code>{gallery}</code>, <code>{locations}</code>, <code>{team}</code> and <code>{price}</code> are filled in from the live caps and price.</li>
                <li>Every competitor claim should be something their own help pages say. Update the checked-on date when you re-check.</li>
                <li>The WebScheduler Local column must match what the site actually gates: staff, branches and vacancies are Verified Business.</li>
                <li>Rows show in Position order. Clear a row's label to delete it.</li>
            </ul>

            <?php if ($errors !== []): ?>
                <div class="alert alert-error">
                    <ul class="list-disc pl-5">
                        <?php foreach ($errors as $e): ?>
                            <li><?= esc($e) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <form method="post" action="<?= base_url('admin/comparison') ?>">
                <?= csrf_field() ?>

                <h2 class="mb-3 text-base font-bold text-slate-900 dark:text-white">Columns</h2>
                <div class="grid gap-4 sm:grid-cols-2">
                    <?php foreach (Comparison::COLUMNS as $col): ?>
                        <div class="field">
                            <label for="col-<?= $col ?>">Heading</label>
                            <input type="text" id="col-<?= $col ?>" name="columns[<?= $col ?>][label]"
                                   maxlength="<?= App\Services\ComparisonService::MAX_COLUMN_LABEL ?>"
                                   value="<?= esc((string) ($columns[$col]['label'] ?? ''), 'attr') ?>">
                            <input type="text" class="mt-2" name="columns[<?= $col ?>][sub]" aria-label="Sub-heading"
                                   placeholder="Sub-heading (optional)"
                                   maxlength="<?= App\Services\ComparisonService::MAX_COLUMN_SUB ?>"
                                   value="<?= esc((string) ($columns[$col]['sub'] ?? ''), 'attr') ?>">
                        </div>
                    <?php endforeach; ?>
                </div>

                <div class="field max-w-xs">
                    <label for="checked-on">Facts checked on</label>
                    <input type="date" id="checked-on" name="checked_on" value="<?= esc((string) $checkedOn, 'attr') ?>">
                </div>

                <h2 class="mb-3 mt-6 text-base font-bold text-slate-900 dark:text-white">Rows</h2>
                <?php foreach ($rows as $i => $r): ?>
                    <fieldset class="panel mb-4">
                        <legend class="sr-only">Row <?= $i + 1 ?></legend>
                        <input type="hidden" name="rows[<?= $i ?>][key]" value="<?= esc((string) ($r['key'] ?? ''), 'attr') ?>">
                        <div class="grid gap-3 sm:grid-cols-[6rem_1fr]">
                            <div class="field mb-0">
                                <label for="row-<?= $i ?>-pos">Position</label>
                                <input type="number" id="row-<?= $i ?>-pos" name="rows[<?= $i ?>][position]" step="1"
                                       value="<?= esc((string) ($r['position'] ?? ''), 'attr') ?>">
                            </div>
                            <div class="field mb-0">
                                <label for="row-<?= $i ?>-label"><?= ($r['label'] ?? '') === '' ? 'New row label' : 'Feature' ?></label>
                                <input type="text" id="row-<?= $i ?>-label" name="rows[<?= $i ?>][label]"
                                       maxlength="<?= App\Services\ComparisonService::MAX_LABEL ?>"
                                       value="<?= esc((string) ($r['label'] ?? ''), 'attr') ?>">
                            </div>
                        </div>
                        <div class="mt-3 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                            <?php foreach (Comparison::COLUMNS as $col):
                                $cell    = (array) ($r['cells'][$col] ?? []);
                                $options = $col === 'local' ? Comparison::LOCAL_STATUSES : Comparison::OTHER_STATUSES; ?>
                                <div class="field mb-0">
                                    <label for="row-<?= $i ?>-<?= $col ?>"><?= esc((string) ($columns[$col]['label'] ?? $col)) ?></label>
                                    <select id="row-<?= $i ?>-<?= $col ?>" name="rows[<?= $i ?>][cells][<?= $col ?>][status]">
                                        <option value="">— pick —</option>
                                        <?php foreach ($options as $value => $label): ?>
                                            <option value="<?= $value ?>" <?= ($cell['status'] ?? '') === $value ? 'selected' : '' ?>><?= esc($label) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <input type="text" class="mt-2" name="rows[<?= $i ?>][cells][<?= $col ?>][note]"
                                           aria-label="<?= esc((string) ($columns[$col]['label'] ?? $col), 'attr') ?> note"
                                           placeholder="Note (optional)"
                                           maxlength="<?= App\Services\ComparisonService::MAX_NOTE ?>"
                                           value="<?= esc((string) ($cell['note'] ?? ''), 'attr') ?>">
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </fieldset>
                <?php endforeach; ?>

                <button type="submit" class="btn btn-accent">Save comparison</button>
            </form>

            <hr class="my-6 border-slate-200 dark:border-slate-700">

            <form method="post" action="<?= base_url('admin/comparison/reset') ?>">
                <?= csrf_field() ?>
                <p class="mb-3 text-sm text-slate-500 dark:text-slate-400">
                    Throw away your edits and show the built-in table again.
                </p>
                <button type="submit" class="btn btn-ghost">Reset to defaults</button>
            </form>
        </div>
    </div>
</section>
<?= $this->endSection() ?>

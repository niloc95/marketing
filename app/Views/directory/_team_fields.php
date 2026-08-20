<?php

use App\Services\TeamMemberService;

/**
 * "Your people" — the team rows, inside the listing form.
 *
 * Rendered from _form_fields.php, so it is part of the one big form and saves
 * with the Save button at the bottom. That is why there are no per-row buttons
 * here: an earlier version of this feature had a <form> per person and could
 * only sit outside the listing form, because HTML forbids nesting forms.
 *
 * Progressive enhancement, in the house style:
 *
 *  - The disclosure is a native <details>/<summary>, so "Add your people" rolls
 *    the section down with no JavaScript at all — the same reasoning already
 *    written into faq.php. Open when there is something to see or something to
 *    fix, collapsed otherwise.
 *  - Removal is a real checkbox, not a scripted × — it works with scripting
 *    off. directory.js restyles it as an × and strikes the row through.
 *  - "Add another person" clones the <template>. Without JavaScript there is
 *    still one blank slot below the stored rows, so a person can be added one
 *    per save rather than not at all.
 *
 * @var array    $rows   stored team rows, or flashed input after a failed save
 * @var callable $err    fn(string $field): string — keys are 'team.0.name' etc
 */
$max   = TeamMemberService::MAX_MEMBERS;
$rows  = array_values($rows);
$used  = count($rows);
$slots = max(0, $max - $used);

// Any error anywhere in the section forces it open, or the owner is told to
// correct a field they cannot see.
$hasError = false;
foreach ($rows as $i => $_) {
    foreach (['name', 'role', 'credentials', 'specializations', 'bio'] as $f) {
        if ($err('team.' . $i . '.' . $f) !== '') {
            $hasError = true;
        }
    }
}
$hasError = $hasError || $err('team') !== '';

/**
 * One row. Also used to build the <template>, with $i = '__i__' — the index
 * placeholder directory.js rewrites when it clones. Keeping the markup in one
 * closure is what stops the live rows and the cloned ones drifting apart.
 */
$row = function ($i, array $m = []) use ($err): string {
    $val = static fn (string $k): string => (string) ($m[$k] ?? '');
    $e   = static fn (string $k): string => is_string($i) ? '' : $err('team.' . $i . '.' . $k);

    ob_start(); ?>
    <div class="repeat-row" data-repeat-item>
        <?php if (! empty($m['id'])): ?>
            <input type="hidden" name="team[<?= $i ?>][id]" value="<?= (int) $m['id'] ?>">
        <?php endif; ?>

        <div class="repeat-row-head">
            <?php if (! empty($m['photo_path'])): ?>
                <img src="<?= esc(base_url($m['photo_path']), 'attr') ?>" alt=""
                     width="48" height="48" class="team-avatar team-avatar-sm" loading="lazy">
            <?php endif; ?>
            <label class="repeat-remove">
                <input type="checkbox" name="team[<?= $i ?>][_remove]" value="1"
                       data-repeat-remove aria-label="Remove this person">
                <span>Remove</span>
            </label>
        </div>

        <div class="form-row">
            <div class="field">
                <label>Name</label>
                <input type="text" name="team[<?= $i ?>][name]" maxlength="150"
                       value="<?= esc($val('name'), 'attr') ?>" placeholder="e.g. Jane Smith">
                <?php if ($e('name')): ?><div class="err"><?= esc($e('name')) ?></div><?php endif; ?>
            </div>
            <div class="field">
                <label>Position</label>
                <input type="text" name="team[<?= $i ?>][role]" maxlength="120"
                       value="<?= esc($val('role'), 'attr') ?>" placeholder="e.g. Founding Partner">
                <?php if ($e('role')): ?><div class="err"><?= esc($e('role')) ?></div><?php endif; ?>
            </div>
        </div>
        <div class="field">
            <label>Qualifications</label>
            <input type="text" name="team[<?= $i ?>][credentials]" maxlength="255"
                   value="<?= esc($val('credentials'), 'attr') ?>"
                   placeholder="e.g. BA LLB (UCT), admitted attorney 1996">
            <?php if ($e('credentials')): ?><div class="err"><?= esc($e('credentials')) ?></div><?php endif; ?>
        </div>
        <div class="field">
            <label>Areas of focus</label>
            <input type="text" name="team[<?= $i ?>][specializations]" maxlength="500"
                   value="<?= esc($val('specializations'), 'attr') ?>"
                   placeholder="Comma-separated, e.g. Conveyancing, Commercial litigation">
            <div class="hint">Separate with commas &mdash; up to 12 per person.</div>
            <?php if ($e('specializations')): ?><div class="err"><?= esc($e('specializations')) ?></div><?php endif; ?>
        </div>
        <div class="field">
            <label>Short bio</label>
            <textarea name="team[<?= $i ?>][bio]" rows="2" maxlength="1200"
                      placeholder="A paragraph on what they do and who they help."><?= esc($val('bio')) ?></textarea>
            <?php if ($e('bio')): ?><div class="err"><?= esc($e('bio')) ?></div><?php endif; ?>
        </div>
        <div class="field">
            <label>Photo</label>
            <?php // Indexed to match the row: resolveTeamPhotos() reads these
                  // with getFileMultiple('team_photo') and merges each result
                  // into team[same index]. accept="image/*" for the HEIC reason
                  // spelled out in _form_fields.php. ?>
            <input type="file" name="team_photo[<?= $i ?>]" accept="image/*">
            <div class="hint"><?= ! empty($m['photo_path']) ? 'Choosing a file replaces the current photo.' : 'Head and shoulders. Up to 10 MB, resized here.' ?></div>
        </div>
    </div>
    <?php
    return (string) ob_get_clean();
};
?>
<details class="disclosure" <?= $used > 0 || $hasError ? 'open' : '' ?> data-repeat>
    <summary class="disclosure-summary">
        <span>Add your people</span>
        <span class="hint"><?= $used > 0 ? $used . ' of ' . $max : 'Partners, associates, practitioners' ?></span>
    </summary>

    <div class="disclosure-body">
        <p class="hint">
            The people inside your business, each with their own position and areas of focus.
            They appear as an &ldquo;Our team&rdquo; panel on your public profile. Up to <?= (int) $max ?>.
        </p>
        <?php if ($err('team')): ?><div class="err"><?= esc($err('team')) ?></div><?php endif; ?>

        <div data-repeat-list>
            <?php foreach ($rows as $i => $m): ?>
                <?= $row($i, is_array($m) ? $m : []) ?>
            <?php endforeach; ?>

            <?php // The no-JS affordance: one empty slot, always. A blank name is
                  // skipped by the service, so leaving it untouched costs nothing. ?>
            <?php if ($slots > 0): ?>
                <?= $row($used) ?>
            <?php endif; ?>
        </div>

        <template data-repeat-template><?= $row('__i__') ?></template>

        <button type="button" class="btn btn-ghost btn-xs" data-repeat-add
                data-repeat-max="<?= (int) $max ?>"
                data-repeat-full="You have listed the maximum of <?= (int) $max ?> people.">
            + Add another person
        </button>
    </div>
</details>

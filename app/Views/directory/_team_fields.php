<?php

use App\Libraries\ProfessionalRegistration;
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
 * Professional registration (number, ORCID, ResearchGate) is per person since
 * 4 Nov 2026 (owner): a practice has several doctors, each with a number of
 * their own. Which regulator applies comes from the listing's category, as
 * before: each option names it as data-regulator, and directory.js relabels
 * every row's fieldset, and hides and disables it for an unregulated
 * category, so its marker is not posted and stored numbers survive.
 *
 * @var array    $rows       stored team rows, or flashed input after a failed save
 * @var callable $err        fn(string $field): string — keys are 'team.0.name' etc
 * @var callable $v          the listing form's value reader, for the category
 * @var array    $categories rows with id, slug and group_name
 * @var bool     $adminEdit  admin edit: each person gets the "checked" tick
 */
$regulator = null;
foreach ($categories ?? [] as $c) {
    if ((string) $c['id'] === (string) $v('category_id')) {
        $regulator = ProfessionalRegistration::regulatorFor($c['slug'] ?? null, $c['group_name'] ?? null);
        break;
    }
}
$adminEdit = ! empty($adminEdit);
$regLabels = array_map(
    static fn (array $r): array => ['short' => $r['short'], 'name' => $r['name'], 'example' => $r['example'], 'research' => $r['research']],
    config('Registrations')->regulators
);

$max   = TeamMemberService::MAX_MEMBERS;
$rows  = array_values($rows);
$used  = count($rows);
$slots = max(0, $max - $used);

// Any error anywhere in the section forces it open, or the owner is told to
// correct a field they cannot see.
$hasError = false;
foreach ($rows as $i => $_) {
    foreach (['name', 'role', 'credentials', 'specializations', 'bio', 'reg_number', 'orcid', 'researchgate_url'] as $f) {
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
$row = function ($i, array $m = [], bool $spare = false) use ($err, $regulator, $adminEdit): string {
    $val = static fn (string $k): string => (string) ($m[$k] ?? '');
    $e   = static fn (string $k): string => is_string($i) ? '' : $err('team.' . $i . '.' . $k);

    ob_start(); ?>
    <div class="repeat-row" data-repeat-item<?= $spare ? ' data-repeat-spare' : '' ?>>
        <?php if (! empty($m['id'])): ?>
            <input type="hidden" name="team[<?= $i ?>][id]" value="<?= (int) $m['id'] ?>">
        <?php endif; ?>

        <div class="repeat-row-head">
            <?php if (! empty($m['photo_path'])): ?>
                <img src="<?= esc(base_url($m['photo_path'])) ?>" alt=""
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
        <?php // Hidden AND disabled with no regulator, so nothing of it posts. ?>
        <?php $off = $regulator === null; ?>
        <fieldset class="field team-registration" data-registration <?= $off ? 'hidden disabled' : '' ?>>
            <legend>Professional registration <span class="text-slate-400">(optional)</span></legend>
            <input type="hidden" name="team[<?= $i ?>][<?= ProfessionalRegistration::MARKER ?>]" value="1">
            <div class="form-row">
                <div class="field">
                    <label><span data-reg-short><?= esc($regulator['short'] ?? '') ?></span> registration number</label>
                    <input type="text" name="team[<?= $i ?>][reg_number]" maxlength="40" autocomplete="off"
                           value="<?= esc($val('reg_number'), 'attr') ?>" placeholder="<?= esc($regulator['example'] ?? '', 'attr') ?>">
                    <?php if ($e('reg_number')): ?><div class="err"><?= esc($e('reg_number')) ?></div><?php endif; ?>
                </div>
            </div>
            <?php // Disabled with it, so a hidden ORCID box cannot fail a lawyer's save. ?>
            <fieldset class="form-row" data-reg-research <?= ($regulator['research'] ?? true) ? '' : 'hidden disabled' ?>>
                <div class="field">
                    <label>ORCID iD</label>
                    <input type="text" name="team[<?= $i ?>][orcid]" maxlength="60" autocomplete="off"
                           value="<?= esc($val('orcid'), 'attr') ?>" placeholder="0000-0002-1825-0097">
                    <?php if ($e('orcid')): ?><div class="err"><?= esc($e('orcid')) ?></div><?php endif; ?>
                </div>
                <div class="field">
                    <label>ResearchGate profile</label>
                    <input type="text" name="team[<?= $i ?>][researchgate_url]" maxlength="255" inputmode="url"
                           value="<?= esc($val('researchgate_url'), 'attr') ?>" placeholder="researchgate.net/profile/Your-Name">
                    <?php if ($e('researchgate_url')): ?><div class="err"><?= esc($e('researchgate_url')) ?></div><?php endif; ?>
                </div>
            </fieldset>
            <div class="hint">
                As it appears on the <span data-reg-name><?= esc($regulator['name'] ?? '') ?></span> register.
                Shown on this person&rsquo;s card as self declared, beside a link to the register so customers can check it.
            </div>
            <?php // Admin only: lets the card say "checked" instead of "self
                  // declared". Offered beside a stored number; the owner
                  // changing the number clears it. ?>
            <?php if ($adminEdit && $val('reg_number') !== '' && ! empty($m['id'])): ?>
                <input type="hidden" name="team[<?= $i ?>][reg_checked_present]" value="1">
                <label class="mt-2 block font-medium"><input type="checkbox" name="team[<?= $i ?>][reg_checked]" value="1" <?= ! empty($m['reg_checked_at']) ? 'checked' : '' ?>> Number <?= esc($val('reg_number')) ?> checked against the register</label>
                <?php if (! empty($m['reg_checked_at'])): ?><div class="hint">Checked <?= esc(date('j F Y', strtotime((string) $m['reg_checked_at']))) ?>.</div><?php endif; ?>
            <?php endif; ?>
        </fieldset>
        <div class="field">
            <label>Areas of focus</label>
            <input type="text" name="team[<?= $i ?>][specializations]" maxlength="500"
                   value="<?= esc($val('specializations'), 'attr') ?>"
                   placeholder="Comma separated, e.g. Conveyancing, Commercial litigation">
            <div class="hint">Separate with commas, up to 12 per person.</div>
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
<details class="disclosure" <?= $used > 0 || $hasError ? 'open' : '' ?> data-repeat data-regulators="<?= esc(json_encode($regLabels, JSON_UNESCAPED_SLASHES), 'attr') ?>">
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

            <?php // The no-JS affordance: one empty slot. A blank name is skipped by
                  // the service, so leaving it untouched costs nothing. Kept while
                  // the list is empty, so an opened section has a first row to
                  // fill. Once people are saved it is marked data-repeat-spare and
                  // directory.js removes it, because "+ Add another person" adds a
                  // row and an always open blank one read as unfinished (owner,
                  // 4 Nov 2026). Not <noscript>: a branch row holds a <noscript>
                  // of its own (holiday rows), and they cannot nest. ?>
            <?php if ($slots > 0): ?>
                <?= $row($used, [], $used > 0) ?>
            <?php endif; ?>
        </div>

        <template data-repeat-template><?= $row('__i__') ?></template>

        <button type="button" class="btn btn-ghost btn-xs" data-repeat-add
                data-repeat-max="<?= (int) $max ?>"
                data-repeat-full="You have added the maximum of <?= (int) $max ?> people.">
            + Add another person
        </button>
    </div>
</details>

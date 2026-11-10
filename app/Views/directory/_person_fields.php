<?php

use App\Libraries\ProfessionalRegistration;

/**
 * One person's fields (T31): the profile form's "Add your people" rows and
 * the person page in Bookings render this one partial, so a person is the
 * same record with the same fields wherever they were added. The writer is
 * TeamMemberService (syncFromForm() for the profile form, saveOne() for
 * Bookings), which reads the same names.
 *
 * @var callable    $name      fn(string $field): string, the input's name ('team[0][role]', or 'role')
 * @var string      $photoName the file input's name ('team_photo[0]', or 'photo')
 * @var array       $m         the stored card, or flashed input
 * @var callable    $e         fn(string $field): string, the field's error
 * @var array|null  $regulator the listing category's regulator
 * @var bool        $adminEdit admin edit: the "checked against the register" tick
 * @var bool        $showSwitch offer "Show on my profile"
 */
$val        = static fn (string $k): string => (string) ($m[$k] ?? '');
$adminEdit  = ! empty($adminEdit);
$showSwitch = $showSwitch ?? true;
?>
<div class="form-row">
    <div class="field">
        <label>Name</label>
        <input type="text" name="<?= $name('name') ?>" maxlength="150"
               value="<?= esc($val('name'), 'attr') ?>" placeholder="e.g. Jane Smith">
        <?php if ($e('name')): ?><div class="err"><?= esc($e('name')) ?></div><?php endif; ?>
    </div>
    <div class="field">
        <label>Position</label>
        <input type="text" name="<?= $name('role') ?>" maxlength="120"
               value="<?= esc($val('role'), 'attr') ?>" placeholder="e.g. Founding Partner">
        <?php if ($e('role')): ?><div class="err"><?= esc($e('role')) ?></div><?php endif; ?>
    </div>
</div>
<div class="field">
    <label>Qualifications</label>
    <input type="text" name="<?= $name('credentials') ?>" maxlength="255"
           value="<?= esc($val('credentials'), 'attr') ?>"
           placeholder="e.g. BA LLB (UCT), admitted attorney 1996">
    <?php if ($e('credentials')): ?><div class="err"><?= esc($e('credentials')) ?></div><?php endif; ?>
</div>
<?php // Hidden AND disabled with no regulator, so nothing of it posts. ?>
<?php $off = $regulator === null; ?>
<fieldset class="field team-registration" data-registration <?= $off ? 'hidden disabled' : '' ?>>
    <legend>Professional registration <span class="text-slate-400">(optional)</span></legend>
    <input type="hidden" name="<?= $name(ProfessionalRegistration::MARKER) ?>" value="1">
    <div class="form-row">
        <div class="field">
            <label><span data-reg-short><?= esc($regulator['short'] ?? '') ?></span> registration number</label>
            <input type="text" name="<?= $name('reg_number') ?>" maxlength="40" autocomplete="off"
                   value="<?= esc($val('reg_number'), 'attr') ?>" placeholder="<?= esc($regulator['example'] ?? '', 'attr') ?>">
            <?php if ($e('reg_number')): ?><div class="err"><?= esc($e('reg_number')) ?></div><?php endif; ?>
        </div>
    </div>
    <?php // Disabled with it, so a hidden ORCID box cannot fail a lawyer's save. ?>
    <fieldset class="form-row" data-reg-research <?= ($regulator['research'] ?? true) ? '' : 'hidden disabled' ?>>
        <div class="field">
            <label>ORCID iD</label>
            <input type="text" name="<?= $name('orcid') ?>" maxlength="60" autocomplete="off"
                   value="<?= esc($val('orcid'), 'attr') ?>" placeholder="0000-0002-1825-0097">
            <?php if ($e('orcid')): ?><div class="err"><?= esc($e('orcid')) ?></div><?php endif; ?>
        </div>
        <div class="field">
            <label>ResearchGate profile</label>
            <input type="text" name="<?= $name('researchgate_url') ?>" maxlength="255" inputmode="url"
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
        <input type="hidden" name="<?= $name('reg_checked_present') ?>" value="1">
        <label class="mt-2 block font-medium"><input type="checkbox" name="<?= $name('reg_checked') ?>" value="1" <?= ! empty($m['reg_checked_at']) ? 'checked' : '' ?>> Number <?= esc($val('reg_number')) ?> checked against the register</label>
        <?php if (! empty($m['reg_checked_at'])): ?><div class="hint">Checked <?= esc(date('j F Y', strtotime((string) $m['reg_checked_at']))) ?>.</div><?php endif; ?>
    <?php endif; ?>
</fieldset>
<div class="field">
    <label>Areas of focus</label>
    <input type="text" name="<?= $name('specializations') ?>" maxlength="500"
           value="<?= esc($val('specializations'), 'attr') ?>"
           placeholder="Comma separated, e.g. Conveyancing, Commercial litigation">
    <div class="hint">Separate with commas, up to 12 per person.</div>
    <?php if ($e('specializations')): ?><div class="err"><?= esc($e('specializations')) ?></div><?php endif; ?>
</div>
<div class="field">
    <label>Short bio</label>
    <textarea name="<?= $name('bio') ?>" rows="2" maxlength="1200"
              placeholder="A paragraph on what they do and who they help."><?= esc($val('bio')) ?></textarea>
    <?php if ($e('bio')): ?><div class="err"><?= esc($e('bio')) ?></div><?php endif; ?>
</div>
<div class="field">
    <label>Photo</label>
    <?php if (! empty($m['photo_path'])): ?>
        <img src="<?= esc(base_url($m['photo_path'])) ?>" alt="" width="48" height="48" class="team-avatar team-avatar-sm mb-2" loading="lazy">
    <?php endif; ?>
    <?php // accept="image/*" for the HEIC reason spelled out in _form_fields.php. ?>
    <input type="file" name="<?= esc($photoName, 'attr') ?>" accept="image/*">
    <div class="hint"><?= ! empty($m['photo_path']) ? 'Choosing a file replaces the current photo.' : 'Head and shoulders. Up to 10 MB, resized here.' ?></div>
    <?php if ($e('photo')): ?><div class="err"><?= esc($e('photo')) ?></div><?php endif; ?>
</div>
<?php if ($showSwitch): ?>
    <?php // The marker tells the writer the switch was on the form, so an unticked box means hidden. ?>
    <input type="hidden" name="<?= $name('show_present') ?>" value="1">
    <label class="field block font-medium">
        <input type="checkbox" name="<?= $name('show_on_profile') ?>" value="1" <?= (string) ($m['show_on_profile'] ?? '1') !== '0' ? 'checked' : '' ?>>
        Show on my profile
    </label>
    <?php if ($e('show_on_profile')): ?><div class="err"><?= esc($e('show_on_profile')) ?></div><?php endif; ?>
<?php endif; ?>

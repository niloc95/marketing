<?php

use App\Libraries\ProfessionalRegistration;

/**
 * Registration number, ORCID and ResearchGate, for regulated professions only.
 * See ProfessionalRegistration and Config\Registrations.
 *
 * One fieldset whatever the regulator: the chosen category names its regulator
 * on the <option> as data-regulator, and directory.js relabels the number field
 * and shows or hides the research links from data-regulators. Hidden AND
 * disabled for a category with none, as the menu field is, so the marker is
 * not posted and a stored number is left alone. Before a category is chosen it
 * is hidden too: there is no regulator to name yet.
 *
 * The switch posts a hidden 0 ahead of the checkbox, so an unticked box still
 * arrives in old input after a failed save rather than falling back to the
 * stored value.
 *
 * @var callable $v
 * @var callable $err
 * @var array    $categories rows with id, slug and group_name
 */
$regulator = null;
foreach ($categories as $c) {
    if ((string) $c['id'] === (string) $v('category_id')) {
        $regulator = ProfessionalRegistration::regulatorFor($c['slug'] ?? null, $c['group_name'] ?? null);
        break;
    }
}
$off      = $regulator === null;
$research = $regulator['research'] ?? true;
$labels   = array_map(
    static fn (array $r): array => ['short' => $r['short'], 'name' => $r['name'], 'example' => $r['example'], 'research' => $r['research']],
    config('Registrations')->regulators
);
$visible = $v('registration_visible') === '1';
$filled  = $v('reg_number') !== '' || $v('orcid') !== '' || $v('researchgate_url') !== '';
$errored = $err('reg_number') !== '' || $err('orcid') !== '' || $err('researchgate_url') !== '';
?>
<details class="disclosure" data-registration data-regulators="<?= esc(json_encode($labels, JSON_UNESCAPED_SLASHES), 'attr') ?>" <?= $off ? 'hidden' : '' ?> <?= $filled || $errored ? 'open' : '' ?>>
    <summary class="disclosure-summary">
        <span>Professional registration</span>
        <span class="hint">Your registration number and research profiles</span>
    </summary>
    <fieldset class="disclosure-body" <?= $off ? 'disabled' : '' ?>>
        <input type="hidden" name="<?= ProfessionalRegistration::MARKER ?>" value="1">
        <div class="field">
            <label for="field-reg-number"><span data-reg-short><?= esc($regulator['short'] ?? '') ?></span> registration number</label>
            <input type="text" id="field-reg-number" name="reg_number" value="<?= esc($v('reg_number'), 'attr') ?>" maxlength="40" autocomplete="off" placeholder="<?= esc($regulator['example'] ?? '', 'attr') ?>">
            <div class="hint">As it appears on the <span data-reg-name><?= esc($regulator['name'] ?? '') ?></span> register. Your profile links to the register, so customers can check it.</div>
            <?php if ($err('reg_number')): ?><div class="err"><?= esc($err('reg_number')) ?></div><?php endif; ?>
        </div>
        <?php // Disabled with it, so a hidden ORCID box cannot fail a lawyer's save. ?>
        <fieldset data-reg-research <?= $research ? '' : 'hidden disabled' ?>>
            <div class="field">
                <label for="field-orcid">ORCID iD</label>
                <input type="text" id="field-orcid" name="orcid" value="<?= esc($v('orcid'), 'attr') ?>" maxlength="60" autocomplete="off" placeholder="0000-0002-1825-0097">
                <div class="hint">Your 16 digit iD, or the link to your orcid.org page.</div>
                <?php if ($err('orcid')): ?><div class="err"><?= esc($err('orcid')) ?></div><?php endif; ?>
            </div>
            <div class="field">
                <label for="field-researchgate">ResearchGate profile</label>
                <input type="text" id="field-researchgate" name="researchgate_url" value="<?= esc($v('researchgate_url'), 'attr') ?>" maxlength="255" inputmode="url" placeholder="https://www.researchgate.net/profile/Your-Name">
                <?php if ($err('researchgate_url')): ?><div class="err"><?= esc($err('researchgate_url')) ?></div><?php endif; ?>
            </div>
        </fieldset>
        <div class="field">
            <input type="hidden" name="registration_visible" value="0">
            <label class="switch">
                <input type="checkbox" role="switch" name="registration_visible" value="1" <?= $visible ? 'checked' : '' ?>>
                <span>Show my registration and research profiles on my business profile</span>
            </label>
            <div class="hint">Nothing shows until you switch this on. What you enter is self declared: we show it as you typed it, beside a link to the register.</div>
        </div>
    </fieldset>
</details>

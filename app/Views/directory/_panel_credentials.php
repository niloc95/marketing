<?php
/**
 * Qualifications, registrations, accreditation. See _panel_description.php.
 *
 * The column is the renamed `qualifications` and is still free text, so the
 * heading is doing the work of saying what belongs in it — "Qualifications &
 * registrations" for a practice, "Certifications & registrations" for a trade,
 * "Admissions & accreditation" for a firm. It is also the panel the medical and
 * legal verticals put FIRST, because it is what a visitor is there to check.
 *
 * Above the free text, the structured registration line, when the owner has
 * switched it on (ProfessionalRegistration::forDisplay()). The number is
 * labelled self declared unless an admin has checked it, and always sits beside
 * the regulator's own lookup so the visitor can check it themselves.
 *
 * @var array $l
 * @var array $v
 */
$reg = \App\Libraries\ProfessionalRegistration::forDisplay($l);
if (empty($l['credentials']) && $reg === null) {
    return;
}
$link = 'font-medium text-primary-500 dark:text-primary-300 hover:underline';
?>
<div class="panel mb-5">
    <h3><?= esc($v['headings']['credentials']) ?></h3>
    <?php if ($reg !== null): ?>
        <ul class="mb-3 space-y-1 text-sm text-slate-700 dark:text-slate-300" data-registration-line>
            <?php if ($reg['number'] !== ''): ?>
                <li>
                    <abbr title="<?= esc($reg['name'], 'attr') ?>" class="no-underline"><?= esc($reg['short']) ?></abbr> registration
                    <strong class="text-slate-900 dark:text-white"><?= esc($reg['number']) ?></strong>
                    <span class="text-xs text-slate-500 dark:text-slate-400">(<?= $reg['checked'] ? 'checked by ' . esc(config('Directory')->siteName()) : 'self declared' ?>)</span>
                    <span aria-hidden="true">&middot;</span>
                    <a class="<?= $link ?>" href="<?= esc($reg['register'], 'attr') ?>" target="_blank" rel="noopener nofollow">Check on the <?= esc($reg['short']) ?> register</a>
                </li>
            <?php endif; ?>
            <?php if ($reg['orcid'] !== null || $reg['researchgate'] !== null): ?>
                <li>
                    Research profiles:
                    <?php if ($reg['orcid'] !== null): ?><a class="<?= $link ?>" href="<?= esc($reg['orcid'], 'attr') ?>" target="_blank" rel="noopener nofollow me">ORCID</a><?php endif; ?>
                    <?php if ($reg['orcid'] !== null && $reg['researchgate'] !== null): ?><span aria-hidden="true">&middot;</span><?php endif; ?>
                    <?php if ($reg['researchgate'] !== null): ?><a class="<?= $link ?>" href="<?= esc($reg['researchgate'], 'attr') ?>" target="_blank" rel="noopener nofollow me">ResearchGate</a><?php endif; ?>
                </li>
            <?php endif; ?>
        </ul>
    <?php endif; ?>
    <?php if (! empty($l['credentials'])): ?>
        <p class="whitespace-pre-line text-sm text-slate-700 dark:text-slate-300"><?= esc($l['credentials']) ?></p>
    <?php endif; ?>
</div>

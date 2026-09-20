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
 * @var array $l
 * @var array $v
 */
if (empty($l['credentials'])) {
    return;
}
?>
<div class="panel mb-5">
    <h3><?= esc($v['headings']['credentials']) ?></h3>
    <p class="whitespace-pre-line text-sm text-slate-700 dark:text-slate-300"><?= esc($l['credentials']) ?></p>
</div>

<?php
/**
 * The footer every campaign email ends with (emails/_footer.php), shown to the
 * admin on the campaign form and the preview page so it is never a surprise.
 * Same details (Config\Company), drawn with classes: the admin CSP refuses the
 * email's inline styles. Read only: the details are the company's, not a
 * campaign's, and change in Config\Company.
 */
$company = config('Company');
?>
<div class="comms-footer-preview" aria-label="Email footer">
    <p class="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">Added under every email</p>
    <div class="text-center text-xs leading-relaxed text-slate-500 dark:text-slate-400">
        <span class="brand mb-2 inline-flex justify-center">
            <img class="brand-mark" src="<?= esc(base_url($company->logoPath), 'attr') ?>" alt="" width="32" height="32">
            <span>WebScheduler <span class="text-brand-orange">Local</span></span>
        </span>
        <?php foreach ($company->legalLines() as $line): ?>
            <p><?= esc($line) ?></p>
        <?php endforeach; ?>
        <p class="mt-1"><?= esc($company->phone) ?> · <?= esc($company->email) ?> · <?= esc($company->websiteLabel()) ?></p>
    </div>
</div>

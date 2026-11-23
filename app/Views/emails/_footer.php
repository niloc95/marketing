<?php
/**
 * Logo and company details under the card of every customer email, drawn by
 * emails/_layout.php. Details from Config\Company, the same on both sites
 * (see its docblock). Inline styles, as in the layout: mail clients drop
 * <style>. The logo URL is absolute, or mail clients cannot load it.
 */
$company = config('Company');
$website = preg_replace('#^https?://|/$#i', '', $company->website);
?>
    <div style="max-width:520px;margin:20px auto 0;text-align:center;font-size:12px;line-height:1.6;color:#64748b">
        <p style="margin:0 0 8px">
            <img src="<?= esc(base_url($company->logoPath), 'attr') ?>" width="32" height="32" alt="" style="width:32px;height:32px;border:0;vertical-align:middle;border-radius:8px">
            <span style="vertical-align:middle;font-size:15px;font-weight:700;color:#003049;margin-left:6px">WebScheduler <span style="color:#F77F00">Local</span></span>
        </p>
        <p style="margin:0"><?= esc($company->name) ?>, registered in South Africa</p>
        <p style="margin:0">Registration number <?= esc($company->registration) ?></p>
        <p style="margin:4px 0 0">
            <a href="tel:<?= esc($company->phoneTel, 'attr') ?>" style="color:#64748b;text-decoration:none"><?= esc($company->phone) ?></a>
            &nbsp;&middot;&nbsp;
            <a href="mailto:<?= esc($company->email, 'attr') ?>" style="color:#64748b"><?= esc($company->email) ?></a>
            &nbsp;&middot;&nbsp;
            <a href="<?= esc($company->website, 'attr') ?>" style="color:#64748b"><?= esc($website) ?></a>
        </p>
    </div>

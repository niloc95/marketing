<?php
/**
 * The welcome pack on the owner's Plan page (Business\Owner::badge), shown
 * only while WelcomePackService::available() says yes (Verified or Plus).
 * A plain link with data-no-spa: the answer is a PDF, not a shell page.
 *
 * @var string $planName Verified or Plus
 */
?>
<div class="panel" id="welcome-pack">
    <h2 class="panel-title">Your welcome pack</h2>
    <p class="mt-2 text-sm text-slate-600 dark:text-slate-300">
        Part of <?= esc($planName) ?>: a printable copy of your profile, a QR poster for your reception or front door,
        four counter cards and a share kit. It is built from your live profile each time you download it.
    </p>
    <p class="mt-4">
        <a class="btn btn-primary" href="<?= esc(base_url('business/welcome-pack'), 'attr') ?>" target="_blank" rel="noopener" data-no-spa>Download the welcome pack (PDF)</a>
    </p>
</div>

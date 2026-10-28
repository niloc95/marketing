<?php
/**
 * The approve bar on the owner's private preview (Directory::preview()).
 *
 * Approve posts to preview/{token}/approve, which runs the same verify() the
 * usual email link does: it publishes, signs the owner in and takes them on.
 * Asking for changes mails the admin inbox and leaves the link working.
 *
 * @var array{token:string} $preview
 */
$base = base_url('preview/' . rawurlencode($preview['token']));
?>
<div class="alert alert-info mb-6" role="region" aria-label="Preview">
    <p class="font-semibold">This is a private preview of your business profile. It is not live yet.</p>
    <p class="mt-1 text-sm">Check that everything is right. When you approve it, your profile goes live straight away and you can still edit it later.</p>
    <div class="mt-3 flex flex-wrap items-start gap-3">
        <form method="post" action="<?= esc($base . '/approve', 'attr') ?>">
            <?= csrf_field() ?>
            <button type="submit" class="btn btn-accent">Approve and publish</button>
        </form>
        <details class="min-w-0 flex-1">
            <summary class="btn btn-ghost">Ask for changes</summary>
            <form method="post" action="<?= esc($base . '/changes', 'attr') ?>" class="mt-3">
                <?= csrf_field() ?>
                <label class="text-sm font-medium" for="preview-changes">What would you like us to change?</label>
                <textarea id="preview-changes" name="message" rows="4" maxlength="3000" required class="mt-1 w-full"></textarea>
                <button type="submit" class="btn btn-ghost btn-xs mt-2">Send to us</button>
            </form>
        </details>
    </div>
</div>

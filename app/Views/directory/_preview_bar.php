<?php
/**
 * The bar on top of a private profile preview. Three kinds:
 *
 * - approve (Directory::preview()): the owner's preview of a pending profile,
 *   from a preview we sent or the usual verification email. Approve posts to
 *   preview/{token}/approve, which runs the same verify() the old email link
 *   did: it publishes, signs the owner in and takes them on. "Ask for changes"
 *   (a profile we built only) mails the admin inbox and leaves the link working.
 * - draft (Manage::preview()): what the owner's edit form holds right now,
 *   saved or not. Nothing was written; the bar says what the page cannot show.
 * - admin (Admin::preview()): any profile, whatever its status.
 *
 * @var array{mode:string,token?:string,changes?:bool,errors?:array<string,string>,status?:string,editUrl?:string,liveUrl?:string} $preview
 */
$mode = $preview['mode'] ?? 'approve';
?>
<?php if ($mode === 'draft'): ?>
    <div class="alert alert-info mb-6" role="region" aria-label="Preview">
        <p class="font-semibold">This is a preview of your unsaved changes. Nothing has changed on your profile yet.</p>
        <p class="mt-1 text-sm">Close this tab and press Save to keep them. New photos, a new logo, team members and branches show once you save.</p>
        <?php if (! empty($preview['errors'])): ?>
            <p class="mt-3 text-sm font-semibold">These need fixing before you can save:</p>
            <ul class="mt-1 list-disc pl-5 text-sm">
                <?php foreach (array_unique($preview['errors']) as $message): ?>
                    <li><?= esc($message) ?></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>
<?php elseif ($mode === 'admin'): ?>
    <div class="alert alert-info mb-6" role="region" aria-label="Preview">
        <p class="font-semibold">Admin preview. Status: <?= esc(ucfirst((string) ($preview['status'] ?? ''))) ?></p>
        <p class="mt-1 text-sm">
            This is the profile as it would look live, whatever its status.
            <a class="underline" href="<?= esc($preview['editUrl'] ?? '', 'attr') ?>">Edit this profile</a>
            <?php if (! empty($preview['liveUrl'])): ?>
                &middot; <a class="underline" href="<?= esc($preview['liveUrl'], 'attr') ?>">Open the live page</a>
            <?php endif; ?>
        </p>
    </div>
<?php else: ?>
    <?php $base = base_url('preview/' . rawurlencode($preview['token'])); ?>
    <div class="alert alert-info mb-6" role="region" aria-label="Preview">
        <p class="font-semibold">This is a private preview of your business profile. It is not live yet.</p>
        <p class="mt-1 text-sm">Check that everything is right. When you approve it, your profile goes live straight away and you can still edit it later.</p>
        <div class="mt-3 flex flex-wrap items-start gap-3">
            <form method="post" action="<?= esc($base . '/approve', 'attr') ?>">
                <?= csrf_field() ?>
                <button type="submit" class="btn btn-accent">Approve and publish</button>
            </form>
            <?php if ($preview['changes'] ?? true): ?>
                <details class="min-w-0 flex-1">
                    <summary class="btn btn-ghost">Ask for changes</summary>
                    <form method="post" action="<?= esc($base . '/changes', 'attr') ?>" class="mt-3">
                        <?= csrf_field() ?>
                        <label class="text-sm font-medium" for="preview-changes">What would you like us to change?</label>
                        <textarea id="preview-changes" name="message" rows="4" maxlength="3000" required class="mt-1 w-full"></textarea>
                        <button type="submit" class="btn btn-ghost btn-xs mt-2">Send to us</button>
                    </form>
                </details>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>

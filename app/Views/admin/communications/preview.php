<?= $this->extend('layouts/admin') ?>

<?= $this->section('head') ?>
<?= seo_meta(['title' => 'Preview | Admin']) ?>
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<?php
/**
 * A campaign rendered for one profile. The email itself carries inline
 * styles, which the admin policy refuses, so it opens on its own page
 * (AdminCommunications::raw, locked policy) rather than inside this one.
 *
 * @var array<string,mixed>      $campaign
 * @var array<string,mixed>|null $email     CommunicationsService::preview()
 * @var int                      $listingId
 */
$id    = (int) $campaign['id'];
$query = $listingId > 0 ? '?listing=' . $listingId : '';
?>
<div class="shell-page">
    <nav class="crumbs" aria-label="Breadcrumb">
        <a href="<?= base_url('admin/communications') ?>">Communications</a>
        <span class="crumbs-sep">/</span>
        <a href="<?= base_url('admin/communications/' . $id) ?>"><?= esc($campaign['name']) ?></a>
        <span class="crumbs-sep">/</span>
        <span>Preview</span>
    </nav>

    <form method="get" action="<?= base_url('admin/communications/' . $id . '/preview') ?>" class="mb-5 flex flex-wrap items-end gap-2">
        <div class="field mb-0">
            <label for="p-listing">Render for profile id</label>
            <input type="number" id="p-listing" name="listing" min="1" value="<?= $listingId > 0 ? $listingId : '' ?>" placeholder="First in audience">
        </div>
        <button type="submit" class="btn btn-ghost">Show</button>
    </form>

    <?php if ($email === null): ?>
        <div class="empty">Nobody to render it for: the audience is empty, or that profile id does not exist.</div>
    <?php else: ?>
        <div class="form-card">
            <p class="mb-1 text-sm text-slate-500 dark:text-slate-400">To</p>
            <p class="mb-3"><a href="<?= base_url('admin/edit/' . (int) $email['listing']['id']) ?>"><?= esc($email['listing']['display_name']) ?></a> (<?= esc((string) $email['listing']['email']) ?>)</p>
            <p class="mb-1 text-sm text-slate-500 dark:text-slate-400">Subject</p>
            <p class="mb-5 font-semibold text-slate-900 dark:text-white"><?= esc($email['subject']) ?></p>
            <a class="btn btn-primary" href="<?= base_url('admin/communications/' . $id . '/raw' . $query) ?>" target="_blank" rel="noopener" data-no-spa>Open the email as sent</a>
            <p class="mt-3 text-xs text-slate-500 dark:text-slate-400">The preview button links to the sign in page; the real email carries a one use sign in link minted at send time.</p>
        </div>
    <?php endif; ?>
</div>
<?= $this->endSection() ?>

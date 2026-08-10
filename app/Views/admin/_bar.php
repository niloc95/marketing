<?php
/**
 * Admin nav bar.
 *
 * Was copy-pasted byte-identically into index/categories/edit, which is how a
 * fourth link ends up on two pages out of three. One copy now.
 */

// The count is the point of the link: a verification queue nobody is reminded
// of is a queue people wait in. Counted here rather than passed in by each
// controller, so the four pages that render this bar do not each have to
// remember to fetch it.
$awaitingReview = (new App\Models\DirectoryVerificationModel())
    ->where('state', App\Models\DirectoryVerificationModel::STATE_SUBMITTED)
    ->countAllResults();
?>
<div class="admin-bar">
    <div class="container">
        <strong><?= esc(config('Directory')->siteName()) ?> admin</strong>
        <span>
            <a href="<?= base_url('admin') ?>">Profiles</a> &middot;
            <a href="<?= base_url('admin/verifications') ?>">Verification<?= $awaitingReview > 0 ? ' (' . (int) $awaitingReview . ')' : '' ?></a> &middot;
            <a href="<?= base_url('admin/categories') ?>">Categories</a> &middot;
            <a href="<?= base_url('admin/settings') ?>">Settings</a> &middot;
            <a href="<?= base_url('admin/status') ?>">Status</a> &middot;
            <a href="<?= base_url('admin/logout') ?>">Sign out</a>
        </span>
    </div>
</div>

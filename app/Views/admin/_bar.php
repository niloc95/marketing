<?php
/**
 * Admin nav bar.
 *
 * Was copy-pasted byte-identically into index/categories/edit, which is how a
 * fourth link ends up on two pages out of three. One copy now.
 */
?>
<div class="admin-bar">
    <div class="container">
        <strong>Directory admin</strong>
        <span>
            <a href="<?= base_url('admin') ?>">Listings</a> &middot;
            <a href="<?= base_url('admin/categories') ?>">Categories</a> &middot;
            <a href="<?= base_url('admin/status') ?>">Status</a> &middot;
            <a href="<?= base_url('admin/logout') ?>">Sign out</a>
        </span>
    </div>
</div>

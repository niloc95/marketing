<?php
/** Jobs (Business\Owner::jobs): the business's posts on the jobs board and its lead alerts. */
?>
<?= $this->extend('layouts/business') ?>
<?= $this->section('content') ?>
<div class="shell-page">
    <?= view('directory/_jobs_panel', ['listing' => $listing, 'posts' => $posts, 'svc' => $svc], ['saveData' => false]) ?>
</div>
<?= $this->endSection() ?>

<?php // Loads directory.js (layouts/business.php). ?>
<?= $this->section('profile_form') ?>1<?= $this->endSection() ?>

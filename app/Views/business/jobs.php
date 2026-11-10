<?php
/** Jobs (Business\Owner::jobs): the business's posts on the jobs board and its lead alerts. */
?>
<?= $this->extend('layouts/business') ?>
<?= $this->section('content') ?>
<div class="mx-auto w-full max-w-3xl">
    <?= view('directory/_jobs_panel', ['listing' => $listing, 'posts' => $posts, 'svc' => $svc], ['saveData' => false]) ?>
</div>
<?= $this->endSection() ?>

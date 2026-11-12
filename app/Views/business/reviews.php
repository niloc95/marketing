<?php
/** Reviews (Business\Owner::reviews): switch reviews on or off, reply and report. */
?>
<?= $this->extend('layouts/business') ?>
<?= $this->section('content') ?>
<div class="shell-page">
    <?= view('directory/_reviews_panel', ['listing' => $listing, 'reviews' => $reviews], ['saveData' => false]) ?>
    <?= view('directory/_pager', ['result' => $pager, 'baseUrl' => base_url('business/reviews'), 'query' => [], 'noun' => 'reviews'], ['saveData' => false]) ?>
</div>
<?= $this->endSection() ?>

<?php // Loads directory.js (layouts/business.php). ?>
<?= $this->section('profile_form') ?>1<?= $this->endSection() ?>

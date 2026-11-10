<?php
/** Reviews (Business\Owner::reviews): switch reviews on or off, reply and report. */
?>
<?= $this->extend('layouts/business') ?>
<?= $this->section('content') ?>
<div class="mx-auto w-full max-w-3xl">
    <?= view('directory/_reviews_panel', ['listing' => $listing, 'reviews' => $reviews], ['saveData' => false]) ?>
</div>
<?= $this->endSection() ?>

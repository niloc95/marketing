<?php
/**
 * Plan and badge (Business\Owner::badge): the Verified Business badge on
 * co.za, Plus on .app, and the International Listing plan where it applies,
 * then the profile setup service. The same panels the old /manage/edit page
 * showed; their forms post to manage/verification/*.
 */
?>
<?= $this->extend('layouts/business') ?>
<?= $this->section('content') ?>
<div class="shell-page space-y-6">
    <?php // Hosting first: when it is unpaid the profile is not public at all. ?>
    <?php if ($hostingRequired): ?>
        <?= view('directory/_hosting_panel', [
            'listing'      => $listing,
            'subscription' => $hosting,
            'amount'       => $hostingAmount,
            'payable'      => $verificationPayable,
            'pending'      => $hostingPending,
        ]) ?>
    <?php endif ?>

    <?php if (! empty($plusShown)): ?>
        <?= view('directory/_plus_panel', [
            'listing'      => $listing,
            'subscription' => $hosting,
            'amount'       => $hostingAmount,
            'usd'          => $plusUsd ?? null,
            'usdYearly'    => $plusUsdYearly ?? null,
            'payable'      => $verificationPayable,
            'pending'      => $hostingPending,
        ]) ?>
    <?php endif ?>

    <?php if ($verificationOffered): ?>
        <?= view('directory/_verification_panel', [
            'verification' => $verification,
            'amount'       => $verificationAmount,
            'yearly'       => $verificationYearly ?? null,
            'payable'      => $verificationPayable,
            'pending'      => $verificationPending,
        ]) ?>
    <?php endif ?>

    <?= view('directory/_profile_setup_offer') ?>
</div>
<?= $this->endSection() ?>

<?php // Loads directory.js (layouts/business.php). ?>
<?= $this->section('profile_form') ?>1<?= $this->endSection() ?>

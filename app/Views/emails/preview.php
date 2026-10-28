<?= $this->extend('emails/_layout') ?>
<?= $this->section('content') ?>
        <?php // Sent by DirectoryListingMutationService::sendPreview(). The link is
              // a verify link, so the one button both shows the profile and,
              // from that page, publishes it. ?>
        <h1 style="color:#003049;font-size:20px;margin:0 0 12px"><?= esc($site) ?></h1>
        <p style="font-size:15px;line-height:1.6">Hi <?= esc($name) ?>,</p>
        <p style="font-size:15px;line-height:1.6">We've set up the business profile for <strong><?= esc($business) ?></strong>. Before it goes live, please have a look and check everything is right.</p>
        <p style="margin:22px 0">
            <?= email_button($link, 'Preview my business profile') ?>
        </p>
        <p style="font-size:15px;line-height:1.6">If you're happy, click <strong>Approve and publish</strong> at the top of the preview and your profile goes live straight away. If something needs changing, tell us on the same page and we'll fix it.</p>
        <p style="font-size:13px;color:#64748b">Only people with this link can see the preview, so please don't share it. It works for <?= (int) $days ?> days.</p>
        <p style="font-size:13px;color:#64748b">Or paste this link into your browser:<br><a href="<?= esc($link) ?>" style="color:#003049;word-break:break-all"><?= esc($link) ?></a></p>
<?= $this->endSection() ?>

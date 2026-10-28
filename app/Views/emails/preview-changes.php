<?= $this->extend('emails/_layout') ?>
<?= $this->section('content') ?>
        <h1 style="color:#003049;font-size:20px;margin:0 0 12px">Changes requested</h1>
        <p style="font-size:15px;line-height:1.6">The owner of <strong><?= esc($listing['display_name']) ?></strong> (<?= esc($listing['email']) ?>) looked at their preview and asked for changes:</p>
        <p style="font-size:15px;line-height:1.6;white-space:pre-line;border-left:3px solid #cbd5e1;padding-left:12px"><?= esc($message) ?></p>
        <p style="margin:22px 0"><?= email_button($editUrl, 'Edit the profile') ?></p>
        <p style="font-size:13px;color:#64748b">Their preview link still works, so once it's fixed they can approve it. Send a new preview from the admin list if you'd like them to get a fresh email.</p>
<?= $this->endSection() ?>

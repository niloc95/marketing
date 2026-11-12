<?php
/**
 * A visitor's message to a business, relayed by ListingMessageService.
 * Reply-To is the visitor, so the business answers them directly and we drop
 * out of the conversation. The business's address was never shown.
 *
 * @var string               $site
 * @var string               $heading
 * @var string               $intro
 * @var array<string,string> $fields  label => value, all escaped
 * @var string               $message
 * @var list<string>         $files   names of the attached files, if any
 * @var string               $profile the business profile's URL
 * @var string               $footnote
 */
$files = $files ?? [];
?>
<?= $this->extend('emails/_layout') ?>
<?= $this->section('content') ?>
        <p style="font-size:13px;color:#64748b;margin:0 0 6px"><?= esc($site) ?></p>
        <h1 style="color:#003049;font-size:20px;margin:0 0 12px"><?= esc($heading) ?></h1>
        <p style="font-size:15px;line-height:1.6"><?= esc($intro) ?></p>

        <table style="font-size:15px;line-height:1.6;border-collapse:collapse">
            <?php foreach ($fields as $label => $value): ?>
                <tr>
                    <td style="color:#64748b;padding-right:14px;vertical-align:top"><?= esc($label) ?></td>
                    <td><?= esc($value) ?></td>
                </tr>
            <?php endforeach; ?>
        </table>

        <?php // esc() first, then nl2br, so the sender's text cannot inject markup. ?>
        <div style="margin:22px 0;padding:16px;background:#f8fafc;border-radius:10px;font-size:15px;line-height:1.6">
            <?= nl2br(esc($message)) ?>
        </div>

        <?php if ($files !== []): ?>
            <p style="font-size:14px;line-height:1.6;margin:0 0 16px"><strong>Attached:</strong> <?= esc(implode(', ', $files)) ?>. Open attachments only if you expected them; we checked the file types but cannot vouch for what is inside.</p>
        <?php endif; ?>

        <p style="font-size:13px;color:#64748b"><?= esc($footnote) ?></p>
        <p style="font-size:13px;color:#64748b">Sent from your profile: <a href="<?= esc($profile, 'attr') ?>" style="color:#003049"><?= esc($profile) ?></a>. You can choose whether your email address shows on your profile under Details in your profile dashboard.</p>
<?= $this->endSection() ?>

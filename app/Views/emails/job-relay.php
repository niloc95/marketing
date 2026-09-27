<?php
/**
 * A message passed from one person to another: a job application, or a
 * business replying to a service request. Reply-To is set to the sender, so
 * the recipient answers them directly and we drop out of the conversation.
 *
 * @var string               $site
 * @var string               $heading
 * @var string               $intro
 * @var array<string,string> $fields  label => value; values that look like
 *                                    links are linked, everything is escaped
 * @var string               $message
 * @var string               $footnote
 */
?>
<!DOCTYPE html>
<html lang="en">
<body style="font-family:Inter,Arial,sans-serif;color:#0f172a;background:#f8fafc;padding:24px">
    <div style="max-width:520px;margin:0 auto;background:#fff;border:1px solid #e2e8f0;border-radius:14px;padding:28px">
        <p style="font-size:13px;color:#64748b;margin:0 0 6px"><?= esc($site) ?> Jobs</p>
        <h1 style="color:#003049;font-size:20px;margin:0 0 12px"><?= esc($heading) ?></h1>
        <p style="font-size:15px;line-height:1.6"><?= esc($intro) ?></p>

        <table style="font-size:15px;line-height:1.6;border-collapse:collapse">
            <?php foreach ($fields as $label => $value): ?>
                <tr>
                    <td style="color:#64748b;padding-right:14px;vertical-align:top"><?= esc($label) ?></td>
                    <td>
                        <?php if (preg_match('#^https?://#i', $value) === 1): ?>
                            <a href="<?= esc($value, 'attr') ?>" style="color:#003049"><?= esc($value) ?></a>
                        <?php else: ?>
                            <?= esc($value) ?>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </table>

        <?php // esc() first, then nl2br, so the sender's text cannot inject markup. ?>
        <div style="margin:22px 0;padding:16px;background:#f8fafc;border-radius:10px;font-size:15px;line-height:1.6">
            <?= nl2br(esc($message)) ?>
        </div>

        <p style="font-size:13px;color:#64748b"><?= esc($footnote) ?></p>
    </div>
</body>
</html>

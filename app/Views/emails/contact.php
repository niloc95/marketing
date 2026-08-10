<!DOCTYPE html>
<html lang="en">
<body style="font-family:Inter,Arial,sans-serif;color:#0f172a;background:#f8fafc;padding:24px">
    <div style="max-width:520px;margin:0 auto;background:#fff;border:1px solid #e2e8f0;border-radius:14px;padding:28px">
        <h1 style="color:#003049;font-size:20px;margin:0 0 12px">Contact form &mdash; <?= esc($site) ?></h1>

        <table style="font-size:15px;line-height:1.6;border-collapse:collapse">
            <tr><td style="color:#64748b;padding-right:14px;vertical-align:top">From</td><td><?= esc($name) ?></td></tr>
            <tr><td style="color:#64748b;padding-right:14px;vertical-align:top">Email</td><td><a href="mailto:<?= esc($email, 'attr') ?>" style="color:#003049"><?= esc($email) ?></a></td></tr>
            <?php if ($subject !== ''): ?>
                <tr><td style="color:#64748b;padding-right:14px;vertical-align:top">Subject</td><td><?= esc($subject) ?></td></tr>
            <?php endif; ?>
        </table>

        <?php // nl2br over an escaped string: esc() first so the message body can
              // never inject markup, then turn the visitor's own line breaks back
              // into <br> so a paragraphed message stays readable. ?>
        <div style="margin:22px 0;padding:16px;background:#f8fafc;border-radius:10px;font-size:15px;line-height:1.6">
            <?= nl2br(esc($message)) ?>
        </div>

        <p style="font-size:13px;color:#64748b">
            Reply to this email and it goes straight back to <?= esc($name) ?> &mdash; the Reply-To is already set.
        </p>
        <p style="font-size:12px;color:#94a3b8">Sent from <?= esc($ip) ?></p>
    </div>
</body>
</html>

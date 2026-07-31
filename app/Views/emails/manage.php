<!DOCTYPE html>
<html lang="en">
<body style="font-family:Inter,Arial,sans-serif;color:#0f172a;background:#f8fafc;padding:24px">
    <div style="max-width:520px;margin:0 auto;background:#fff;border:1px solid #e2e8f0;border-radius:14px;padding:28px">
        <h1 style="color:#003049;font-size:20px;margin:0 0 12px"><?= esc($site) ?></h1>
        <p style="font-size:15px;line-height:1.6">Hi <?= esc($name) ?>,</p>
        <p style="font-size:15px;line-height:1.6">Use the button below to edit your directory listing. No password needed &mdash; this link signs you in.</p>
        <p style="margin:22px 0">
            <a href="<?= esc($link, 'attr') ?>" style="background:#F77F00;color:#fff;text-decoration:none;font-weight:700;padding:12px 22px;border-radius:11px;display:inline-block">Edit my listing</a>
        </p>
        <p style="font-size:13px;color:#64748b">Or paste this link into your browser:<br><a href="<?= esc($link, 'attr') ?>" style="color:#003049"><?= esc($link) ?></a></p>
        <p style="font-size:13px;color:#64748b">The link works once and expires in <?= (int) $ttl ?> minutes. If you didn't request it, you can safely ignore this email &mdash; nothing has changed.</p>
    </div>
</body>
</html>

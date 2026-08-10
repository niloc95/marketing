<!DOCTYPE html>
<html lang="en">
<body style="font-family:Inter,Arial,sans-serif;color:#0f172a;background:#f8fafc;padding:24px">
    <div style="max-width:520px;margin:0 auto;background:#fff;border:1px solid #e2e8f0;border-radius:14px;padding:28px">
        <h1 style="color:#003049;font-size:20px;margin:0 0 12px"><?= esc($site) ?></h1>
        <p style="font-size:15px;line-height:1.6">Hi <?= esc($name) ?>,</p>
        <p style="font-size:15px;line-height:1.6">Thanks for adding your business. Click below to verify your email and publish your profile:</p>
        <p style="margin:22px 0">
            <a href="<?= esc($link, 'attr') ?>" style="background:#F77F00;color:#fff;text-decoration:none;font-weight:700;padding:12px 22px;border-radius:11px;display:inline-block">Verify &amp; publish my profile</a>
        </p>
        <p style="font-size:13px;color:#64748b">Or paste this link into your browser:<br><a href="<?= esc($link, 'attr') ?>" style="color:#003049"><?= esc($link) ?></a></p>
        <p style="font-size:13px;color:#64748b">If you didn't request this, you can ignore this email.</p>
        <?php // Deliberately after the ignore-notice and in muted type: the one job
              // of this email is getting the link clicked, and a second call to
              // action competing with it would cost more signups than it wins
              // badges. $offerBadge is defaulted so any caller that has not been
              // updated simply says nothing. ?>
        <?php if ($offerBadge ?? false): ?>
            <hr style="border:0;border-top:1px solid #e2e8f0;margin:22px 0">
            <p style="font-size:13px;color:#64748b">
                <strong style="color:#0f172a">Once you're published:</strong> you can apply for a
                <strong style="color:#0f172a">Verified Business</strong> badge &mdash; we check your company
                registration document and the owner's ID, and your profile carries the badge in search results
                for R<?= esc($badgePrice ?? '') ?> a month. Your listing stays free either way.
                <a href="<?= esc(base_url('verified'), 'attr') ?>" style="color:#003049">What we check &rarr;</a>
            </p>
        <?php endif; ?>
    </div>
</body>
</html>

<!DOCTYPE html>
<html lang="en">
<body style="font-family:Inter,Arial,sans-serif;color:#0f172a;background:#f8fafc;padding:24px">
    <div style="max-width:520px;margin:0 auto;background:#fff;border:1px solid #e2e8f0;border-radius:14px;padding:28px">
        <h1 style="color:#003049;font-size:20px;margin:0 0 12px"><?= esc($site) ?></h1>
        <p style="font-size:15px;line-height:1.6">Hi <?= esc($name) ?>,</p>
        <p style="font-size:15px;line-height:1.6">Thanks for creating your business profile. Click below to verify your email and publish it:</p>
        <p style="margin:22px 0">
            <a href="<?= esc($link) ?>" style="background:#F77F00;color:#fff;text-decoration:none;font-weight:700;padding:12px 22px;border-radius:11px;display:inline-block">Verify &amp; publish my profile</a>
        </p>
        <p style="font-size:13px;color:#64748b">Or paste this link into your browser:<br><a href="<?= esc($link) ?>" style="color:#003049;word-break:break-all"><?= esc($link) ?></a></p>
        <p style="font-size:13px;color:#64748b">If you didn't request this, you can ignore this email.</p>
        <?php // A proper section now, not a footnote: Verified is the offer we lead
              // with. Still no second button, though. The one job of this email is
              // getting the link above clicked, and that same click now carries on
              // to the badge application (Directory::verify() lands a listing that
              // has not applied on manage/edit#get-verified). $offerBadge is
              // defaulted so any caller that has not been updated says nothing. ?>
        <?php if ($offerBadge ?? false): ?>
            <div style="margin-top:22px;border:1px solid #6ee7b7;border-radius:12px;padding:16px 18px;background:#ecfdf5">
                <p style="font-size:15px;font-weight:700;color:#065f46;margin:0 0 8px">Next: get the Verified Business badge</p>
                <ul style="font-size:14px;line-height:1.6;color:#0f172a;margin:0 0 10px;padding-left:18px">
                    <li>A green <strong>Verified Business</strong> badge on your profile and beside your name in search results</li>
                    <li>All your locations: your other branches or practices, each with its own address, phone, map pin and hours, and described to Google as a location in its own right</li>
                    <li>Your people by name, with photos, qualifications and areas of expertise, so searches for your people find you</li>
                    <li>Post jobs on our Jobs board, and reply to customers who need your kind of service</li>
                    <li>First to hear about new work: verified businesses are the first we alert when a customer in your province asks for your kind of service</li>
                </ul>
                <p style="font-size:13px;line-height:1.6;color:#334155;margin:0">
                    R<?= esc($badgePrice ?? '') ?> a month, and nothing to pay until we've checked your company
                    registration and the owner's ID. If you haven't sent them yet, confirming above takes you
                    straight there. Your business profile stays free either way.
                    <a href="<?= esc(base_url('verified')) ?>" style="color:#065f46">What we check &rarr;</a>
                </p>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>

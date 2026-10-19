<?php
/**
 * "Are you open on <holiday>?": the public holiday hours reminder, sent by
 * HolidayHoursService to owners who have not answered yet. Same card chrome
 * as profile-strength.php, and the same stop link: it is an email about the
 * owner's own profile.
 *
 * @var string $site
 * @var string $name
 * @var string $holiday     "Heritage Day"
 * @var string $holidayDate "Wednesday 24 September"
 * @var list<string> $upcoming more holidays coming soon, "Christmas Day, Thursday 25 December"
 * @var string $profileUrl
 * @var string $manageLink
 * @var string $unsubscribe
 */
$profileLabel = preg_replace('#^https?://#i', '', $profileUrl);
?>
<!DOCTYPE html>
<html lang="en">
<body style="font-family:Inter,Arial,sans-serif;color:#0f172a;background:#f8fafc;padding:24px">
    <div style="max-width:520px;margin:0 auto;background:#fff;border:1px solid #e2e8f0;border-radius:14px;padding:28px">
        <h1 style="color:#003049;font-size:20px;margin:0 0 12px"><?= esc($site) ?></h1>
        <p style="font-size:15px;line-height:1.6">Hi <?= esc($name) ?>,</p>

        <p style="font-size:15px;line-height:1.6">
            <strong><?= esc($holiday) ?></strong> is on <strong><?= esc($holidayDate) ?></strong>.
            Customers check whether a business is open before they travel, and your profile at
            <a href="<?= esc($profileUrl) ?>" style="color:#003049;font-weight:600"><?= esc($profileLabel) ?></a>
            does not say yet what you do on public holidays.
        </p>
        <p style="font-size:15px;line-height:1.6">
            Until you do, on the day it tells visitors your hours may differ and to call ahead,
            and you will not appear when people look for businesses open on the holiday.
        </p>

        <?php if ($upcoming !== []): ?>
            <p style="font-size:14px;line-height:1.6;background:#f1f5f9;border-radius:8px;padding:12px 16px">
                Also coming up:<br>
                <?php foreach ($upcoming as $line): ?><?= esc($line) ?><br><?php endforeach; ?>
            </p>
        <?php endif; ?>

        <p style="margin:22px 0">
            <a href="<?= esc($manageLink) ?>" style="background:#F77F00;color:#fff;text-decoration:none;font-weight:700;padding:12px 22px;border-radius:11px;display:inline-block">Set my holiday hours</a>
        </p>
        <p style="font-size:13px;color:#64748b">
            That button signs you straight in, no password. Open <strong>Opening hours</strong> and choose an answer
            under Public holidays. One answer covers every holiday, and you can set a few to be different.
            It takes a minute.
        </p>

        <p style="font-size:13px;color:#64748b">
            You're getting this because your business has a profile on <?= esc($site) ?>.
            We stop asking once you have answered.
            <a href="<?= esc($unsubscribe) ?>" style="color:#64748b">Stop emails about my profile</a>.
        </p>
    </div>
</body>
</html>

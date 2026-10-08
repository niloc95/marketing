<?php
/**
 * The one-off "finish your profile" email — see ProfileNudgeService.
 *
 * Same card chrome as verification-owner.php. Lists the few outstanding rubric
 * steps that are worth the most, straight from ListingQualityService::strength(),
 * so the email can never promise points the score does not pay.
 *
 * @var string $site
 * @var string $name
 * @var int    $score
 * @var int    $max
 * @var int    $target
 * @var list<array{label:string,points:int,earned:int,hint:string}> $steps
 * @var string $profileUrl
 * @var bool   $hasWebsite
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
            Your profile has been live for a few days at
            <a href="<?= esc($profileUrl) ?>" style="color:#003049;font-weight:600"><?= esc($profileLabel) ?></a>.
            It scores <strong><?= $score ?> out of <?= $max ?></strong> for completeness right now.
        </p>
        <p style="font-size:15px;line-height:1.6">
            Profiles at <strong><?= $target ?> or more</strong> appear higher in search, can be featured on
            the home page and are shown to Google. These would get you there fastest:
        </p>

        <table role="presentation" cellpadding="0" cellspacing="0" style="width:100%;border-collapse:collapse;margin:8px 0 4px">
            <?php foreach ($steps as $step): ?>
                <tr>
                    <td style="padding:10px 0;border-top:1px solid #e2e8f0;font-size:14px;line-height:1.5">
                        <strong><?= esc($step['label']) ?></strong><br>
                        <span style="color:#64748b"><?= esc($step['hint']) ?></span>
                    </td>
                    <td style="padding:10px 0 10px 12px;border-top:1px solid #e2e8f0;font-size:14px;font-weight:700;color:#F77F00;text-align:right;white-space:nowrap;vertical-align:top">
                        +<?= (int) $step['points'] - (int) $step['earned'] ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </table>

        <p style="margin:22px 0">
            <a href="<?= esc($manageLink) ?>" style="background:#F77F00;color:#fff;text-decoration:none;font-weight:700;padding:12px 22px;border-radius:11px;display:inline-block">Finish my profile</a>
        </p>
        <p style="font-size:13px;color:#64748b">
            That button signs you straight in — no password. It works once, and expires in 7 days.
            Nothing on this list costs money.
        </p>

        <?php if (! $hasWebsite): ?>
            <p style="font-size:15px;line-height:1.6;background:#f1f5f9;border-radius:8px;padding:12px 16px">
                <strong>No website? You don't need one.</strong> Your profile address works as your
                website — put <?= esc($profileLabel) ?> on your Google Business Profile, Facebook page,
                email signature and business cards.
            </p>
        <?php endif; ?>

        <p style="font-size:13px;color:#64748b">
            You're getting this once, because your business has a profile on <?= esc($site) ?>.
            <a href="<?= esc($unsubscribe) ?>" style="color:#64748b">Stop emails about my profile</a>.
        </p>
    </div>
</body>
</html>

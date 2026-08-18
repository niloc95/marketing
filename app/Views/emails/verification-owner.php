<?php
/**
 * The four things we say to an owner about their Verified Business badge, in
 * one template rather than four near-identical files. They share a shell and
 * differ by a paragraph; splitting them would mean four copies of the card
 * chrome drifting apart.
 *
 * @var string $site
 * @var string $name
 * @var string $event      approved|rejected|activated|renewing
 * @var string $manageLink
 * @var string $reason     rejected only
 * @var string $amount     approved and renewing only
 * @var string $paidUntil  renewing only
 */
$amount    = $amount ?? '';
$reason    = $reason ?? '';
$paidUntil = $paid_until ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<body style="font-family:Inter,Arial,sans-serif;color:#0f172a;background:#f8fafc;padding:24px">
    <div style="max-width:520px;margin:0 auto;background:#fff;border:1px solid #e2e8f0;border-radius:14px;padding:28px">
        <h1 style="color:#003049;font-size:20px;margin:0 0 12px"><?= esc($site) ?></h1>
        <p style="font-size:15px;line-height:1.6">Hi <?= esc($name) ?>,</p>

        <?php if ($event === 'approved'): ?>
            <p style="font-size:15px;line-height:1.6">
                Good news — we've checked your company registration document and owner ID, and both are in order.
            </p>
            <p style="font-size:15px;line-height:1.6">
                Your Verified Business badge is ready to switch on. It costs
                <strong>R<?= esc($amount) ?> a month</strong>, and it appears on your profile and
                everywhere your business shows up in search results.
            </p>
            <p style="margin:22px 0">
                <a href="<?= esc($manageLink, 'attr') ?>" style="background:#F77F00;color:#fff;text-decoration:none;font-weight:700;padding:12px 22px;border-radius:11px;display:inline-block">Activate my badge</a>
            </p>
            <p style="font-size:13px;color:#64748b">
                That button takes you straight to the payment page — no password, nothing else to fill in.
                It works once, and expires in 7 days.
            </p>

        <?php elseif ($event === 'rejected'): ?>
            <p style="font-size:15px;line-height:1.6">
                We weren't able to verify your business from the documents you sent. Here's why:
            </p>
            <p style="font-size:15px;line-height:1.6;background:#fef2f2;border-left:3px solid #dc2626;padding:12px 16px;border-radius:6px">
                <?= nl2br(esc($reason)) ?>
            </p>
            <p style="font-size:15px;line-height:1.6">
                You're welcome to send new documents whenever you're ready — nothing has been charged,
                and your listing itself is unaffected.
            </p>
            <p style="margin:22px 0">
                <a href="<?= esc($manageLink, 'attr') ?>" style="background:#F77F00;color:#fff;text-decoration:none;font-weight:700;padding:12px 22px;border-radius:11px;display:inline-block">Send new documents</a>
            </p>

        <?php elseif ($event === 'activated'): ?>
            <p style="font-size:15px;line-height:1.6">
                Your payment came through and your <strong>Verified Business</strong> badge is now live on your profile.
            </p>
            <p style="font-size:15px;line-height:1.6">
                It renews automatically each month. You can cancel any time from your dashboard — the
                badge stays up until the month you've paid for runs out.
            </p>
            <p style="margin:22px 0">
                <a href="<?= esc($manageLink, 'attr') ?>" style="background:#F77F00;color:#fff;text-decoration:none;font-weight:700;padding:12px 22px;border-radius:11px;display:inline-block">View my profile</a>
            </p>

        <?php else: /* renewing */ ?>
            <p style="font-size:15px;line-height:1.6">
                Just so there are no surprises: your Verified Business badge renews on
                <strong><?= esc($paidUntil) ?></strong>, and PayFast will charge
                <strong>R<?= esc($amount) ?></strong> as usual.
            </p>
            <p style="font-size:15px;line-height:1.6">
                Nothing to do if that's all fine. If you'd rather stop, cancel from your PayFast
                account before that date and the badge will simply come down when the month ends.
            </p>
        <?php endif; ?>

        <p style="font-size:13px;color:#64748b">
            You're getting this because your business is listed on <?= esc($site) ?>.
        </p>
    </div>
</body>
</html>

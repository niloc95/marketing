<?php
/**
 * A communications campaign email (CampaignRenderer). The admin's paragraphs
 * are plain text, escaped here; the button and footer come from the template.
 *
 * @var string                    $site
 * @var string                    $name        the profile's name
 * @var string                    $noun        practice, business...
 * @var list<string>              $paragraphs
 * @var array{0:string,1:string}  $button      [label, url]
 * @var string                    $unsubscribe
 * @var bool                      $signIn      the button is a one use sign in link
 * @var bool                      $claim       the button opens the claim page
 * @var int                       $claimDays   how long a claim link works
 * @var list<array{0:string,1:string}> $extras  "See it for yourself" links [label, url]
 */
?>
<?= $this->extend('emails/_layout') ?>
<?= $this->section('content') ?>
        <p style="font-size:13px;color:#64748b;margin:0 0 6px"><?= esc($site) ?></p>
        <?php foreach ($paragraphs as $p): ?>
            <p style="font-size:15px;line-height:1.6"><?= esc($p) ?></p>
        <?php endforeach; ?>
        <p style="margin:22px 0">
            <?= email_button($button[1], $button[0]) ?>
        </p>
        <?php if (! empty($extras)): ?>
            <div style="margin:0 0 22px;padding:14px 16px;border-radius:10px;background:#f1f5f9">
                <p style="font-size:14px;font-weight:700;color:#0f172a;margin:0 0 6px">See it for yourself</p>
                <?php foreach ($extras as [$label, $url]): ?>
                    <p style="font-size:14px;margin:4px 0"><a href="<?= esc($url) ?>" style="color:#003049;font-weight:600"><?= esc($label) ?></a></p>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        <p style="font-size:13px;color:#64748b">Or paste this link into your browser:<br><a href="<?= esc($button[1]) ?>" style="color:#003049;word-break:break-all"><?= esc($button[1]) ?></a></p>
        <?php if ($signIn): ?>
            <p style="font-size:13px;color:#64748b">That button signs you straight in, no password. It works once, and expires in 7 days.</p>
        <?php elseif (! empty($claim)): ?>
            <p style="font-size:13px;color:#64748b">That button opens your profile so you can confirm it is yours, tell us what is wrong, or remove it. Nothing changes until you choose. The link works for <?= (int) ($claimDays ?? 14) ?> days.</p>
        <?php endif; ?>
        <p style="font-size:13px;color:#64748b">
            You're getting this because <?= esc($name) ?> has a <?= esc($noun) ?> profile on <?= esc($site) ?>.
            <a href="<?= esc($unsubscribe) ?>" style="color:#64748b">Stop emails about my profile</a>.
        </p>
<?= $this->endSection() ?>

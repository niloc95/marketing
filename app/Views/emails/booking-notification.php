<?php
/**
 * Every online booking message by email (Notifications\Channels::email()),
 * to the customer or to the team. The wording above the details is the
 * business's template, plain text: each paragraph is escaped here, and only
 * links are made clickable, so nothing typed by a business or a customer
 * becomes markup. The details card, the button and the unsubscribe line are
 * fixed, so no template can remove them.
 *
 * @var string                         $business
 * @var list<string>                   $paragraphs
 * @var list<array{0:string,1:string}> $details
 * @var list<array{0:string,1:string}> $answers   empty for the team and when the template leaves them out
 * @var array{0:string,1:string}|null  $button    label, url
 * @var string                         $policy    cancellation policy, customer only
 * @var string                         $unsubscribe link, customer only
 * @var string                         $footer
 */
$link = static fn (string $text): string => (string) preg_replace(
    '~https?://[^\s<]+~',
    '<a href="$0" style="color:#003049">$0</a>',
    esc($text),
);
?>
<?= $this->extend('emails/_layout') ?>
<?= $this->section('content') ?>
        <p style="font-size:12px;letter-spacing:.06em;text-transform:uppercase;color:#64748b;margin:0 0 6px"><?= esc($business) ?></p>
        <?php foreach ($paragraphs as $p): ?>
            <p style="font-size:15px;line-height:1.6;margin:0 0 12px"><?= nl2br($link($p), false) ?></p>
        <?php endforeach ?>
        <table role="presentation" style="width:100%;border-collapse:collapse;margin:16px 0;background:#f8fafc;border-radius:10px">
            <?php foreach ($details as [$label, $value]): ?>
                <tr>
                    <td style="padding:8px 12px;font-size:13px;color:#64748b;width:30%;vertical-align:top"><?= esc($label) ?></td>
                    <td style="padding:8px 12px;font-size:14px;color:#0f172a"><?= esc($value) ?></td>
                </tr>
            <?php endforeach ?>
        </table>
        <?php if ($answers !== []): ?>
            <p style="font-size:13px;font-weight:700;margin:16px 0 6px">Your answers</p>
            <?php foreach ($answers as [$q, $a]): ?>
                <p style="font-size:14px;line-height:1.5;margin:0 0 6px"><span style="color:#64748b"><?= esc($q) ?>:</span> <?= esc($a) ?></p>
            <?php endforeach ?>
        <?php endif ?>
        <?php if ($button !== null): ?>
            <p style="margin:22px 0">
                <a href="<?= esc($button[1]) ?>" style="background:#F77F00;color:#fff;text-decoration:none;font-weight:700;padding:12px 22px;border-radius:11px;display:inline-block"><?= esc($button[0]) ?></a>
            </p>
        <?php endif ?>
        <?php if ($policy !== ''): ?>
            <p style="font-size:13px;line-height:1.5;color:#64748b;margin:0 0 12px"><strong>Cancellation policy:</strong> <?= nl2br(esc($policy), false) ?></p>
        <?php endif ?>
        <p style="font-size:12px;line-height:1.5;color:#94a3b8;margin:18px 0 0"><?= esc($footer) ?>
            <?php if ($unsubscribe !== ''): ?>
                <a href="<?= esc($unsubscribe) ?>" style="color:#94a3b8">Stop emails from <?= esc($business) ?></a>
            <?php endif ?>
        </p>
<?= $this->endSection() ?>

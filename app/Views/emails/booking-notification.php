<?php
/**
 * The customer's email about their online booking (Notifications\Channels::email()).
 * The wording under the heading is the business's template, plain text:
 * each paragraph is escaped here, and only links are made clickable, so
 * nothing typed by a business or a customer becomes markup. The heading,
 * the details card, the place with its maps links and opening hours, the
 * buttons, the policy, the terms and the unsubscribe line are fixed, so no
 * template can remove them. The team's email is emails/booking-team.php.
 *
 * @var string                         $business
 * @var string                         $heading
 * @var list<string>                   $paragraphs
 * @var list<array{0:string,1:string}> $details
 * @var array{name:string,address:string,maps_url:string,waze_url:string}|null $place  upcoming events only
 * @var list<array{0:string,1:string}> $hours     the place's opening hours, upcoming events only
 * @var string                         $timezone_note named when it is not the profile's zone
 * @var array{email:string,phone:string} $enquiries
 * @var list<array{0:string,1:string}> $answers   empty when the template leaves them out
 * @var array{0:string,1:string}|null  $button    label, url
 * @var string                         $calendar_link Google Calendar, upcoming events only
 * @var string                         $policy    cancellation policy
 * @var string                         $terms_url
 * @var string                         $privacy_url
 * @var string                         $unsubscribe link
 * @var string                         $footer
 */
$link  = static fn (string $text): string => (string) preg_replace(
    '~https?://[^\s<]+~',
    '<a href="$0" style="color:#003049">$0</a>',
    esc($text),
);
$muted = 'font-size:13px;color:#64748b';
$row   = static fn (string $label, string $html): string => '<tr><td style="padding:8px 12px;' . $muted . ';width:30%;vertical-align:top">' . esc($label) . '</td><td style="padding:8px 12px;font-size:14px;color:#0f172a">' . $html . '</td></tr>';
?>
<?= $this->extend('emails/_layout') ?>
<?= $this->section('content') ?>
        <p style="font-size:12px;letter-spacing:.06em;text-transform:uppercase;color:#64748b;margin:0 0 6px"><?= esc($business) ?></p>
        <?php if ($heading !== ''): ?>
            <h1 style="color:#003049;font-size:20px;margin:0 0 12px"><?= esc($heading) ?></h1>
        <?php endif ?>
        <?php foreach ($paragraphs as $p): ?>
            <p style="font-size:15px;line-height:1.6;margin:0 0 12px"><?= nl2br($link($p), false) ?></p>
        <?php endforeach ?>
        <table role="presentation" style="width:100%;border-collapse:collapse;margin:16px 0;background:#f8fafc;border-radius:10px">
            <?php foreach ($details as [$label, $value]): ?>
                <?php if ($label === 'When' && $timezone_note !== ''): ?>
                    <?= $row($label, esc($value) . ' <span style="' . $muted . '">(' . esc($timezone_note) . ')</span>') ?>
                <?php elseif ($label === 'Where' && $place !== null): ?>
                    <?= $row('Where', esc($place['name']) . ($place['address'] !== '' ? '<br><span style="' . $muted . '">' . esc($place['address']) . '</span>' : '')
                        . ($place['maps_url'] !== '' ? '<br><a href="' . esc($place['maps_url']) . '" style="color:#003049">Open in Google Maps</a>' : '')
                        . ($place['waze_url'] !== '' ? ' · <a href="' . esc($place['waze_url']) . '" style="color:#003049">Open in Waze</a>' : '')) ?>
                <?php elseif ($label === 'Join online'): ?>
                    <?= $row($label, '<a href="' . esc($value) . '" style="color:#003049">' . esc($value) . '</a>') ?>
                <?php else: ?>
                    <?= $row($label, esc($value)) ?>
                <?php endif ?>
            <?php endforeach ?>
            <?php if ($enquiries['email'] !== '' || $enquiries['phone'] !== ''): ?>
                <?= $row('Enquiries', implode(' · ', array_filter([
                    $enquiries['email'] !== '' ? '<a href="mailto:' . esc($enquiries['email']) . '" style="color:#003049">' . esc($enquiries['email']) . '</a>' : '',
                    esc($enquiries['phone']),
                ]))) ?>
            <?php endif ?>
        </table>
        <?php if ($hours !== []): ?>
            <p style="font-size:13px;font-weight:700;margin:16px 0 6px">Opening hours<?= $place !== null && $place['name'] !== $business ? ', ' . esc($place['name']) : '' ?></p>
            <table role="presentation" style="border-collapse:collapse;margin:0 0 12px">
                <?php foreach ($hours as [$day, $line]): ?>
                    <tr><td style="padding:2px 12px 2px 0;<?= $muted ?>"><?= esc($day) ?></td><td style="padding:2px 0;font-size:13px;color:#0f172a"><?= esc($line) ?></td></tr>
                <?php endforeach ?>
            </table>
        <?php endif ?>
        <?php if ($answers !== []): ?>
            <p style="font-size:13px;font-weight:700;margin:16px 0 6px">Your answers</p>
            <?php foreach ($answers as [$q, $a]): ?>
                <p style="font-size:14px;line-height:1.5;margin:0 0 6px"><span style="color:#64748b"><?= esc($q) ?>:</span> <?= esc($a) ?></p>
            <?php endforeach ?>
        <?php endif ?>
        <?php if ($button !== null): ?>
            <p style="margin:22px 0 10px"><?= email_button($button[1], $button[0]) ?></p>
            <?php if ($calendar_link !== ''): ?>
                <p style="font-size:14px;margin:0 0 10px"><a href="<?= esc($calendar_link) ?>" style="color:#003049;font-weight:700">Add to Google Calendar</a></p>
            <?php endif ?>
            <p style="<?= $muted ?>;margin:0 0 18px">If the button does not work, copy this link into your browser:<br><a href="<?= esc($button[1]) ?>" style="color:#003049;word-break:break-all"><?= esc($button[1]) ?></a></p>
        <?php endif ?>
        <?php if ($policy !== ''): ?>
            <p style="font-size:13px;line-height:1.5;color:#64748b;margin:0 0 12px"><strong>Cancellation policy:</strong> <?= nl2br(esc($policy), false) ?></p>
        <?php endif ?>
        <p style="font-size:12px;line-height:1.5;color:#94a3b8;margin:18px 0 0"><?= esc($footer) ?>
            <?php if ($terms_url !== ''): ?><a href="<?= esc($terms_url) ?>" style="color:#94a3b8">Terms</a> · <?php endif ?>
            <a href="<?= esc($privacy_url) ?>" style="color:#94a3b8">Privacy</a>
            <?php if ($unsubscribe !== ''): ?>
                · <a href="<?= esc($unsubscribe) ?>" style="color:#94a3b8">Stop emails from <?= esc($business) ?></a>
            <?php endif ?>
        </p>
<?= $this->endSection() ?>

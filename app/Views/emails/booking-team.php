<?php
/**
 * The team's email about an online booking (Notifications\Channels::email()
 * with $team): a notice for staff, not the customer's email. The customer's
 * name, phone and email, the appointment with its place and maps links,
 * their answers, links into /bookings, and who made the booking when.
 * From WebScheduler Local; Reply goes to the customer.
 *
 * @var string                         $business
 * @var string                         $heading
 * @var list<string>                   $paragraphs
 * @var array{name:string,email:string,phone:string} $contact
 * @var list<array{0:string,1:string}> $details
 * @var array{name:string,address:string,maps_url:string,waze_url:string}|null $place
 * @var list<array{0:string,1:string}> $answers   sensitive answers are never here
 * @var string                         $view_url  the appointment, open on the calendar
 * @var string                         $customer_page_url
 * @var string                         $booked    "online by the customer, 7 Jan 2030 at 08:00"
 * @var string                         $footer
 */
$muted = 'font-size:13px;color:#64748b';
$row   = static fn (string $label, string $html): string => '<tr><td style="padding:6px 12px;' . $muted . ';width:30%;vertical-align:top">' . esc($label) . '</td><td style="padding:6px 12px;font-size:14px;color:#0f172a">' . $html . '</td></tr>';
?>
<?= $this->extend('emails/_layout') ?>
<?= $this->section('content') ?>
        <p style="font-size:12px;letter-spacing:.06em;text-transform:uppercase;color:#64748b;margin:0 0 6px">WebScheduler Local · <?= esc($business) ?></p>
        <h1 style="color:#003049;font-size:20px;margin:0 0 12px"><?= esc($heading) ?></h1>
        <?php foreach ($paragraphs as $p): ?>
            <p style="font-size:15px;line-height:1.6;margin:0 0 12px"><?= nl2br(esc($p), false) ?></p>
        <?php endforeach ?>

        <p style="font-size:13px;font-weight:700;margin:16px 0 4px">Customer</p>
        <table role="presentation" style="width:100%;border-collapse:collapse;margin:0 0 12px;background:#f8fafc;border-radius:10px">
            <?= $row('Name', esc($contact['name'])) ?>
            <?php if ($contact['phone'] !== ''): ?>
                <?= $row('Phone', '<a href="tel:' . esc(preg_replace('/[^\d+]/', '', $contact['phone'])) . '" style="color:#003049">' . esc($contact['phone']) . '</a>') ?>
            <?php endif ?>
            <?php if ($contact['email'] !== ''): ?>
                <?= $row('Email', '<a href="mailto:' . esc($contact['email']) . '" style="color:#003049">' . esc($contact['email']) . '</a>') ?>
            <?php endif ?>
        </table>

        <p style="font-size:13px;font-weight:700;margin:16px 0 4px">Appointment</p>
        <table role="presentation" style="width:100%;border-collapse:collapse;margin:0 0 12px;background:#f8fafc;border-radius:10px">
            <?php foreach ($details as [$label, $value]): ?>
                <?php if ($label === 'Where' && $place !== null): ?>
                    <?= $row('Where', esc($place['name']) . ($place['address'] !== '' ? '<br><span style="' . $muted . '">' . esc($place['address']) . '</span>' : '')
                        . ($place['maps_url'] !== '' ? '<br><a href="' . esc($place['maps_url']) . '" style="color:#003049">Google Maps</a>' : '')
                        . ($place['waze_url'] !== '' ? ' · <a href="' . esc($place['waze_url']) . '" style="color:#003049">Waze</a>' : '')) ?>
                <?php elseif ($label === 'Join online'): ?>
                    <?= $row($label, '<a href="' . esc($value) . '" style="color:#003049">' . esc($value) . '</a>') ?>
                <?php else: ?>
                    <?= $row($label, esc($value)) ?>
                <?php endif ?>
            <?php endforeach ?>
        </table>

        <?php if ($answers !== []): ?>
            <p style="font-size:13px;font-weight:700;margin:16px 0 6px">Their answers</p>
            <?php foreach ($answers as [$q, $a]): ?>
                <p style="font-size:14px;line-height:1.5;margin:0 0 6px"><span style="color:#64748b"><?= esc($q) ?>:</span> <?= esc($a) ?></p>
            <?php endforeach ?>
        <?php endif ?>

        <p style="margin:22px 0 8px"><?= email_button($view_url, 'Open the appointment') ?></p>
        <p style="font-size:14px;margin:0 0 18px">
            <?php if ($customer_page_url !== ''): ?><a href="<?= esc($customer_page_url) ?>" style="color:#003049">Customer page</a><?php endif ?>
            <?php if ($customer_page_url !== '' && $contact['email'] !== ''): ?> · <?php endif ?>
            <?php if ($contact['email'] !== ''): ?><a href="mailto:<?= esc($contact['email']) ?>" style="color:#003049">Email the customer</a><?php endif ?>
        </p>
        <p style="<?= $muted ?>;margin:0 0 12px">Booked <?= esc($booked) ?>.</p>
        <p style="font-size:12px;line-height:1.5;color:#94a3b8;margin:18px 0 0"><?= esc($footer) ?></p>
<?= $this->endSection() ?>

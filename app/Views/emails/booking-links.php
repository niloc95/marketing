<?php
/**
 * "Find my booking" (PublicBookingService::emailLinks()): one link per
 * business where this address has an upcoming booking. Sent only to the
 * address typed, and only when something matched.
 *
 * @var list<array{business:string,count:int,url:string}> $links
 */
?>
<?= $this->extend('emails/_layout') ?>
<?= $this->section('content') ?>
        <h1 style="color:#003049;font-size:20px;margin:0 0 12px">Your bookings</h1>
        <p style="font-size:15px;line-height:1.6">You asked for the links to your bookings. Open one to see the details, or to change or cancel a booking.</p>
        <?php foreach ($links as $link): ?>
            <p style="margin:18px 0">
                <a href="<?= esc($link['url']) ?>" style="background:#F77F00;color:#fff;text-decoration:none;font-weight:700;padding:12px 22px;border-radius:11px;display:inline-block"><?= esc($link['business']) ?></a>
                <span style="font-size:13px;color:#64748b;margin-left:8px"><?= (int) $link['count'] ?> upcoming <?= $link['count'] === 1 ? 'booking' : 'bookings' ?></span>
            </p>
        <?php endforeach ?>
        <p style="font-size:13px;color:#64748b">Keep these links to yourself: anyone with one can see and change your bookings. If you did not ask for this email, you can ignore it.</p>
<?= $this->endSection() ?>

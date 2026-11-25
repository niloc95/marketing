<?php
/**
 * One appointment as a small card on the dashboard (today, waiting, coming
 * up). For a member who may open appointments it opens the details dialog
 * in place (resources/business/appointment-host.js), and links to the
 * calendar's day without script; otherwise it is plain text.
 *
 * @var array<string,mixed> $a       CalendarQuery::format() row (local start and end)
 * @var string              $when    the time line ("09:45", "Thu 26 Nov, 08:15")
 * @var string              $sub     the line under the name
 * @var bool                $canOpen
 * @var string              $state   past, now or later ('' when not today)
 * @var bool                $compact today's row: the clock in its own column
 */
use App\Services\Bookings\AppointmentStatus;

$state   ??= '';
$compact ??= false;
$date    = substr((string) $a['start'], 0, 10);
// Confirmed is the usual case: only another status earns a pill.
$pill    = (string) $a['status'] === 'confirmed' ? ''
    : '<span class="appt-pill" data-status="' . esc((string) $a['status'], 'attr') . '">' . esc(AppointmentStatus::short((string) $a['status'])) . '</span>';
$now     = $state === 'now' ? ' <span class="dash-appt-now">Now</span>' : '';
$inner   = $compact
    // Today: the start in its own column, the end under it.
    ? '<span class="dash-appt-clock"><span>' . esc(substr((string) $a['start'], 11, 5)) . '</span><span>' . esc(substr((string) $a['end'], 11, 5)) . '</span></span>'
        . '<span class="dash-appt-main"><span class="dash-appt-name">' . esc((string) ($a['customer_name'] ?? 'No customer')) . $now . '</span>'
        . '<span class="dash-appt-sub">' . esc($sub) . '</span></span>' . $pill
    : '<span class="dash-appt-main">'
        . '<span class="dash-appt-time">' . esc($when) . $now . '</span>'
        . '<span class="dash-appt-name">' . esc((string) ($a['customer_name'] ?? 'No customer')) . '</span>'
        . '<span class="dash-appt-sub">' . esc($sub) . '</span>'
        . '</span>' . $pill;
$class = 'dash-appt' . ($compact ? ' is-compact' : '') . ($state !== '' ? ' is-' . $state : '');
?>
<?php if ($canOpen): ?>
<a class="<?= $class ?>" href="<?= esc(base_url('business/calendar?view=day&date=' . $date . '&open=' . $a['hash']), 'attr') ?>" data-appt-open="<?= (int) $a['id'] ?>"><?= $inner ?></a>
<?php else: ?>
<div class="<?= $class ?>"><?= $inner ?></div>
<?php endif ?>

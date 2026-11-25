<?php
/**
 * Notices (Business\Notifications::index): bookings, moves and cancellations
 * customers made online, for the people who work with that team member.
 *
 * Cards grouped by day, newest first, each with an icon for what happened,
 * the appointment's status now and Mark read. A card opens the appointment's
 * details dialog here and marks the notice read (resources/business/
 * appointment-host.js); without script, or for a member who may not manage
 * appointments, it links to the calendar or is plain text. All and Unread
 * filter the list; Email me too and the sent messages sit beside it.
 *
 * @var list<array<string,mixed>>              $items
 * @var array<string,mixed>                    $pager
 * @var string                                 $show   all or unread
 * @var int                                    $total
 * @var int                                    $unread
 * @var bool                                   $notify
 * @var string                                 $tz
 * @var bool                                   $canLog
 * @var bool                                   $canOpen may open appointments (appointments.manage)
 * @var list<string>                           $teamOff team email events this business switched off
 * @var list<array<string,mixed>>              $providers
 * @var list<array{value:string,label:string}> $statuses
 * @var array<string,string>                   $sendChannels
 */
use App\Services\Bookings\AppointmentStatus;

$zone  = new DateTimeZone($tz);
$at    = static fn (string $utc): DateTimeImmutable => (new DateTimeImmutable($utc, new DateTimeZone('UTC')))->setTimezone($zone);
$today = new DateTimeImmutable('today', $zone);
$dayLabel = static function (DateTimeImmutable $d) use ($today): string {
    $days = (int) $today->diff($d->setTime(0, 0))->format('%r%a');

    return match (true) {
        $days === 0  => 'Today',
        $days === -1 => 'Yesterday',
        default      => $d->format($d->format('Y') === $today->format('Y') ? 'l j F' : 'l j F Y'),
    };
};
// What happened, as an icon and a colour.
$kinds = [
    'pending'     => ['calendar-clock', 'notice-amber'],
    'confirmed'   => ['calendar-check', 'notice-green'],
    'rescheduled' => ['calendar-days', 'notice-blue'],
    'cancelled'   => ['calendar-x', 'notice-rose'],
];

$groups = [];
foreach ($items as $n) {
    $groups[$dayLabel($at((string) $n['created_at']))][] = $n;
}
$filterUrl = static fn (string $s): string => base_url('business/notifications' . ($s === 'unread' ? '?show=unread' : ''));
?>
<?= $this->extend('layouts/business') ?>
<?= $this->section('content') ?>
<div class="notice-page" data-appt-host
     data-appointment-url="<?= esc(base_url('business/api/appointments'), 'attr') ?>"
     data-customer-page="<?= esc(base_url('business/customers'), 'attr') ?>"
     data-calendar-page="<?= esc(base_url('business/calendar'), 'attr') ?>"
     data-read-url="<?= esc(base_url('business/notifications/read'), 'attr') ?>"
     data-csrf-header="<?= esc(config('Security')->headerName, 'attr') ?>" data-csrf-hash="<?= esc(csrf_hash(), 'attr') ?>">

    <div class="notice-main">
        <nav class="notice-filter" aria-label="Show">
            <a href="<?= esc($filterUrl('all'), 'attr') ?>"<?= $show === 'all' ? ' aria-current="page"' : '' ?>>All <span><?= number_format($total) ?></span></a>
            <a href="<?= esc($filterUrl('unread'), 'attr') ?>"<?= $show === 'unread' ? ' aria-current="page"' : '' ?>>Unread <span><?= number_format($unread) ?></span></a>
        </nav>

        <?php if ($items === []): ?>
            <div class="panel notice-empty">
                <?= lucide('inbox', 'h-8 w-8') ?>
                <p><?= $show === 'unread' && $total > 0 ? 'All read. New ones show here.' : 'Nothing yet. When a customer books, moves or cancels online, you see it here.' ?></p>
            </div>
        <?php else: ?>
            <?php foreach ($groups as $label => $list): ?>
                <section class="notice-day">
                    <h2 class="notice-day-title"><?= esc($label) ?></h2>
                    <ul class="notice-list">
                        <?php foreach ($list as $n): ?>
                            <?php
                            [$icon, $tone] = $kinds[$n['event']] ?? ['bell', 'notice-blue'];
                            $isUnread = $n['read_at'] === null;
                            $status   = (string) ($n['appointment_status'] ?? '');
                            $inner    = '<span class="notice-icon ' . $tone . '">' . lucide($icon, 'h-5 w-5') . '</span>'
                                . '<span class="notice-text">'
                                . '<span class="notice-title">' . esc((string) $n['title']) . '</span>'
                                . (! empty($n['body']) ? '<span class="notice-body">' . esc((string) $n['body']) . '</span>' : '')
                                . '<span class="notice-meta">' . esc($at((string) $n['created_at'])->format('H:i'))
                                . ($status !== '' ? ' <span class="appt-pill" data-status="' . esc($status, 'attr') . '">' . esc(AppointmentStatus::short($status)) . '</span>' : '')
                                . '</span></span>'
                                . ($isUnread ? '<span class="notice-dot" title="Unread"><span class="sr-only">Unread</span></span>' : '');
                            ?>
                            <li class="panel notice<?= $isUnread ? ' is-unread' : '' ?>">
                                <?php if ($canOpen && ! empty($n['appointment_hash'])): ?>
                                    <a class="notice-card" href="<?= esc(base_url('business/calendar?view=day&date=' . $at((string) $n['start_at'])->format('Y-m-d') . '&open=' . $n['appointment_hash']), 'attr') ?>"
                                       data-appt-open="<?= (int) $n['appointment_id'] ?>"<?= $isUnread ? ' data-notice-read="' . (int) $n['id'] . '"' : '' ?>><?= $inner ?></a>
                                <?php else: ?>
                                    <div class="notice-card"><?= $inner ?></div>
                                <?php endif ?>
                                <?php if ($isUnread): ?>
                                    <form method="post" action="<?= base_url('business/notifications/read') ?>" class="notice-read">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="id" value="<?= (int) $n['id'] ?>">
                                        <input type="hidden" name="show" value="<?= esc($show, 'attr') ?>">
                                        <button type="submit" class="btn btn-ghost btn-xs" title="Mark read"><?= lucide('check', 'h-4 w-4') ?> <span class="notice-read-label">Mark read</span></button>
                                    </form>
                                <?php endif ?>
                            </li>
                        <?php endforeach ?>
                    </ul>
                </section>
            <?php endforeach ?>
            <?= view('directory/_pager', ['result' => $pager, 'baseUrl' => base_url('business/notifications'), 'query' => $show === 'unread' ? ['show' => 'unread'] : [], 'noun' => 'notices'], ['saveData' => false]) ?>
        <?php endif ?>
    </div>

    <aside class="notice-side">
        <form method="post" action="<?= base_url('business/notifications/preferences') ?>" class="panel">
            <?= csrf_field() ?>
            <h2 class="section-title">Email me too</h2>
            <label class="flex items-start gap-2 text-sm"><input type="checkbox" name="notify" value="1" class="mt-0.5"<?= $notify ? ' checked' : '' ?>> Email me when a customer books, moves or cancels online</label>
            <?php if ($teamOff !== []): ?>
                <?php
                $all   = count($teamOff) === count(\Config\BookingNotifications::TEAM_EVENTS);
                $names = array_map(static fn (string $e): string => mb_strtolower(\Config\BookingNotifications::EVENTS[$e]), $teamOff);
                ?>
                <p class="notice-hint" data-team-off>
                    <?= lucide('mail', 'h-4 w-4') ?>
                    <span>
                        <?= $all
                            ? 'Team emails are switched off in your booking settings, so none are sent, even with this ticked.'
                            : 'Some team emails are switched off in your booking settings: ' . esc(implode(', ', $names)) . '.' ?>
                        You still see every notice here.
                        <?php if ($canLog): ?><a class="underline" href="<?= base_url('business/settings/notifications') ?>">Change this in Messages</a>.<?php else: ?>An admin can change this.<?php endif ?>
                    </span>
                </p>
            <?php endif ?>
            <button type="submit" class="btn btn-primary btn-xs mt-4">Save</button>
        </form>
        <?php if ($canLog): ?>
            <a class="panel notice-sent" href="<?= base_url('business/notifications/sent') ?>">
                <?= lucide('mail', 'h-5 w-5') ?>
                <span><span class="font-semibold">Sent messages</span><span class="block text-sm text-slate-500 dark:text-slate-400">Every email and WhatsApp sent to customers and the team.</span></span>
            </a>
        <?php endif ?>
    </aside>

    <?php if ($canOpen): ?>
        <div class="cal-toast" data-cal-toast role="status" aria-live="polite" hidden></div>
        <?= view('business/_appointment_details', ['providers' => $providers, 'statuses' => $statuses, 'sendChannels' => $sendChannels, 'calendarLink' => true]) ?>
    <?php endif ?>
</div>
<?= $this->endSection() ?>

<?= $this->section('shell_actions') ?>
<?php if ($unread > 0): ?>
    <form method="post" action="<?= base_url('business/notifications/read') ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="show" value="<?= esc($show, 'attr') ?>">
        <button type="submit" class="btn btn-ghost btn-xs">Mark all as read</button>
    </form>
<?php endif ?>
<?= $this->endSection() ?>

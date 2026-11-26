<?php
/**
 * One customer, as cards: who they are and how to reach them, their
 * appointments (upcoming first, past folded after five), their details
 * (read until Edit), and their data. An appointment card opens the
 * calendar's details dialog here (resources/business/appointment-host.js), so
 * the person stays with this customer; it is still a link to the calendar
 * without script.
 *
 * @var array<string,mixed>                                       $customer
 * @var array<string,mixed>                                       $old
 * @var array<string,string>                                      $errors
 * @var list<array<string,mixed>>                                 $customFields
 * @var list<array{key:string,title:string,value:string,current:bool}> $answers each kept answer under the question it answered
 * @var list<array<string,mixed>>                                 $upcoming soonest first
 * @var list<array<string,mixed>>                                 $past     newest first
 * @var list<array<string,mixed>>                                 $providers in the member's scope (Reschedule)
 * @var list<array{value:string,label:string}>                    $statuses
 * @var array<string,string>                                      $sendChannels
 * @var array{total:int,upcoming:int,completed:int,cancelled:int,no_show:int} $stats
 * @var array{upcoming:int,past:int,can_delete:bool}|null         $impact null when the member may not delete
 * @var string                                                    $timezone
 * @var bool                                                      $optedOut  stopped this business's booking emails
 */
use App\Services\Bookings\AppointmentStatus;

$tz      = new DateTimeZone($timezone);
$at      = static fn (string $utc): DateTimeImmutable => (new DateTimeImmutable($utc, new DateTimeZone('UTC')))->setTimezone($tz);
$hash    = (string) $customer['hash'];
$email   = (string) ($customer['email'] ?? '');
$phone   = (string) ($customer['phone'] ?? '');
$initials = mb_strtoupper(mb_substr((string) $customer['first_name'], 0, 1) . mb_substr((string) ($customer['last_name'] ?? ''), 0, 1));
$editing = $errors !== [];
$shown   = 5;

// One appointment card: a date block, when, what, who and where, and its status.
$card = static function (array $a) use ($at): string {
    $start = $at((string) $a['start_at']);
    $where = ($a['delivery_mode'] ?? 'onsite') === 'onsite' ? (string) ($a['location_name'] ?? '') : 'Online';
    $href  = base_url('business/calendar?view=day&date=' . $start->format('Y-m-d') . '&open=' . $a['hash']);
    $parts = array_filter([(string) $a['provider_name'], $where]);

    return '<li><a class="cust-appt" href="' . esc($href, 'attr') . '" data-appt-open="' . (int) $a['id'] . '">'
        . '<span class="cust-appt-date"><span class="cust-appt-day">' . $start->format('j') . '</span><span class="cust-appt-month">' . $start->format('M') . '</span></span>'
        . '<span class="cust-appt-main">'
        . '<span class="cust-appt-when">' . esc($start->format('D j M Y, H:i')) . '</span>'
        . '<span class="cust-appt-what">' . esc((string) $a['service_name']) . '</span>'
        . '<span class="cust-appt-who">' . esc(implode(' · ', $parts)) . '</span>'
        . '</span>'
        . '<span class="appt-pill" data-status="' . esc((string) $a['status'], 'attr') . '">' . esc(AppointmentStatus::short((string) $a['status'])) . '</span>'
        . '</a></li>';
};

// What they told you, for the Details card.
$facts = array_filter([
    'Email'   => $email,
    'Phone'   => $phone,
    'Address' => (string) ($customer['address'] ?? ''),
]);
// Each answer under the question it answered (CustomerService::answerList()).
$types = array_column($customFields, 'type', 'key');
foreach ($answers ?? [] as $a) {
    $facts[$a['title']] = ($types[$a['key']] ?? '') === 'checkbox' && $a['current'] ? ($a['value'] === '1' || $a['value'] === 'Yes' ? 'Yes' : 'No') : $a['value'];
}
$notes = trim((string) ($customer['notes'] ?? ''));
?>
<?= $this->extend('layouts/business') ?>
<?= $this->section('content') ?>
<div class="cust-page" data-appt-host
     data-appointment-url="<?= esc(base_url('business/api/appointments'), 'attr') ?>"
     data-calendar-page="<?= esc(base_url('business/calendar'), 'attr') ?>"
     data-csrf-header="<?= esc(config('Security')->headerName, 'attr') ?>" data-csrf-hash="<?= esc(csrf_hash(), 'attr') ?>">

    <section class="panel cust-head">
        <span class="cust-avatar" aria-hidden="true"><?= esc($initials) ?></span>
        <div class="min-w-0 flex-1">
            <h2 class="cust-name"><?= esc((string) $customer['name']) ?></h2>
            <p class="cust-contact">
                <?php if ($email !== ''): ?><a href="mailto:<?= esc($email, 'attr') ?>"><?= lucide('mail', 'h-4 w-4') ?> <?= esc($email) ?></a><?php endif ?>
                <?php if ($phone !== ''): ?><a href="tel:<?= esc(preg_replace('/[^\d+]/', '', $phone), 'attr') ?>"><?= lucide('phone', 'h-4 w-4') ?> <?= esc($phone) ?></a><?php endif ?>
            </p>
            <p class="cust-stats">
                <?= (int) $stats['total'] ?> <?= $stats['total'] === 1 ? 'appointment' : 'appointments' ?>
                <?php foreach (['upcoming' => 'upcoming', 'completed' => 'completed', 'cancelled' => 'cancelled', 'no_show' => 'no show'] as $k => $label): ?>
                    <?php if ($stats[$k] > 0): ?> · <?= (int) $stats[$k] ?> <?= $label ?><?php endif ?>
                <?php endforeach ?>
            </p>
        </div>
        <?php if (! empty($optedOut)): ?>
            <div class="alert alert-info cust-optout">
                <p>This customer asked us to stop your booking emails to them.</p>
                <form method="post" action="<?= base_url('business/customers/' . $hash . '/emails') ?>" class="mt-2">
                    <?= csrf_field() ?>
                    <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="confirm" value="yes" required> They asked me to send them again</label>
                    <button type="submit" class="btn btn-ghost btn-xs mt-2">Send booking emails again</button>
                </form>
            </div>
        <?php endif ?>
    </section>

    <div class="cust-grid">
        <div class="cust-main">
            <section class="panel">
                <h2 class="section-title">Upcoming</h2>
                <?php if ($upcoming === []): ?>
                    <p class="text-sm text-slate-500 dark:text-slate-400">Nothing booked. Use Book an appointment at the top.</p>
                <?php else: ?>
                    <ul class="cust-appts"><?= implode('', array_map($card, array_slice($upcoming, 0, $shown))) ?></ul>
                    <?php if (count($upcoming) > $shown): ?>
                        <details class="cust-more">
                            <summary>Show <?= count($upcoming) - $shown ?> more</summary>
                            <ul class="cust-appts mt-3"><?= implode('', array_map($card, array_slice($upcoming, $shown))) ?></ul>
                        </details>
                    <?php endif ?>
                <?php endif ?>
            </section>

            <section class="panel">
                <h2 class="section-title">Past</h2>
                <?php if ($past === []): ?>
                    <p class="text-sm text-slate-500 dark:text-slate-400">None yet.</p>
                <?php else: ?>
                    <ul class="cust-appts"><?= implode('', array_map($card, array_slice($past, 0, $shown))) ?></ul>
                    <?php if (count($past) > $shown): ?>
                        <details class="cust-more">
                            <summary>Show <?= count($past) - $shown ?> more</summary>
                            <ul class="cust-appts mt-3"><?= implode('', array_map($card, array_slice($past, $shown))) ?></ul>
                        </details>
                    <?php endif ?>
                <?php endif ?>
            </section>
        </div>

        <div class="cust-side">
            <section class="panel" id="edit">
                <details class="cust-edit"<?= $editing ? ' open' : '' ?>>
                    <summary>
                        <h2 class="section-title mb-0">Details</h2>
                        <span class="btn btn-ghost btn-xs cust-edit-open"><?= lucide('square-pen', 'h-4 w-4') ?> Edit</span>
                        <span class="btn btn-ghost btn-xs cust-edit-close">Close</span>
                    </summary>
                    <form method="post" action="<?= base_url('business/customers/' . $hash) ?>" class="mt-4">
                        <?= csrf_field() ?>
                        <?= view('business/_customer_fields', ['old' => $old, 'errors' => $errors, 'customFields' => $customFields]) ?>
                        <button type="submit" class="btn btn-primary">Save</button>
                    </form>
                </details>
                <div class="cust-facts">
                    <?php if ($facts === [] && $notes === ''): ?>
                        <p class="text-sm text-slate-500 dark:text-slate-400">Nothing more yet.</p>
                    <?php else: ?>
                        <dl>
                            <?php foreach ($facts as $label => $value): ?>
                                <div><dt><?= esc($label) ?></dt><dd><?= esc($value) ?></dd></div>
                            <?php endforeach ?>
                            <?php if ($notes !== ''): ?>
                                <div><dt>Notes <span class="font-normal normal-case">(only your team)</span></dt><dd class="whitespace-pre-line"><?= esc($notes) ?></dd></div>
                            <?php endif ?>
                        </dl>
                    <?php endif ?>
                </div>
            </section>

            <?php if ($impact !== null): ?>
                <section class="panel" id="their-data">
                    <h2 class="section-title">Their data</h2>
                    <p class="text-sm">If they ask what you hold about them, download it all in one file: their details, answers, appointments, deposits and the messages sent to them. We keep customers for <?= (int) config('Bookings')->retentionMonths ?> months after their last appointment, then remove their personal details.</p>
                    <a class="btn btn-ghost btn-xs mt-3" href="<?= base_url('business/customers/' . $hash . '/export') ?>" download><?= lucide('download', 'h-4 w-4') ?> Download their data</a>

                    <div class="cust-delete" id="delete">
                        <h3 class="text-sm font-semibold">Delete this customer</h3>
                        <?php if (! $impact['can_delete']): ?>
                            <p class="mt-1 text-sm">They have <?= $impact['upcoming'] ?> upcoming <?= $impact['upcoming'] === 1 ? 'appointment' : 'appointments' ?>. Cancel or move <?= $impact['upcoming'] === 1 ? 'it' : 'them' ?> first.</p>
                        <?php else: ?>
                            <p class="mt-1 text-sm">This removes their name, contact details, notes and answers.<?php if ($impact['past'] > 0): ?> Their <?= $impact['past'] ?> past <?= $impact['past'] === 1 ? 'appointment stays' : 'appointments stay' ?> in your calendar without their name.<?php endif ?> You cannot undo this.</p>
                            <form method="post" action="<?= base_url('business/customers/' . $hash . '/delete') ?>" class="mt-3">
                                <?= csrf_field() ?>
                                <button type="submit" class="btn btn-ghost btn-xs text-rose-700">Delete customer</button>
                            </form>
                        <?php endif ?>
                    </div>
                </section>
            <?php endif ?>
        </div>
    </div>

    <div class="cal-toast" data-cal-toast role="status" aria-live="polite" hidden></div>
    <?= view('business/_appointment_details', ['providers' => $providers, 'statuses' => $statuses, 'sendChannels' => $sendChannels, 'customerLink' => false, 'calendarLink' => true]) ?>
</div>
<?= $this->endSection() ?>

<?= $this->section('shell_title') ?><?= esc($customer['name']) ?><?= $this->endSection() ?>

<?php // The top bar's Book an appointment books for this customer (layouts/business.php). ?>
<?= $this->section('book_customer') ?><?= esc($hash) ?><?= $this->endSection() ?>

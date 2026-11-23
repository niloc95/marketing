<?php
/**
 * "When can customers book?" (Business\Availability, AvailabilityOverview).
 *
 * @var list<array<string,mixed>> $services
 * @var list<array<string,mixed>> $providers
 * @var string                    $service  'all' or a service id
 * @var string                    $who      'any' or a person's id
 * @var string                    $date     the day the view is about
 * @var string                    $view     day, week, month or year
 * @var bool                      $public   customers' rules (not "as the business")
 * @var string                    $from
 * @var string                    $to
 * @var array<string,mixed>|null  $day      AvailabilityOverview::day(), day view only
 * @var list<array<string,mixed>> $days     AvailabilityOverview::range(), the other views
 * @var string                    $horizon  the last day customers can book
 * @var string                    $today
 * @var string                    $timezone
 * @var list<array<string,mixed>> $customFields  for the Book dialog
 * @var list<array{value:string,label:string}> $statuses for the Book dialog
 */
use App\Services\Bookings\AvailabilityOverview;

$params = ['service_id' => $service, 'provider_id' => $who, 'view' => $view, 'date' => $date] + ($public ? [] : ['as' => 'business']);
$link   = static fn (array $over): string => base_url('business/availability') . '?' . http_build_query($over + $params);
// A free time opens the New appointment form with service, person, day and time filled in.
$book   = static fn (int $serviceId, int $providerId, string $time): string => base_url('business/appointments/new') . '?' . http_build_query(['service_id' => $serviceId, 'provider_id' => $providerId, 'date' => $date, 'time' => $time]);
$fmt    = static fn (string $d, string $f): string => (new DateTimeImmutable($d))->format($f);
$shift  = static fn (int $by): string => (new DateTimeImmutable($date))->modify(match ($view) {
    'week' => ($by * 7) . ' days', 'month' => $by . ' months', 'year' => ($by * 12) . ' months', default => $by . ' days',
})->format('Y-m-d');
$label  = match ($view) {
    'week'  => $fmt($from, 'j M') . ' to ' . $fmt($to, 'j M Y'),
    'month' => $fmt($from, 'F Y'),
    'year'  => $fmt($from, 'F Y') . ' to ' . $fmt($to, 'F Y'),
    default => $fmt($date, 'l j F Y'),
};
$short = ['past' => 'Past', 'too_far' => 'Not open yet', 'closed' => 'Closed', 'off' => 'Nobody working', 'full' => 'Fully booked'];
$long  = [
    'past'    => 'This day has passed.',
    'too_far' => 'Customers cannot book this far ahead yet: the last day they can book is ' . $fmt($horizon, 'l j F Y') . '. Tick "As the business" to see the times your team can still book.',
    'closed'  => 'Closed on this day, by your opening hours or public holiday hours.',
    'off'     => 'Nobody you chose works on this day for these services.',
    'full'    => 'Fully booked.',
];
$byDate = array_column($days, null, 'date');
?>
<?= $this->extend('layouts/business') ?>
<?= $this->section('content') ?>
<?php // availability.js: a free time opens the Book dialog here (same fields and JSON as the calendar's); without the script the link opens /business/appointments/new. ?>
<div data-availability data-date="<?= esc($date, 'attr') ?>" data-service="<?= esc($service, 'attr') ?>" data-provider="<?= esc($who, 'attr') ?>"
     data-appointment-url="<?= esc(base_url('business/api/appointments'), 'attr') ?>"
     data-customer-url="<?= esc(base_url('business/api/customers'), 'attr') ?>"
     data-csrf-header="<?= esc(config('Security')->headerName, 'attr') ?>" data-csrf-hash="<?= esc(csrf_hash(), 'attr') ?>">
<p class="mt-1 text-sm text-slate-500 dark:text-slate-400">The free times for all your services or one, everyone or one person, on a day, a week, a month or a year. Times are in <?= esc($timezone) ?>.</p>

<?php if ($services === []): ?>
    <div class="panel mt-5"><p class="text-sm"><a class="underline" href="<?= base_url('business/services/new') ?>">Add a service</a> first.</p></div>
<?php else: ?>
    <form method="get" action="<?= base_url('business/availability') ?>" class="panel mt-5 grid gap-3 sm:grid-cols-2 lg:grid-cols-5 lg:items-end">
        <div>
            <label for="av-service" class="mb-1 block text-sm font-semibold">Service</label>
            <select id="av-service" name="service_id" class="w-full">
                <option value="all"<?= $service === 'all' ? ' selected' : '' ?>>All services</option>
                <?php foreach ($services as $s): ?>
                    <option value="<?= (int) $s['id'] ?>"<?= (string) $s['id'] === $service ? ' selected' : '' ?>><?= esc((string) $s['name']) ?></option>
                <?php endforeach ?>
            </select>
        </div>
        <div>
            <label for="av-who" class="mb-1 block text-sm font-semibold">With</label>
            <select id="av-who" name="provider_id" class="w-full">
                <?php if (count($providers) > 1): ?><option value="any"<?= $who === 'any' ? ' selected' : '' ?>>Everyone</option><?php endif ?>
                <?php foreach ($providers as $p): ?>
                    <option value="<?= (int) $p['id'] ?>"<?= (string) $p['id'] === $who || count($providers) === 1 ? ' selected' : '' ?>><?= esc((string) $p['name']) ?></option>
                <?php endforeach ?>
            </select>
        </div>
        <div>
            <label for="av-view" class="mb-1 block text-sm font-semibold">Show</label>
            <select id="av-view" name="view" class="w-full">
                <?php foreach (AvailabilityOverview::VIEWS as $key => $name): ?>
                    <option value="<?= $key ?>"<?= $key === $view ? ' selected' : '' ?>><?= $name ?></option>
                <?php endforeach ?>
            </select>
        </div>
        <div>
            <label for="av-date" class="mb-1 block text-sm font-semibold">From the day</label>
            <input id="av-date" type="date" name="date" value="<?= esc($date) ?>" class="w-full">
        </div>
        <div class="flex flex-col gap-2">
            <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="as" value="business"<?= $public ? '' : ' checked' ?>> As the business (no notice or horizon rules)</label>
            <button type="submit" class="btn btn-primary">Show times</button>
        </div>
    </form>

    <div class="mt-5 flex flex-wrap items-center gap-2">
        <a class="btn btn-ghost btn-xs" href="<?= esc($link(['date' => $shift(-1)]), 'attr') ?>" aria-label="Earlier"><?= lucide('chevron-left', 'h-4 w-4') ?></a>
        <a class="btn btn-ghost btn-xs" href="<?= esc($link(['date' => $today]), 'attr') ?>">Today</a>
        <a class="btn btn-ghost btn-xs" href="<?= esc($link(['date' => $shift(1)]), 'attr') ?>" aria-label="Later"><?= lucide('chevron-right', 'h-4 w-4') ?></a>
        <h2 class="ml-2 section-title"><?= esc($label) ?></h2>
    </div>
    <?php if ($public && $to > $horizon): ?>
        <p class="avail-note">Customers can book up to <?= esc($fmt($horizon, 'l j F Y')) ?> (Settings, Booking rules). Days after that show as "Not open yet"; tick "As the business" to see them.</p>
    <?php endif ?>

    <?php if ($view === 'day'): ?>
        <div class="mt-4">
            <?php if ($day['count'] === 0): ?>
                <p class="alert alert-info"><?= esc($long[$day['reason']] ?? 'No times on this day.') ?></p>
            <?php else: ?>
                <p class="mb-3 text-sm text-slate-600 dark:text-slate-300"><?= $day['count'] ?> start <?= $day['count'] === 1 ? 'time' : 'times' ?> free. Pick a time to book it.</p>
                <?php foreach ($day['services'] as $s): ?>
                    <?php
                    // Every time at one place: say it once under the heading, not on each time.
                    $allPlaces = array_values(array_unique(array_map(static fn (array $t): string => implode(', ', array_filter($t['places'])), $s['times'])));
                    $onePlace  = count($allPlaces) === 1 ? $allPlaces[0] : null;
                    ?>
                    <section class="panel mb-3">
                        <h3 class="subsection-title"><?= esc($s['name']) ?></h3>
                        <?php if ($onePlace !== null && $onePlace !== ''): ?><p class="mt-0.5 text-sm text-slate-500 dark:text-slate-400"><?= esc($onePlace) ?></p><?php endif ?>
                        <ul class="mt-2 flex flex-wrap gap-2">
                            <?php foreach ($s['times'] as $t): ?>
                                <?php $where = $onePlace !== null ? '' : esc(implode(', ', array_filter($t['places']))); ?>
                                <?php if (count($t['provider_ids']) === 1): ?>
                                    <li><a class="avail-time avail-time-book" href="<?= esc($book((int) $s['id'], $t['provider_ids'][0], $t['time']), 'attr') ?>" data-book-service="<?= (int) $s['id'] ?>" data-book-provider="<?= $t['provider_ids'][0] ?>" data-book-date="<?= esc($date, 'attr') ?>" data-book-time="<?= esc($t['time'], 'attr') ?>" title="<?= esc('Book ' . $t['time'] . ' with ' . $t['people'][0], 'attr') ?>">
                                        <span class="avail-time-when"><?= esc($t['time']) ?></span>
                                        <?php $line = $where . (count($providers) > 1 ? ($where !== '' ? ' · ' : '') . esc($t['people'][0]) : ''); ?>
                                        <?php if ($line !== ''): ?><span class="avail-time-where"><?= $line ?></span><?php endif ?>
                                    </a></li>
                                <?php else: ?>
                                    <li class="avail-time">
                                        <span class="avail-time-when"><?= esc($t['time']) ?></span>
                                        <?php if ($where !== ''): ?><span class="avail-time-where"><?= $where ?></span><?php endif ?>
                                        <span class="avail-time-where">Book with
                                            <?php foreach ($t['provider_ids'] as $i => $pid): ?><?= $i > 0 ? ', ' : '' ?><a class="underline hover:text-brand-ocean" href="<?= esc($book((int) $s['id'], $pid, $t['time']), 'attr') ?>" data-book-service="<?= (int) $s['id'] ?>" data-book-provider="<?= $pid ?>" data-book-date="<?= esc($date, 'attr') ?>" data-book-time="<?= esc($t['time'], 'attr') ?>"><?= esc($t['people'][$i]) ?></a><?php endforeach ?>
                                        </span>
                                    </li>
                                <?php endif ?>
                            <?php endforeach ?>
                        </ul>
                    </section>
                <?php endforeach ?>
            <?php endif ?>
        </div>
    <?php elseif ($view === 'week'): ?>
        <ul class="panel mt-4 divide-y divide-slate-100 dark:divide-slate-800">
            <?php foreach ($days as $d): ?>
                <li class="flex flex-wrap items-center justify-between gap-2 py-2">
                    <a class="font-semibold underline" href="<?= esc($link(['view' => 'day', 'date' => $d['date']]), 'attr') ?>"><?= esc($fmt($d['date'], 'D j M')) ?></a>
                    <span class="text-sm"><?= $d['count'] > 0 ? $d['count'] . ' free, from ' . esc($d['first']) : '<span class="avail-reason avail-' . esc($d['reason']) . '">' . esc($short[$d['reason']]) . '</span>' ?></span>
                </li>
            <?php endforeach ?>
        </ul>
    <?php else: ?>
        <?php // A month, or twelve of them: every day links to its day view. ?>
        <div class="<?= $view === 'year' ? 'avail-year' : 'avail-month-wrap' ?> mt-4">
            <?php for ($m = new DateTimeImmutable($from); $m->format('Y-m-d') <= $to; $m = $m->modify('first day of next month')): ?>
                <section class="panel avail-month">
                    <?php if ($view === 'year'): ?><h3 class="mb-2 subsection-title"><a class="hover:underline" href="<?= esc($link(['view' => 'month', 'date' => $m->format('Y-m-d')]), 'attr') ?>"><?= $m->format('F') ?></a></h3><?php endif ?>
                    <div class="avail-grid">
                        <?php foreach (['M', 'T', 'W', 'T', 'F', 'S', 'S'] as $wd): ?><span class="avail-wd"><?= $wd ?></span><?php endforeach ?>
                        <?php for ($i = 1; $i < (int) $m->format('N'); $i++): ?><span></span><?php endfor ?>
                        <?php for ($d = $m; $d->format('m') === $m->format('m'); $d = $d->modify('+1 day')): ?>
                            <?php $row = $byDate[$d->format('Y-m-d')]; $state = $row['count'] > 0 ? 'free' : $row['reason']; ?>
                            <a class="avail-day avail-<?= esc($state) ?><?= $row['date'] === $today ? ' avail-today' : '' ?>"
                               href="<?= esc($link(['view' => 'day', 'date' => $row['date']]), 'attr') ?>"
                               title="<?= esc($fmt($row['date'], 'D j M') . ': ' . ($row['count'] > 0 ? $row['count'] . ' free, from ' . $row['first'] : $short[$row['reason']]), 'attr') ?>">
                                <span class="avail-day-num"><?= $d->format('j') ?></span>
                                <?php if ($view === 'month'): ?><span class="avail-day-info"><?= $row['count'] > 0 ? $row['count'] . ' free' : esc($short[$row['reason']]) ?></span><?php endif ?>
                            </a>
                        <?php endfor ?>
                    </div>
                </section>
            <?php endfor ?>
        </div>
        <p class="avail-legend">
            <span class="avail-day avail-free">Free times</span>
            <span class="avail-day avail-full">Fully booked</span>
            <span class="avail-day avail-off">Nobody working</span>
            <span class="avail-day avail-closed">Closed</span>
            <span class="avail-day avail-too_far">Not open yet</span>
        </p>
    <?php endif ?>

    <div class="cal-toast" data-avail-toast role="status" aria-live="polite" hidden></div>

    <?php // Book dialog: the same fields as /business/appointments/new, sent as JSON by availability.js. ?>
    <dialog class="cal-dialog book-dialog" data-avail-book aria-labelledby="avail-book-title">
        <form method="post" action="<?= base_url('business/appointments/new') ?>" data-avail-book-form data-free-url="<?= esc(base_url('business/api/appointments/board'), 'attr') ?>" novalidate>
            <?= csrf_field() ?>
            <div class="mb-4 flex items-center gap-3">
                <h2 id="avail-book-title" class="flex-1 text-lg font-bold text-slate-900 dark:text-white">Book an appointment</h2>
                <button type="button" class="rounded-control p-1.5 hover:bg-slate-100 dark:hover:bg-slate-700" data-avail-close aria-label="Close"><?= lucide('x', 'h-5 w-5') ?></button>
            </div>
            <?= view('business/_appointment_fields', ['providers' => $providers, 'services' => $services, 'customFields' => $customFields, 'statuses' => $statuses, 'old' => ['date' => $date], 'errors' => [], 'prefix' => 'av-new', 'wide' => true]) ?>
            <p class="mt-2 hidden text-sm text-rose-600" data-create-error role="alert"></p>
            <div class="book-actions">
                <button type="submit" class="btn btn-primary" data-book-submit>Book</button>
                <button type="button" class="btn btn-ghost" data-avail-close>Cancel</button>
            </div>
        </form>
    </dialog>
<?php endif ?>
</div>
<?= $this->endSection() ?>

<?= $this->section('shell_title') ?>When can customers book?<?= $this->endSection() ?>

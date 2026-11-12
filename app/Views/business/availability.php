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
 */
use App\Services\Bookings\AvailabilityOverview;

$params = ['service_id' => $service, 'provider_id' => $who, 'view' => $view, 'date' => $date] + ($public ? [] : ['as' => 'business']);
$link   = static fn (array $over): string => base_url('business/availability') . '?' . http_build_query($over + $params);
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
                <p class="mb-3 text-sm text-slate-600 dark:text-slate-300"><?= $day['count'] ?> start <?= $day['count'] === 1 ? 'time' : 'times' ?> free.</p>
                <?php foreach ($day['services'] as $s): ?>
                    <section class="panel mb-3">
                        <h3 class="mb-2 subsection-title"><?= esc($s['name']) ?></h3>
                        <ul class="flex flex-wrap gap-2">
                            <?php foreach ($s['times'] as $t): ?>
                                <li class="avail-time" title="<?= esc(implode(', ', $t['people']), 'attr') ?>">
                                    <span class="avail-time-when"><?= esc($t['time']) ?></span>
                                    <span class="avail-time-where"><?= esc(implode(', ', array_filter($t['places']))) ?><?= count($providers) > 1 ? ' · ' . esc(implode(', ', $t['people'])) : '' ?></span>
                                </li>
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
<?php endif ?>
<?= $this->endSection() ?>

<?= $this->section('shell_title') ?>When can customers book?<?= $this->endSection() ?>

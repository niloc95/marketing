<?php
/**
 * The dashboard's figures and today's list. Rendered inside business/home.php
 * and again by /business/api/dashboard/today, which dashboard.js fetches every
 * two minutes while the tab is open (Bookings' dashboard-refresh.js).
 *
 * @var array<string,mixed>       $cards    DashboardService::cards()
 * @var list<array<string,mixed>> $schedule DashboardService::schedule()
 * @var string                    $today    local Y-m-d
 * @var bool                      $canValue may see booked value (analytics.view)
 * @var bool                      $canBook
 */
use App\Services\Bookings\AppointmentStatus;
use App\Services\Bookings\CatalogueService;

$tiles = [
    ['Today', $cards['today'], $cards['next_hours'] . ' in the next 4 hours'],
    ['Next 7 days', $cards['week'], 'today included'],
    ['Waiting for confirmation', $cards['pending'], $cards['pending'] > 0 ? 'confirm or decline them' : 'nothing waiting'],
    ['Customers', $cards['customers'], 'all time'],
];
if ($canValue) {
    $tiles[] = ['Booked this month', CatalogueService::priceText((int) $cards['month_value_cents'], (string) $cards['currency']), (int) $cards['deposits_cents'] > 0
        ? 'from your prices; ' . CatalogueService::priceText((int) $cards['deposits_cents']) . ' paid in deposits'
        : 'from your prices, not money received'];
}
?>
<?php // Literal classes, so Tailwind sees them: four or five tiles a row on a wide screen. ?>
<dl class="stats-tiles biz-tiles <?= count($tiles) === 5 ? 'lg:grid-cols-5' : 'lg:grid-cols-4' ?>">
    <?php foreach ($tiles as [$label, $value, $note]): ?>
        <div class="stats-tile">
            <dt class="stats-tile-label"><?= esc($label) ?></dt>
            <dd class="stats-tile-value"><?= esc(is_int($value) ? number_format($value) : (string) $value) ?></dd>
            <dd class="stats-tile-change"><?= esc($note) ?></dd>
        </div>
    <?php endforeach ?>
</dl>

<div class="panel">
    <div class="dash-panel-head">
        <h2 class="strength-panel-title">Today, <?= esc(date('l j F', (int) strtotime($today))) ?></h2>
        <?php if ($canBook): ?>
            <a class="dash-more" href="<?= base_url('business/calendar?view=day&date=' . $today) ?>">Open the day</a>
        <?php endif ?>
    </div>
    <?php if ($schedule === []): ?>
        <p class="stats-empty">No appointments today.</p>
    <?php else: ?>
        <div class="biz-today">
            <?php foreach ($schedule as $person): ?>
                <section>
                    <h3 class="biz-today-name"><span class="biz-dot" data-color="<?= esc((string) $person['color'], 'attr') ?>"></span><?= esc((string) $person['name']) ?></h3>
                    <ul class="stats-list dash-list">
                        <?php foreach ($person['appointments'] as $a): ?>
                            <li class="biz-when-<?= esc($a['when'], 'attr') ?>">
                                <a class="min-w-0 truncate text-primary-600 hover:underline dark:text-primary-300" href="<?= base_url('business/calendar?view=day&date=' . $today . '&open=' . $a['hash']) ?>">
                                    <span class="tabular-nums"><?= esc($a['time']) ?></span>
                                    <?= esc((string) ($a['customer_name'] ?? 'No customer')) ?>
                                </a>
                                <span class="stats-list-n"><?= esc((string) $a['service_name']) ?>, <?= esc(AppointmentStatus::short((string) $a['status'])) ?><?= $a['when'] === 'now' ? ', now' : '' ?></span>
                            </li>
                        <?php endforeach ?>
                    </ul>
                </section>
            <?php endforeach ?>
        </div>
    <?php endif ?>
</div>

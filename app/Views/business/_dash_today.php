<?php
/**
 * The dashboard's figures and today's appointments. Rendered inside
 * business/home.php and again by /business/api/dashboard/today, which
 * dashboard.js fetches every two minutes while the tab is open (Bookings'
 * dashboard-refresh.js). The wrapper in home.php is display: contents, so
 * these two blocks take their own places in the page's grid.
 *
 * Today is one column per person, each appointment a card that opens in
 * place (business/_dash_appt.php); what has already finished folds under
 * "N earlier today" and anything after the next six under "N more later".
 *
 * @var array<string,mixed>       $cards    DashboardService::cards()
 * @var list<array<string,mixed>> $schedule DashboardService::schedule()
 * @var string                    $today    local Y-m-d
 * @var bool                      $canValue may see booked value (analytics.view)
 * @var bool                      $canBook
 */
use App\Services\Bookings\CatalogueService;

// [icon, label, value, note, link or '', highlight]
$tiles = [
    ['calendar', 'Today', $cards['today'], $cards['next_hours'] . ' in the next 4 hours', $canBook ? 'business/calendar?view=day&date=' . $today : '', false],
    ['calendar-days', 'Next 7 days', $cards['week'], 'today included', $canBook ? 'business/calendar?view=week&date=' . $today : '', false],
    ['calendar-clock', 'Waiting for confirmation', $cards['pending'], $cards['pending'] > 0 ? 'confirm or decline them' : 'nothing waiting', $cards['pending'] > 0 ? '#waiting' : '', $cards['pending'] > 0],
    ['users', 'Customers', $cards['customers'], 'all time', 'business/customers', false],
];
if ($canValue) {
    $tiles[] = ['wallet', 'Booked this month', CatalogueService::priceText((int) $cards['month_value_cents'], (string) $cards['currency']), (int) $cards['deposits_cents'] > 0
        ? 'from your prices; ' . CatalogueService::priceText((int) $cards['deposits_cents']) . ' paid in deposits'
        : 'from your prices, not money received', $canValue ? 'business/analytics' : '', false];
}
?>
<?php // Literal classes, so Tailwind sees them: four or five tiles a row on a wide screen. ?>
<ul class="dash-tiles-row <?= count($tiles) === 5 ? 'xl:grid-cols-5' : 'xl:grid-cols-4' ?>">
    <?php foreach ($tiles as [$icon, $label, $value, $note, $link, $hot]): ?>
        <li>
            <?php $tag = $link !== '' ? 'a' : 'div'; ?>
            <<?= $tag ?> class="panel dash-tile<?= $hot ? ' is-hot' : '' ?>"<?= $link !== '' ? ' href="' . esc($link[0] === '#' ? $link : base_url($link), 'attr') . '"' : '' ?>>
                <span class="dash-tile-icon"><?= lucide($icon, 'h-5 w-5') ?></span>
                <span class="min-w-0">
                    <span class="dash-tile-label"><?= esc($label) ?></span>
                    <span class="dash-tile-value"><?= esc(is_int($value) ? number_format($value) : (string) $value) ?></span>
                    <span class="dash-tile-note"><?= esc($note) ?></span>
                </span>
            </<?= $tag ?>>
        </li>
    <?php endforeach ?>
</ul>

<section class="panel dash-today">
    <div class="dash-panel-head">
        <h2 class="section-title mb-0">Today, <?= esc(date('l j F', (int) strtotime($today))) ?></h2>
        <?php if ($canBook): ?>
            <a class="dash-more" href="<?= base_url('business/calendar?view=day&date=' . $today) ?>">Open the day</a>
        <?php endif ?>
    </div>
    <?php if ($schedule === []): ?>
        <p class="dash-empty"><?= lucide('calendar', 'h-5 w-5') ?> No appointments today.</p>
    <?php else: ?>
        <div class="dash-people">
            <?php foreach ($schedule as $person): ?>
                <?php
                $past = array_values(array_filter($person['appointments'], static fn (array $a): bool => $a['when'] === 'past'));
                $rest = array_values(array_filter($person['appointments'], static fn (array $a): bool => $a['when'] !== 'past'));
                $card = static fn (array $a): string => view('business/_dash_appt', [
                    'a' => $a, 'when' => (string) $a['time'], 'sub' => (string) $a['service_name'], 'canOpen' => $canBook, 'state' => (string) $a['when'], 'compact' => true,
                ], ['saveData' => false]);
                $shown = 6;
                ?>
                <section class="dash-person">
                    <h3 class="dash-person-name">
                        <span class="biz-dot" data-color="<?= esc((string) $person['color'], 'attr') ?>"></span>
                        <span class="min-w-0 flex-1 truncate"><?= esc((string) $person['name']) ?></span>
                        <span class="dash-person-count"><?= count($rest) ?> to go</span>
                    </h3>
                    <?php if ($rest === []): ?>
                        <p class="dash-empty-sm">All done for today.</p>
                    <?php else: ?>
                        <div class="dash-appts"><?= implode('', array_map($card, array_slice($rest, 0, $shown))) ?></div>
                        <?php if (count($rest) > $shown): ?>
                            <details class="dash-earlier">
                                <summary><?= count($rest) - $shown ?> more later</summary>
                                <div class="dash-appts mt-2"><?= implode('', array_map($card, array_slice($rest, $shown))) ?></div>
                            </details>
                        <?php endif ?>
                    <?php endif ?>
                    <?php if ($past !== []): ?>
                        <details class="dash-earlier">
                            <summary><?= count($past) ?> earlier today</summary>
                            <div class="dash-appts mt-2"><?= implode('', array_map($card, $past)) ?></div>
                        </details>
                    <?php endif ?>
                </section>
            <?php endforeach ?>
        </div>
    <?php endif ?>
</section>

<?php
/**
 * The fields of a new appointment, shared by the calendar's "Book" dialog
 * and the plain form (business/appointment_form.php).
 *
 * @var list<array<string,mixed>>                $providers
 * @var list<array<string,mixed>>                $services
 * @var list<array<string,mixed>>                $customFields
 * @var list<array{value:string,label:string}>   $statuses
 * @var array<string,mixed>                      $old
 * @var array<string,string>                     $errors
 * @var string                                   $prefix id prefix, unique per page
 * @var array<string,mixed>|null                 $initialCustomer a customer already chosen (?customer= on the booking page)
 * @var bool                                     $wide   two columns from lg up: when on the left, who on the right (the page and the wide dialogs)
 */
$old    ??= [];
$wide   ??= false;
$errors ??= [];
$val    = static fn (string $k): string => (string) ($old[$k] ?? '');
// Always printed, empty when fine, so the calendar script can fill it in place.
$err    = static fn (string $k): string => '<p class="mt-1 text-sm text-rose-600' . (isset($errors[$k]) ? '' : ' hidden') . '" data-error-for="' . esc($k, 'attr') . '">' . esc($errors[$k] ?? '') . '</p>';
?>
<?php
// The public booking page's steps (owner, 24 Nov 2026): Service as a list
// (length, price, where it is given), With as buttons, then the month beside
// the week of free times. The selects stay the form's fields (and all of it
// without script); free-times.js hides them and drives them from the lists.
$listing  = service('businessContext')->isSet() ? service('businessContext')->listing() : null;
$places   = [];
if ($listing !== null) {
    $boardSvc = new \App\Services\Bookings\FreeTimesBoard($listing, false);
    $inScope  = array_map(static fn (array $p): int => (int) $p['id'], $providers);
    foreach ($services as $s) {
        $places[(int) $s['id']] = $boardSvc->servicePlaces((int) $s['id'], $inScope);
    }
}
$price = static fn (array $s): string => isset($s['price_cents']) && $s['price_cents'] !== null ? money($s['price_cents'] / 100, (string) ($s['currency'] ?? 'ZAR')) : '';
$many  = static fn (array $list): bool => count($list) > 1;
?>
<div class="book-layout<?= $wide ? ' book-layout-wide' : '' ?>">
<div class="book-form-when">
<section class="book-step" data-step="service">
    <h3 class="book-step-title">Service</h3>
    <div class="field" data-step-select>
        <label for="<?= $prefix ?>-service">Service</label>
        <select id="<?= $prefix ?>-service" name="service_id" required>
            <?php if ($many($services)): ?><option value="">Choose a service</option><?php endif ?>
            <?php foreach ($services as $s): ?>
                <option value="<?= (int) $s['id'] ?>" data-name="<?= esc((string) $s['name'], 'attr') ?>" data-duration="<?= (int) $s['duration_min'] ?>"<?= $val('service_id') === (string) $s['id'] ? ' selected' : '' ?>><?= esc((string) $s['name']) ?> (<?= (int) $s['duration_min'] ?> min)</option>
            <?php endforeach ?>
        </select>
    </div>
    <p class="book-step-chosen" data-step-chosen hidden>
        <span data-step-chosen-text></span>
        <?php if ($many($services)): ?><button type="button" class="text-sm underline" data-step-change>Change</button><?php endif ?>
    </p>
    <ul class="book-step-list divide-y divide-slate-100 dark:divide-slate-800" data-step-list hidden>
        <?php foreach ($services as $s): ?>
            <?php $meta = (int) $s['duration_min'] . ' min' . ($price($s) !== '' ? ', ' . $price($s) : ''); ?>
            <li>
                <button type="button" class="book-service-row" data-pick="<?= (int) $s['id'] ?>" data-label="<?= esc((string) $s['name'] . ', ' . $meta, 'attr') ?>">
                    <span class="min-w-0 flex-1 text-left">
                        <span class="block font-semibold"><?= esc((string) $s['name']) ?></span>
                        <?php if (trim((string) ($s['description'] ?? '')) !== ''): ?><span class="book-service-desc"><?= esc((string) $s['description']) ?></span><?php endif ?>
                        <?php if (($places[(int) $s['id']] ?? []) !== []): ?>
                            <span class="book-service-places">
                                <?php foreach ($places[(int) $s['id']] as $pl): ?>
                                    <span class="book-service-place"><?= lucide('map-pin', 'mt-0.5 h-3.5 w-3.5 shrink-0') ?><span><?= esc($pl['name']) ?></span></span>
                                <?php endforeach ?>
                            </span>
                        <?php endif ?>
                    </span>
                    <span class="text-right text-sm text-slate-500"><?= (int) $s['duration_min'] ?> min<?= $price($s) !== '' ? '<br>' . esc($price($s)) : '' ?></span>
                </button>
            </li>
        <?php endforeach ?>
    </ul>
    <?= $err('service_id') ?>
</section>

<section class="book-step" data-step="provider">
    <h3 class="book-step-title">With</h3>
    <div class="field" data-step-select>
        <label for="<?= $prefix ?>-provider">With</label>
        <select id="<?= $prefix ?>-provider" name="provider_id" required>
            <?php if ($many($providers)): ?><option value="">Choose who</option><?php endif ?>
            <?php foreach ($providers as $p): ?>
                <option value="<?= (int) $p['id'] ?>"<?= $val('provider_id') === (string) $p['id'] ? ' selected' : '' ?>><?= esc((string) $p['name']) ?></option>
            <?php endforeach ?>
        </select>
    </div>
    <p class="book-step-chosen" data-step-chosen hidden>
        <span data-step-chosen-text></span>
        <?php if ($many($providers)): ?><button type="button" class="text-sm underline" data-step-change>Change</button><?php endif ?>
    </p>
    <div class="flex flex-wrap gap-2" data-step-list hidden>
        <?php foreach ($providers as $p): ?>
            <?php $offers = array_values(array_map(static fn (array $s): int => (int) $s['id'], array_filter($services, static fn (array $s): bool => in_array((int) $p['id'], array_map('intval', (array) ($s['provider_ids'] ?? [])), true)))); ?>
            <button type="button" class="btn btn-ghost book-who" data-pick="<?= (int) $p['id'] ?>" data-label="<?= esc((string) $p['name'], 'attr') ?>" data-services="<?= implode(',', $offers) ?>">
                <span class="text-left"><?= esc((string) $p['name']) ?><?php if (trim((string) ($p['title'] ?? '')) !== ''): ?><span class="block text-xs font-normal text-slate-500 dark:text-slate-400"><?= esc((string) $p['title']) ?></span><?php endif ?></span>
            </button>
        <?php endforeach ?>
        <p class="text-sm text-slate-500" data-step-none hidden>Nobody offers this service yet.</p>
    </div>
    <?= $err('provider_id') ?>
</section>

<section class="book-step" data-step="time">
<h3 class="book-step-title">Choose a time</h3>
<?php // Filled by free-times.js: the month (book/_month.php) beside a week of free times, one column per place (book/_board.php, T33). A click fills Date and Time and folds them to one line (Change time opens them). Hidden without the script. ?>
<div class="free-times mb-4" data-free hidden>
    <div class="free-times-summary" data-free-summary hidden>
        <?= lucide('clock', 'h-5 w-5 shrink-0') ?>
        <span class="free-times-chosen" data-free-chosen></span>
        <button type="button" class="btn btn-ghost btn-xs ml-auto" data-free-change>Change time</button>
    </div>
    <div data-free-lists>
        <div class="book-pick">
            <div data-free-cal></div>
            <div class="min-w-0" data-free-board><p class="free-times-empty">Finding free times…</p></div>
        </div>
    </div>
</div>
<div class="grid gap-4 sm:grid-cols-2">
    <div class="field">
        <label for="<?= $prefix ?>-date">Date</label>
        <input id="<?= $prefix ?>-date" type="date" name="date" value="<?= esc($val('date'), 'attr') ?>" required>
    </div>
    <div class="field">
        <label for="<?= $prefix ?>-time">Time <span class="font-normal text-slate-500">(any free minute)</span></label>
        <input id="<?= $prefix ?>-time" type="time" name="time" step="300" value="<?= esc($val('time'), 'attr') ?>" required>
        <?= $err('time') ?>
    </div>
    <?php if (array_filter($services, static fn (array $s): bool => count((array) $s['delivery_modes']) > 1 || (array) $s['delivery_modes'] !== ['onsite'])): ?>
        <div class="field sm:col-span-2">
            <label for="<?= $prefix ?>-mode">How</label>
            <select id="<?= $prefix ?>-mode" name="delivery_mode">
                <option value="">As the service is usually given</option>
                <?php foreach (\App\Services\Bookings\CatalogueService::DELIVERY_MODES as $mode => $label): ?>
                    <option value="<?= $mode ?>"<?= $val('delivery_mode') === $mode ? ' selected' : '' ?>><?= esc($label) ?></option>
                <?php endforeach ?>
            </select>
            <?= $err('delivery_mode') ?>
        </div>
    <?php endif ?>
</div>
</section>
</div>

<div class="book-form-who">
<?php // Filled by free-times.js as the fields change: what is being booked, in one place. Hidden without the script. ?>
<?= view('book/_summary', [
    'rows'   => array_map(static fn (array $r): array => ['key' => $r[0], 'label' => $r[1]], [['service', 'Service'], ['who', 'With'], ['date', 'Date'], ['time', 'Time'], ['when', 'When'], ['where', 'Where']]),
    'attrs'  => 'data-book-summary data-timezone="' . esc(service('businessContext')->isSet() ? service('businessContext')->timezone() : app_timezone(), 'attr') . '"',
    'hidden' => true,
], ['saveData' => false]) ?>

<fieldset class="mt-2" data-customer-picker<?php if (! empty($initialCustomer)): ?> data-customer-initial="<?= esc(json_encode($initialCustomer), 'attr') ?>"<?php endif ?>>
    <legend class="book-step-title">Customer</legend>
    <input type="hidden" name="customer_id" value="<?= esc($val('customer_id'), 'attr') ?>" data-customer-id>
    <div class="field mt-2" data-customer-find hidden>
        <label for="<?= $prefix ?>-find">Find a customer</label>
        <input id="<?= $prefix ?>-find" type="text" autocomplete="off" placeholder="Name, email, phone or reference" data-customer-query>
        <ul class="mt-1 divide-y divide-slate-100 rounded-inner border border-slate-200 bg-white text-sm dark:divide-slate-700 dark:border-slate-700 dark:bg-slate-800" data-customer-results hidden></ul>
        <p class="mt-1 hidden text-sm" data-customer-chosen></p>
    </div>
    <?= $err('customer_id') ?>
    <div class="grid gap-4 sm:grid-cols-2" data-customer-new>
        <div class="field">
            <label for="<?= $prefix ?>-first">First name</label>
            <input id="<?= $prefix ?>-first" type="text" name="first_name" maxlength="80" value="<?= esc($val('first_name'), 'attr') ?>" autocomplete="off">
            <?= $err('first_name') ?>
        </div>
        <div class="field">
            <label for="<?= $prefix ?>-last">Surname</label>
            <input id="<?= $prefix ?>-last" type="text" name="last_name" maxlength="80" value="<?= esc($val('last_name'), 'attr') ?>" autocomplete="off">
            <?= $err('last_name') ?>
        </div>
        <div class="field">
            <label for="<?= $prefix ?>-email">Email</label>
            <input id="<?= $prefix ?>-email" type="email" name="email" maxlength="190" value="<?= esc($val('email'), 'attr') ?>" autocomplete="off">
            <?= $err('email') ?>
        </div>
        <div class="field">
            <label for="<?= $prefix ?>-phone">Phone</label>
            <input id="<?= $prefix ?>-phone" type="tel" name="phone" maxlength="32" value="<?= esc($val('phone'), 'attr') ?>" autocomplete="off">
            <?= $err('phone') ?>
        </div>
    </div>
</fieldset>

<?php foreach ($customFields as $f): ?>
    <?= view('business/_question', ['f' => $f, 'id' => $prefix . '-' . esc($f['key'], 'attr'), 'value' => $val($f['key']), 'error' => $err($f['key']), 'strict' => true]) ?>
<?php endforeach ?>

<div class="grid gap-4 sm:grid-cols-2">
    <div class="field">
        <label for="<?= $prefix ?>-status">Status</label>
        <select id="<?= $prefix ?>-status" name="status">
            <?php foreach ($statuses as $s): ?>
                <?php if (in_array($s['value'], ['pending', 'confirmed'], true)): ?>
                    <option value="<?= esc($s['value'], 'attr') ?>"<?= ($val('status') ?: 'confirmed') === $s['value'] ? ' selected' : '' ?>><?= esc($s['label']) ?></option>
                <?php endif ?>
            <?php endforeach ?>
        </select>
    </div>
    <div class="field">
        <label for="<?= $prefix ?>-notes">Notes <span class="font-normal text-slate-500">(only your team sees them)</span></label>
        <textarea id="<?= $prefix ?>-notes" name="notes" rows="2" maxlength="5000"><?= esc($val('notes')) ?></textarea>
    </div>
</div>
</div>
</div>
